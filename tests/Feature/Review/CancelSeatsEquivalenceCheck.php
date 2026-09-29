<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\WalletTransactionService;
use App\Services\Ride\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V16 (Review R2) — RECORDING test: does cancelling EVERY seat through
 * `POST /bookings/{id}/cancel-seats` produce the same money and the same score
 * as `cancelBooking()` at the same elapsed percentage?
 *
 * A CHECK, not a fix; its result decides RV-02 (L1) and RV-04 (L2).
 *
 * Two payment branches are measured, because they take different code paths
 * (proved by reading the services):
 *   - e-pay: refund/escrow settlement rows are written; the SCORE path is a
 *     documented no-op (`ScoreService::recordPassengerCancel` returns early
 *     unless payment_method === 'cash'), so score must be 0.0 on BOTH paths.
 *   - cash: the refund is zero and the score penalty is the observable effect
 *     (PassengerCancelPolicy: -5 mid / -10 late), so score must be equal and
 *     negative on BOTH paths.
 *
 * Money fixture: one escrowed single-seat booking created 60 min ago for a ride
 * departing in 20 min => ~75% elapsed (the 70-100 tier, refund_percentage 0).
 *
 * If an assertion here ever fails, the two cancellation paths have diverged:
 * update the header with the exact difference and escalate RV-02 to P0. The
 * per-branch expected values below are the RECORDED truth.
 */
