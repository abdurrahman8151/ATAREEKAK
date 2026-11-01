<?php

namespace Tests\Feature\Payment;

use App\Models\Booking;
use App\Models\LedgerEntry;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\BookingCharge;
use App\Services\Payment\CancellationRefund;
use App\Services\Payment\NoShowSettlement;
use App\Services\Payment\RidePayout;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\AssertsLedgerInvariants;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\LedgerPropertySeed;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * The same four invariants as `LedgerInvariantsPropertyTest`, over 220 SEEDED RANDOM scenarios
 * instead of three hand-picked amounts (stage 0.5, step 6).
 *
 * The fixed test answers "does the ledger balance for 50,000, 1,000.50 and 33.33?". This one asks
 * the question the fixtures cannot: does it balance for an arbitrary amount in minor units, an
 * arbitrary seat count, and a ride carrying more than one booking - including the one-fils
 * boundaries (`0.01`, `1.01`) and the odd tenths that produced the RV-19 split overflow.
 *
 * REPRODUCIBILITY IS THE FEATURE
 *
 * The scenario set comes from ONE seed for the whole run. The seed is printed to STDERR before the
 * first case and carried in every assertion message, so a red run is replayed exactly with:
 *
 *     $env:LEDGER_SEED=<printed seed>; php vendor/bin/phpunit tests/Feature/Payment
 *
 * Without `LEDGER_SEED` the seed is random per run on purpose: a fixed default would slowly become
 * just another fixture nobody re-examines.
 */
final class LedgerRandomizedPropertyTest extends TestCase
{
    use AssertsLedgerInvariants;
    use RefreshDatabase;
    use SeedsSystemWallets;

    private User $driver;

    private User $passenger;

    private Wallet $driverWallet;

    private Wallet $passengerWallet;

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function scenarios(): array
    {
        $cases = [];

        foreach (LedgerPropertySeed::scenarios() as $label => $case) {
            $cases[$label] = [$case];
        }

        return $cases;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSystemWallets(10_000_000.0);

        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $this->driverWallet = $this->walletFor($this->driver, '091', 0);
        $this->driver->update(['wallet_id' => $this->driverWallet->id]);

        $this->passenger = User::factory()->create(['is_verified_passenger' => true]);
        $this->passengerWallet = $this->walletFor($this->passenger, '092', 20_000_000);
        $this->passenger->update(['wallet_id' => $this->passengerWallet->id]);
    }

