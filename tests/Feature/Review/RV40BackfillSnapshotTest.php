<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-40 — the backfill must trust the LEDGER, not the mutable ride.
 *
 * The trap this guards against: a backfill that computed unit_price/amount_paid from
 * `seats * ride.price_per_seat` would "fix" the schema while committing the exact bug it
 * removes — stamping TODAY's price onto a booking paid at a different price, then calling
 * it immutable. The only honest source is the escrow ledger row (what actually moved).
 *
 * So these tests set up a booking whose LIVE ride price disagrees with what the ledger
 * says was paid, and assert the command fills from the ledger. If it recomputed from the
 * ride, the assertions would catch it — which is the whole point.
 */
class RV40BackfillSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Wallet $syCash;

    protected function setUp(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: money ledger + lockForUpdate semantics.');
        }
        parent::setUp();

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
    }

    private function unfilledEpayBooking(float $livePrice, float $ledgerAmount, int $seats): Booking
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);

        // Ride price is deliberately NOT equal to what was paid, to expose a
        // ledger-vs-recompute bug: correct backfill uses $ledgerAmount.
        $ride = RideBuilder::for($driver)->price($livePrice)->create();

        $booking = Booking::create([
            'user_id' => User::factory()->create()->id,
            'ride_id' => $ride->id,
            'seats' => $seats,
            'status' => Booking::CONFIRMED,
            'communication_number' => '0912345678',
            // amount_paid defaults to 0 => "unfilled", exactly a pre-RV-40 row.
        ]);

        WalletTransaction::create([
            'wallet_id' => $this->syCash->id,
            'user_id' => null,
            'type' => 'escrow_received',
            'amount' => $ledgerAmount,
            'previous_balance' => 0,
            'new_balance' => $ledgerAmount,
            'description' => 'historical escrow',
            'transaction_id' => 'BF_'.$booking->id,
            'status' => 'completed',
            'reference' => "booking:{$booking->id}",
        ]);

        return $booking;
    }

    /** @test */
    public function the_backfill_uses_the_ledger_amount_not_the_mutable_ride_price(): void
    {
        // Live ride price 1 (would recompute to seats*1 = 1), but ledger says 300000 was paid.
        $booking = $this->unfilledEpayBooking(1.0, 300_000.0, 3);

        $this->artisan('bookings:backfill-money-snapshot')->assertExitCode(0);

        $booking->refresh();

        // The truth is the ledger, NOT seats * price_per_seat (= 3.00 if recomputed).
        $this->assertSame('300000.00', (string) $booking->amount_paid);
        $this->assertSame('100000.00', (string) $booking->unit_price, 'unit = 300000 / 3 seats');
        $this->assertSame('e-pay', $booking->payment_method);

        // A naive `seats * ride.price_per_seat` backfill would have written 3.00 here.
        $this->assertNotSame('3.00', (string) $booking->amount_paid);
    }

    /** @test */
    public function the_backfill_is_idempotent(): void
    {
        $booking = $this->unfilledEpayBooking(1.0, 250_000.0, 5);

        $this->artisan('bookings:backfill-money-snapshot')->assertExitCode(0);
        $firstAmount = $booking->fresh()->amount_paid;

        // Re-running must not double-fill or touch the already-filled booking.
        $this->artisan('bookings:backfill-money-snapshot')->assertExitCode(0);

        $this->assertSame(
            (string) $firstAmount,
            (string) $booking->fresh()->amount_paid,
            'RV-40 backfill must be safe to re-run'
        );
        $this->assertSame('250000.00', (string) $booking->fresh()->amount_paid);
    }

    /** @test */
    public function an_e_pay_booking_with_no_ledger_row_is_not_fabricated(): void
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);
        $ride = RideBuilder::for($driver)->price(50_000)->create();
        $booking = Booking::create([
            'user_id' => User::factory()->create()->id,
            'ride_id' => $ride->id,
            'seats' => 2,
            'status' => Booking::CONFIRMED,
            'communication_number' => '0912345678',
        ]);
        // NO escrow ledger row.

        $this->artisan('bookings:backfill-money-snapshot')->assertExitCode(0);

        // The command must NOT invent an amount from the ride. amount_paid stays 0.
        $this->assertSame(
            '0.00',
            (string) $booking->fresh()->amount_paid,
            'no ledger row => no fabricated amount (that is the RV-40 sin)'
        );
    }

    /** @test */
    public function dry_run_changes_nothing(): void
    {
        $booking = $this->unfilledEpayBooking(1.0, 120_000.0, 2);

        $this->artisan('bookings:backfill-money-snapshot', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(
            '0.00',
            (string) $booking->fresh()->amount_paid,
            'dry-run must not write'
        );
    }
}