class CancelSeatsEquivalenceCheck extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('MySQL required: SPATIAL columns + decimal + lockForUpdate.');
        }
        parent::setUp();

        Wallet::create(['user_id' => null, 'phone_number' => '0000000000', 'balance' => 0]);
        Wallet::create(['user_id' => null, 'phone_number' => config('admin.sycash.phone'), 'balance' => 0]);
    }

    /**
     * Escrowed single-seat booking created 60 min ago, ride departing in 20 min
     * (~75% elapsed) so the cancel lands in the 70-100 tier (refund 0).
     *
     * @return array{0:User,1:Booking}
     */
    private function fixtureAtHighElapsed(string $paymentMethod): array
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);
        $driver->profile()->create(['full_name' => 'D', 'number_of_rides' => 0]);
        $driverWallet = Wallet::create([
            'user_id' => $driver->id, 'phone_number' => '0911'.rand(1000000, 9999999),
            'wallet_number' => 'WLT-'.Str::random(10), 'balance' => 0,
        ]);
        $driver->update(['wallet_id' => $driverWallet->id]);

        $passenger = User::factory()->create(['is_verified_passenger' => true]);
        $passenger->profile()->create(['full_name' => 'P', 'number_of_rides' => 0]);
        $passengerWallet = Wallet::create([
            'user_id' => $passenger->id, 'phone_number' => '0922'.rand(1000000, 9999999),
            'wallet_number' => 'WLT-'.Str::random(10), 'balance' => 1_000_000,
        ]);
        $passenger->update(['wallet_id' => $passengerWallet->id]);

        DB::statement(
            "INSERT INTO rides (driver_id, pickup_address, destination_address,
                pickup_location, destination_location, departure_time, available_seats,
                price_per_seat, payment_method, booking_type, status, distance, duration,
                communication_number, created_at, updated_at)
             VALUES (?, 'X', 'Y',
                ST_GeomFromText('POINT(33.5138 36.2765)', 4326),
                ST_GeomFromText('POINT(36.2021 37.1343)', 4326),
                ?, 3, 50000, ?, 'direct', 'active', 320, 14400, '0911000000', NOW(), NOW())",
            [$driver->id, now()->addMinutes(20)->format('Y-m-d H:i:s'), $paymentMethod]
        );
        $ride = Ride::latest('id')->firstOrFail();

        $booking = Booking::create([
            'user_id' => $passenger->id, 'ride_id' => $ride->id, 'seats' => 1,
            'status' => 'confirmed', 'communication_number' => '0900000000',
        ]);
        $booking->forceFill([
            'created_at' => now()->subMinutes(60), 'updated_at' => now()->subMinutes(60),
        ])->save();

        if ($paymentMethod === 'e-pay') {
            app(WalletTransactionService::class)->chargePassengerForBooking($booking, $ride, $passenger);
        }

        return [$passenger, $booking->fresh()];
    }

    /** Settlement rows for this booking, excluding the original charge. */
    private function settlementTypes(int $bookingId): array
    {
        return WalletTransaction::where('reference', "booking:{$bookingId}")
            ->where('type', '!=', 'ride_booking_payment')
            ->orderBy('id')
            ->pluck('type')
            ->all();
    }

    private function scoreOf(User $user): float
    {
        return (float) (DB::table('user_scores')->where('user_id', $user->id)->value('score') ?? 0);
    }

    public function test_epay_all_seats_cancel_matches_cancel_booking(): void
    {
        // e-pay branch: the observable effect is the escrow settlement.
        [$passengerA, $bookingA] = $this->fixtureAtHighElapsed('e-pay');
        $scoreBeforeA = $this->scoreOf($passengerA);
        app(BookingService::class)->cancelPartialSeats($bookingA->id, $bookingA->seats, $passengerA);
        $ledgerA = $this->settlementTypes($bookingA->id);
        $scoreA = round($this->scoreOf($passengerA) - $scoreBeforeA, 2);

        [$passengerB, $bookingB] = $this->fixtureAtHighElapsed('e-pay');
        $scoreBeforeB = $this->scoreOf($passengerB);
        app(BookingService::class)->cancelBooking($bookingB->id, $passengerB);
        $ledgerB = $this->settlementTypes($bookingB->id);
        $scoreB = round($this->scoreOf($passengerB) - $scoreBeforeB, 2);

        $this->assertNotEmpty($ledgerA, 'e-pay: a high-elapsed cancel must still settle the escrow');
        $this->assertSame(
            $ledgerA, $ledgerB,
            'V16 (e-pay): cancel-seats(all) settles differently from cancelBooking at the same elapsed%'
        );
        $this->assertEqualsWithDelta($scoreA, $scoreB, 0.01, 'V16 (e-pay): score effect differs between the two paths');
        // Recorded: e-pay cancellation is a documented score no-op on BOTH paths.
        $this->assertSame(0.0, $scoreA, 'V16 (e-pay): recordPassengerCancel is cash-only, so no score delta is expected');
    }

    public function test_cash_all_seats_cancel_matches_cancel_booking(): void
    {
        // cash branch: no escrow; the score penalty is the observable effect.
        [$passengerA, $bookingA] = $this->fixtureAtHighElapsed('cash');
        $scoreBeforeA = $this->scoreOf($passengerA);
        app(BookingService::class)->cancelPartialSeats($bookingA->id, $bookingA->seats, $passengerA);
        $scoreA = round($this->scoreOf($passengerA) - $scoreBeforeA, 2);
        $ledgerA = $this->settlementTypes($bookingA->id);

        [$passengerB, $bookingB] = $this->fixtureAtHighElapsed('cash');
        $scoreBeforeB = $this->scoreOf($passengerB);
        app(BookingService::class)->cancelBooking($bookingB->id, $passengerB);
        $scoreB = round($this->scoreOf($passengerB) - $scoreBeforeB, 2);
        $ledgerB = $this->settlementTypes($bookingB->id);

        $this->assertLessThan(0, $scoreA, 'cash: a ~75% elapsed passenger cancel must cost score (recorded policy: -5 mid / -10 late)');
        $this->assertEqualsWithDelta(
            $scoreA, $scoreB, 0.01,
            'V16 (cash): cancel-seats(all) applies a different score than cancelBooking at the same elapsed%'
        );
        $this->assertSame($ledgerA, $ledgerB, 'V16 (cash): ledger effect differs between the two paths');
    }
}
