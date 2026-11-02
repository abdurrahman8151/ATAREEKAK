<?php

namespace Tests\Feature\Payment;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Modules\Money\Services\BookingCharge;
use App\Modules\Money\Services\CancellationRefund;
use App\Modules\Money\Services\NoShowSettlement;
use App\Modules\Money\Services\RidePayout;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\AssertsLedgerInvariants;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * The four money movements, checked as PROPERTIES rather than as one expected row list each.
 *
 * The existing ledger tests (L19/L20/L21, RV-20) pin the exact rows of one scenario. This file asks
 * the questions that must hold for EVERY scenario, including amounts the fixtures never used:
 *
 *   1. DEBITS EQUAL CREDITS. The signed legs of every transfer sum to zero - `LedgerService`
 *      refuses to write anything else, so a non-zero sum here means a leg was written outside it.
 *   2. MONEY IS CONSERVED. The sum of every wallet balance in the database is unchanged by a
 *      movement: charging moves money into SyCash, payout and refunds move it out, and neither
 *      creates or destroys a fils.
 *   3. ESCROW IS NEVER NEGATIVE. `bookings.escrow_held` is a snapshot of what a booking has in
 *      escrow; it must never dip below zero, and it must return to exactly zero when escrow is
 *      released (payout, refund, no-show).
 *   4. A POSTING KEY MAKES THE MOVEMENT IDEMPOTENT. Replaying the same call raises and moves
 *      NOTHING - not a balance, not a transaction row, not an escrow snapshot. Every assertion runs
 *      after the exception (try/catch rather than expectException) so "nothing moved" is provable.
 *
 * The amounts deliberately include an odd tenth (1000.50), the case that made the 95/5 split sum to
 * one fils more than the escrow debit (RV-19) and the reason the balance check lives in minor units.
 */
final class LedgerInvariantsPropertyTest extends TestCase
{
    use AssertsLedgerInvariants;
    use RefreshDatabase;
    use SeedsSystemWallets;

    /** @return array<string, array{0: float}> */
    public static function amounts(): array
    {
        return [
            'a round fifty thousand' => [50_000.0],
            'an odd tenth (RV-19)' => [1_000.50],
            'a third of a hundred' => [33.33],
        ];
    }

    private User $driver;

    private User $passenger;

    private Wallet $driverWallet;

    private Wallet $passengerWallet;

    private Ride $ride;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSystemWallets(10_000_000.0);

        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $this->driverWallet = $this->walletFor($this->driver, '091', 0);
        $this->driver->update(['wallet_id' => $this->driverWallet->id]);

        $this->passenger = User::factory()->create(['is_verified_passenger' => true]);
        $this->passengerWallet = $this->walletFor($this->passenger, '092', 1_000_000);
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

    /** One active e-pay ride plus one confirmed booking on it, charged into escrow. */
    private function chargedBooking(float $price, int $seats = 2): Booking
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

    /** 1 + 2 + 3: the ledger balances, money is conserved, escrow is a non-negative snapshot.
     *
     * The assertions themselves live in `AssertsLedgerInvariants`, shared with the randomized
     * property test, so the two files cannot drift on what "the invariants" are. */
    #[DataProvider('amounts')]
    public function test_charging_a_booking_balances_the_ledger_and_parks_the_charge_in_escrow(float $price): void
    {
        $moneyBefore = (float) Wallet::query()->sum('balance');

        $booking = $this->chargedBooking($price, 2);
        $charged = round($price * 2, 2);

        $this->assertInvariantsHold($moneyBefore, 'charge');

        $this->assertSame($charged, round((float) $booking->escrow_held, 2), 'the booking carries the whole charge in escrow');
        $this->assertSame(
            round((float) $this->passengerWallet->fresh()->balance, 2),
            round(1_000_000 - $charged, 2),
            'the passenger paid exactly the charge'
        );

        $this->assertReplayChangesNothing(
            fn () => app(BookingCharge::class)->chargePassengerForBooking($booking, $this->ride->fresh(), $this->passenger->fresh()),
            'charge'
        );
    }

    #[DataProvider('amounts')]
    public function test_payout_balances_the_ledger_and_releases_every_fils_of_escrow(float $price): void
    {
        $booking = $this->chargedBooking($price, 2);
        $moneyBefore = (float) Wallet::query()->sum('balance');

        app(RidePayout::class)->releaseEscrowToDriver($booking, $this->ride->fresh(), $this->driver->fresh());

        $this->assertInvariantsHold($moneyBefore, 'payout');
        $this->assertSame(0.0, round((float) $booking->fresh()->escrow_held, 2), 'payout must empty the escrow snapshot');

        $this->assertReplayChangesNothing(
            fn () => app(RidePayout::class)->releaseEscrowToDriver($booking, $this->ride->fresh(), $this->driver->fresh()),
            'payout'
        );
    }

    #[DataProvider('amounts')]
    public function test_a_driver_cancellation_refund_balances_the_ledger_and_empties_escrow(float $price): void
    {
        $booking = $this->chargedBooking($price, 2);
        $moneyBefore = (float) Wallet::query()->sum('balance');

        app(CancellationRefund::class)->refundPassengersForDriverCancellation(
            $this->ride->fresh(),
            new Collection([$booking])
        );

        $this->assertInvariantsHold($moneyBefore, 'driver cancellation');
        $this->assertSame(0.0, round((float) $booking->fresh()->escrow_held, 2), 'a refund must empty the escrow snapshot');

        $this->assertReplayChangesNothing(
            fn () => app(CancellationRefund::class)->refundPassengersForDriverCancellation(
                $this->ride->fresh(),
                new Collection([$booking])
            ),
            'driver cancellation'
        );
    }

    #[DataProvider('amounts')]
    public function test_a_passenger_no_show_balances_the_ledger_and_empties_escrow(float $price): void
    {
        $booking = $this->chargedBooking($price, 2);
        $moneyBefore = (float) Wallet::query()->sum('balance');

        app(NoShowSettlement::class)->processPassengerNoShow($booking, $this->ride->fresh(), $this->passenger->fresh());

        $this->assertInvariantsHold($moneyBefore, 'passenger no-show');
        $this->assertSame(0.0, round((float) $booking->fresh()->escrow_held, 2), 'a no-show settlement must empty the escrow snapshot');

        $this->assertReplayChangesNothing(
            fn () => app(NoShowSettlement::class)->processPassengerNoShow($booking, $this->ride->fresh(), $this->passenger->fresh()),
            'passenger no-show'
        );
    }
}