    private function walletFor(User $user, string $prefix, float $balance): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'phone_number' => $prefix.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => $balance,
        ]);
    }

    /** One active e-pay ride, with the first booking already charged into escrow. */
    private function chargedBooking(float $price, int $seats): Booking
    {
        $this->ride = RideBuilder::for($this->driver)
            ->withAttributes([
                'price_per_seat' => $price,
                'payment_method' => 'e-pay',
                'booking_type' => 'direct',
                'status' => 'active',
                'departure_time' => now()->addHours(3),
            ])
            ->create();

        return $this->charge($seats);
    }

    private function charge(int $seats): Booking
    {
        $booking = Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $this->ride->id,
            'seats' => $seats,
            'status' => 'confirmed',
            'communication_number' => '0900000000',
        ]);

        app(BookingCharge::class)->chargePassengerForBooking($booking, $this->ride->fresh(), $this->passenger->fresh());

        return $booking->fresh();
    }

    #[DataProvider('scenarios')]
    public function test_a_randomized_movement_keeps_every_ledger_invariant(array $case): void
    {
        $price = (float) $case['price'];
        $seats = (int) $case['seats'];
        $movement = (string) $case['movement'];
        $context = LedgerPropertySeed::describe($case, $movement);

        $booking = $this->chargedBooking($price, $seats);

        $this->assertSame(
            round($price * $seats, 2),
            round((float) $booking->escrow_held, 2),
            "{$context}: the charge must park exactly price x seats in escrow."
        );

        $this->assertSame(
            round(20_000_000 - $price * $seats, 2),
            round((float) $this->passengerWallet->fresh()->balance, 2),
            "{$context}: the passenger must pay exactly the charge, no rounding drift."
        );

        if ($case['second_booking'] ?? false) {
            $this->charge((int) $case['second_seats']);
        }

        $bookings = Booking::query()->orderBy('id')->get();
        $moneyBefore = $this->moneyInWallets();

        match ($movement) {
            'charge' => null,
            'payout' => app(RidePayout::class)->releaseEscrowToDriver($booking, $this->ride->fresh(), $this->driver->fresh()),
            'refund' => app(CancellationRefund::class)->refundPassengersForDriverCancellation(
                $this->ride->fresh(),
                new Collection($bookings->all())
            ),
            'no_show' => app(NoShowSettlement::class)->processPassengerNoShow($booking, $this->ride->fresh(), $this->passenger->fresh()),
            default => $this->fail("{$context}: unknown movement."),
        };

        $this->assertInvariantsHold($moneyBefore, $context);

        if ($movement === 'refund') {
            // A refund settles the passenger collection it is handed, so every booking must be empty.
            foreach ($bookings as $charged) {
                $this->assertEscrowIsEmpty($charged, $context);
            }
        } elseif ($movement !== 'charge') {
            // Payout and no-show settle ONE booking. The first run of this test asserted they emptied
            // the whole ride and 22 cases said otherwise - correctly: the other passenger's escrow
            // must survive, and that is worth pinning rather than working around.
            $this->assertEscrowIsEmpty($booking, $context);

            if ($bookings->count() > 1) {
                $other = $bookings->first(fn (Booking $candidate): bool => $candidate->id !== $booking->id);

                $this->assertGreaterThan(
                    0.0,
                    round((float) $other->fresh()->escrow_held, 2),
                    "{$context}: a per-booking movement must NOT release another booking's escrow."
                );
            }
        }

        $replay = match ($movement) {
            'charge' => fn () => app(BookingCharge::class)->chargePassengerForBooking($booking, $this->ride->fresh(), $this->passenger->fresh()),
            'payout' => fn () => app(RidePayout::class)->releaseEscrowToDriver($booking, $this->ride->fresh(), $this->driver->fresh()),
            'refund' => fn () => app(CancellationRefund::class)->refundPassengersForDriverCancellation(
                $this->ride->fresh(),
                new Collection(Booking::query()->orderBy('id')->get()->all())
            ),
            'no_show' => fn () => app(NoShowSettlement::class)->processPassengerNoShow($booking, $this->ride->fresh(), $this->passenger->fresh()),
            default => null,
        };

        $this->assertReplayChangesNothing($replay, $context);

        // The driver's wallet is the counterparty for payout and no-show: after them it must hold
        // the money the passenger paid, or the movement has destroyed it somewhere the total
        // (which includes system wallets) would hide.
        if (in_array($movement, ['payout', 'no_show'], true)) {
            $this->assertGreaterThan(
                0.0,
                round((float) $this->driverWallet->fresh()->balance, 2),
                "{$context}: the driver must have been credited."
            );
        }
    }

    /**
     * The properties are only worth running if they can FAIL. Each case below breaks its invariant
     * on purpose and proves the assertion notices; without these, a typo that made an assertion
     * vacuous would leave a green suite that checks nothing.
     */
    public function test_the_ledger_balance_property_fails_when_a_leg_is_destroyed(): void
    {
        $booking = $this->chargedBooking(500, 1);
        $moneyBefore = $this->moneyInWallets();

        LedgerEntry::query()->orderBy('id')->limit(1)->delete();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('must sum to zero');

        $this->assertInvariantsHold($moneyBefore, 'deliberate leg removal');
    }

    public function test_the_conservation_property_fails_when_a_balance_moves_alone(): void
    {
        $booking = $this->chargedBooking(500, 1);
        $moneyBefore = $this->moneyInWallets();

        // The ledger still balances (no leg was touched); only the wallet total moves. This is the
        // "money appeared" case the sum-of-legs check cannot see.
        $this->passengerWallet->update(['balance' => (float) $this->passengerWallet->fresh()->balance + 100]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('money must be conserved');

        $this->assertInvariantsHold($moneyBefore, 'deliberate free money');
    }

    public function test_the_escrow_property_fails_when_escrow_goes_negative(): void
    {
        $booking = $this->chargedBooking(500, 1);
        $moneyBefore = $this->moneyInWallets();

        Booking::query()->whereKey($booking->id)->update(['escrow_held' => -1]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('escrow_held must never be negative');

        $this->assertInvariantsHold($moneyBefore, 'deliberate negative escrow');
    }

    public function test_the_replay_property_fails_when_the_second_call_moves_money(): void
    {
        $booking = $this->chargedBooking(500, 1);

        // A DIFFERENT movement (another seat) has its own posting key, so nothing refuses it - the
        // replay guard must report that the "replay" wrote rows rather than passing silently.
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('must be refused by its posting key');

        $this->assertReplayChangesNothing(
            fn () => $this->charge(1),
            'deliberate replay that is not a replay'
        );
    }
}
