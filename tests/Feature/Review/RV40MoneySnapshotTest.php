<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-40 — the booking must remember what it paid, immutably.
 *
 * Root cause: `bookings` stored NO money. Every settlement/refund recomputed
 * `seats * ride.price_per_seat` at the moment it ran (16 sites). `price_per_seat` is
 * mutable and `seats` changes on a partial cancel, so "what a booking paid" was never a
 * fact — the ledger and the booking could disagree with nothing to arbitrate them.
 *
 * These tests pin the FIX, not the symptom, and prove the one property RV-40 exists for:
 * the snapshot is captured at charge time and does NOT move when the ride price later
 * changes. That is precisely the divergence that was silently changing settled amounts.
 *
 * Requires MySQL: chargePassengerForBooking uses lockForUpdate on wallets (no-op on
 * sqlite's single-writer model) and the money services assert real balances.
 */
class RV40MoneySnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Wallet $syCash;

    private User $passenger;

    protected function setUp(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: wallet lockForUpdate + balance asserts.');
        }
        parent::setUp();

        // The two system wallets chargePassengerForBooking touches (it locks SyCash).
        $this->syCash = Wallet::create([
            'user_id' => null,
            'phone_number' => config('admin.sycash.phone'),
            'balance' => 0,
        ]);
        Wallet::create([
            'user_id' => null,
            'phone_number' => config('admin.system_admin.phone'),
            'balance' => 0,
        ]);

        $this->passenger = User::factory()->create(['is_verified_passenger' => true]);
        $this->passenger->profile()->create(['full_name' => 'P', 'number_of_rides' => 0]);
        $pw = Wallet::create([
            'user_id' => $this->passenger->id,
            'phone_number' => '0922'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 10_000_000,
        ]);
        $this->passenger->update(['wallet_id' => $pw->id]);
    }

    private function chargedBooking(float $pricePerSeat, int $seats): Booking
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);

        $ride = RideBuilder::for($driver)->price($pricePerSeat)->create();

        $booking = Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $ride->id,
            'seats' => $seats,
            'status' => Booking::CONFIRMED,
            'communication_number' => '0912345678',
        ]);

        app(WalletTransactionService::class)->chargePassengerForBooking(
            $booking,
            $ride->fresh(),
            $this->passenger->fresh()
        );

        return $booking->fresh();
    }

    /** @test */
    public function charging_snapshots_the_money_fields_onto_the_booking(): void
    {
        $booking = $this->chargedBooking(100_000, 3);

        // unit_price, amount_paid = seats x price, and the payment method are all
        // recorded at the moment money actually moves.
        $this->assertSame('100000.00', (string) $booking->unit_price);
        $this->assertSame('300000.00', (string) $booking->amount_paid);
        $this->assertSame('e-pay', $booking->payment_method);

        // And the passenger really paid it: SyCash holds exactly the amount.
        $this->assertSame('300000.00', (string) $this->syCash->fresh()->balance);
    }

    /** @test */
    public function the_snapshot_does_not_move_when_the_ride_price_changes_later(): void
    {
        // This is the divergence RV-40 removes.
        $booking = $this->chargedBooking(100_000, 3);
        $paidAtCharge = $booking->amount_paid;

        // The ride's mutable price is edited AFTER the booking was paid.
        $booking->ride->update(['price_per_seat' => 1]);

        $this->assertSame(
            '1.00',
            (string) $booking->ride->fresh()->price_per_seat,
            'precondition: the ride price really changed'
        );

        // The booking must STILL remember what it actually paid — recomputing
        // seats * price_per_seat here would now yield 3.00, silently rewriting a
        // settled amount by three orders of magnitude.
        $this->assertSame(
            (string) $paidAtCharge,
            (string) $booking->fresh()->amount_paid,
            'RV-40: amount_paid must be immutable after charge'
        );
        $this->assertSame('300000.00', (string) $booking->fresh()->amount_paid);
    }

    /** @test */
    public function the_payment_method_snapshot_survives_the_ride_switching_to_cash(): void
    {
        $booking = $this->chargedBooking(50_000, 1);
        $this->assertSame('e-pay', $booking->payment_method);

        // A ride flipping its method must not re-decide how an already-escrowed booking
        // is settled — the snapshot, not the live ride, governs refunds.
        $booking->ride->update(['payment_method' => 'cash']);

        $this->assertSame(
            'e-pay',
            $booking->fresh()->payment_method,
            'RV-40: payment_method is a charge-time snapshot, not the live ride value'
        );
    }
}
