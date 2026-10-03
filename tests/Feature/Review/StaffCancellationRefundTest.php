<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\Employee;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserScore;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * Decision 6 (owner, 2026-10-02): a staff-initiated cancellation refunds every affected passenger IN
 * FULL and applies NO driver score penalty. The owner's rationale: "staff cancellation is rare; make
 * it non-ad-hoc".
 *
 * **The defect this closes (RV-03).** `StaffOperationsController::cancelTrip/cancelBooking` marked
 * bookings `cancelled` and moved NO money at all - the inline comment even claimed "Refund all
 * confirmed bookings" while the code only did `$booking->update(['status' => 'cancelled'])`. Every
 * e-pay passenger's escrow stayed stranded in the SyCash wallet forever.
 *
 * **Why the amount comes from `amount_paid`.** `price_per_seat` is mutable, so refunding
 * `seats * price_per_seat` over/under-refunds if the price changed after the charge. The RV-40
 * snapshot is what the passenger actually paid. The sibling `refundPassengersForDriverCancellation()`
 * still derives from the price - that is decision 3's territory (owner-flagged "reconsider") and is
 * deliberately NOT changed here.
 *
 * The EXISTING StaffOperationsControllerTest already covers all the status transitions (80 tests);
 * none of them asserted money. This file asserts the money.
 */
class StaffCancellationRefundTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private Employee $agent;

    private string $agentToken;

    private User $driver;

    private User $passenger;

    private Wallet $passengerWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Employee::create([
            'username' => 'ops_agent@test.test',
            'email' => 'ops_agent@test.test',
            'password' => 'password123',
            'first_name' => 'Ops', 'last_name' => 'Agent',
            'role' => 'admin',
            'is_active' => true,
            'token_version' => 1,
        ]);

        $this->agentToken = $this->postJson('/api/staff/login', [
            'identifier' => 'ops_agent@test.test',
            'password' => 'password123',
        ])->json('tokens.access_token');
        $this->assertNotEmpty($this->agentToken, 'staff login must yield a token');

        $this->driver = User::factory()->create([
            'is_verified_driver' => true, 'is_verified_passenger' => true,
            'verification_status' => 'approved', 'password' => bcrypt('password123'),
        ]);

        $this->passenger = User::factory()->create([
            'is_verified_passenger' => true, 'verification_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        // SyCash holds the escrow; give it plenty so the refund can always be funded.
        $wallets = $this->seedSystemWallets(10_000_000);

        $this->passengerWallet = Wallet::create([
            'user_id' => $this->passenger->id,
            'phone_number' => '0912'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 0,
        ]);
        $this->passenger->update(['wallet_id' => $this->passengerWallet->id]);

        $this->syCash = $wallets['sycash'];
    }

    private Wallet $syCash;

    /** A ride built the repo way (RideBuilder owns the location/distance/duration invariants). */
    private function makeRide(string $paymentMethod = 'e-pay'): Ride
    {
        return RideBuilder::for($this->driver)
            ->withAttributes([
                'pickup_address' => 'Damascus',
                'destination_address' => 'Aleppo',
                'available_seats' => 4,
                'price_per_seat' => 10_000,
                'payment_method' => $paymentMethod,
                'booking_type' => 'direct',
                'status' => 'full',
                'communication_number' => '0911000000',
            ])
            ->departureTime(now()->addHours(3))
            ->create();
    }

    /** A ride with one confirmed e-pay booking whose RV-40 snapshot is set (money was charged). */
    private function chargedBooking(float $paid, int $seats = 2): array
    {
        $ride = $this->makeRide('e-pay');

        $booking = Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $ride->id,
            'seats' => $seats,
            'status' => 'confirmed',
            'communication_number' => '0912345678',
            'unit_price' => $paid / $seats,
            'amount_paid' => $paid,
            'payment_method' => 'e-pay',
        ]);

        return [$ride, $booking];
    }

    /** @test */
    public function cancelling_a_ride_refunds_the_passenger_the_full_amount_paid(): void
    {
        [$ride, $booking] = $this->chargedBooking(25_000.0);
        $syCashBefore = (float) $this->syCash->balance;

        $response = $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Cancelled by support for operational reasons.',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.new_status', 'cancelled');

        $this->assertSame(
            25_000.0,
            (float) $this->passengerWallet->fresh()->balance,
            'the passenger must be made WHOLE (decision 6: full refund)'
        );
        $this->assertSame(
            $syCashBefore - 25_000.0,
            (float) $this->syCash->fresh()->balance,
            'the money must actually leave SyCash escrow'
        );
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
    }

    /** @test */
    public function the_refund_uses_the_amount_paid_snapshot_not_the_current_ride_price(): void
    {
        // Charged 25,000 when the seat price was 12,500; the price is edited to 99,999 afterwards.
        [$ride, $booking] = $this->chargedBooking(25_000.0);
        $ride->update(['price_per_seat' => 99_999]);

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Cancelled by support after a price change.',
            ])->assertStatus(200);

        $this->assertSame(
            25_000.0,
            (float) $this->passengerWallet->fresh()->balance,
            'refund must be what was PAID, never seats * the mutable current price'
        );
    }

    /** @test */
    public function cancelling_a_single_booking_refunds_that_passenger(): void
    {
        [$ride, $booking] = $this->chargedBooking(15_000.0);

        $response = $this->withToken($this->agentToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [
                'reason' => 'Single booking cancelled by support.',
            ]);

        $response->assertStatus(200);
        $this->assertSame(15_000.0, (float) $this->passengerWallet->fresh()->balance);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
    }

    /** @test */
    public function a_staff_cancellation_applies_no_driver_score_penalty(): void
    {
        [$ride, $booking] = $this->chargedBooking(20_000.0);

        $scoreBefore = UserScore::where('user_id', $this->driver->id)->first();
        $scoreBeforeValue = $scoreBefore ? (int) $scoreBefore->score : null;
        $cancellationsBefore = $scoreBefore ? (int) $scoreBefore->total_cancellations : null;

        $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Support cancelled; the driver is not at fault.',
            ])->assertStatus(200);

        $scoreAfter = UserScore::where('user_id', $this->driver->id)->first();

        // Decision 6: "no driver score penalty". A staff cancellation is not the driver's fault, so
        // neither the score nor the cancellation counter may move.
        $this->assertSame($scoreBeforeValue, $scoreAfter ? (int) $scoreAfter->score : null,
            'a staff cancellation must not change the driver score');
        $this->assertSame($cancellationsBefore, $scoreAfter ? (int) $scoreAfter->total_cancellations : null,
            'a staff cancellation must not count against the driver');
    }

    /** @test */
    public function a_booking_with_no_money_snapshot_is_reported_not_guessed(): void
    {
        // Legacy pre-RV-40 row: confirmed and e-pay, but amount_paid is 0. The refund must NOT
        // invent an amount from the current price - it must surface the row for manual review.
        $ride = $this->makeRide('e-pay');
        $booking = Booking::create([
            'user_id' => $this->passenger->id, 'ride_id' => $ride->id,
            'seats' => 2, 'status' => 'confirmed',
            'communication_number' => '0912345678',
            'amount_paid' => 0, 'payment_method' => 'e-pay',
        ]);

        $response = $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Cancelled while auditing legacy records.',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.needs_manual_review', 1,
            'a confirmed booking with no money snapshot must be surfaced, not silently skipped');
        $this->assertSame(0.0, (float) $this->passengerWallet->fresh()->balance,
            'nothing may be refunded from an amount we cannot prove');
    }

    /** @test */
    public function a_cash_booking_is_not_refunded(): void
    {
        // Cash never touches SyCash, so there is nothing to refund and nothing to review.
        $ride = $this->makeRide('e-pay');
        $booking = Booking::create([
            'user_id' => $this->passenger->id, 'ride_id' => $ride->id,
            'seats' => 2, 'status' => 'confirmed',
            'communication_number' => '0912345678',
            'amount_paid' => 0, 'payment_method' => 'cash',
        ]);

        $response = $this->withToken($this->agentToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Cash ride cancelled by support.',
            ]);

        $response->assertStatus(200);
        $this->assertSame(0.0, (float) $this->passengerWallet->fresh()->balance);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
    }
}
