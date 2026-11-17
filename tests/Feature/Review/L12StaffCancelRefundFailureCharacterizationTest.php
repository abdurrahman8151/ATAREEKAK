<?php

namespace Tests\Feature\Review;

use App\Modules\Ride\Models\Booking;
use App\Modules\Ride\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\Console\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * L12 (modular-monolith slice 11, MONEY/REFUND): the rollback rule for staff cancellations.
 *
 * StaffCancellationRefundTest already pins SUCCESSFUL refunds. Nothing pins the failure rule:
 * a staff cancellation must NOT cancel the ride or the bookings when the passenger refund cannot be
 * funded. cancelTrip() and cancelBooking() refund first inside DB::transaction, and the code comment says
 * "if the refund cannot be funded the transaction rolls back and the ride is NOT cancelled". This file pins that
 * on the UNCHANGED code, so a later refactor cannot silently break it.
 *
 * Here the refund is made unfundable by draining SyCash AFTER the booking is charged, so
 * WalletTransactionService::assertSufficientBalance throws before any balance moves.
 */
class L12StaffCancelRefundFailureCharacterizationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private User $driver;

    private User $passenger;

    private Wallet $passengerWallet;

    private Wallet $syCash;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = User::factory()->create([
            'is_verified_driver' => true,
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
        ]);

        $this->passenger = User::factory()->create([
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
        ]);

        $wallets = $this->seedSystemWallets(10_000_000);
        $this->syCash = $wallets['sycash'];

        $this->passengerWallet = Wallet::create([
            'user_id' => $this->passenger->id,
            'phone_number' => '0913333444',
            'wallet_number' => 'WLT-L12P'.random_int(100000, 999999),
            'balance' => 0,
        ]);
        $this->passenger->update(['wallet_id' => $this->passengerWallet->id]);

        // Admin login accepts only system_admin and sycash (AdminAuthService::isAdminRole).
        // The cancel routes are gated to staff:admin,system_admin, so system_admin can cancel.
        $this->adminToken = $this->staffLogin('l12_admin', 'system_admin');
    }

    public function test_trip_cancel_is_refused_and_leaves_the_ride_and_booking_untouched_when_refund_is_unfunded(): void
    {
        [$ride, $booking] = $this->chargedBooking(25_000.0);
        $this->drainSyCash();

        $response = $this->withToken($this->adminToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Unfunded refund must not cancel the trip',
            ]);

        $this->assertNotSame(200, $response->getStatusCode(), 'an unfunded refund must not report success');
        $this->assertSame('active', $ride->fresh()->status, 'the ride must NOT be cancelled');
        $this->assertSame('confirmed', $booking->fresh()->status, 'the booking must NOT be cancelled');
    }

    public function test_trip_cancel_moves_no_money_when_refund_is_unfunded(): void
    {
        [$ride] = $this->chargedBooking(25_000.0);
        $this->drainSyCash();
        $passengerBefore = (float) $this->passengerWallet->fresh()->balance;
        $syCashBefore = (float) $this->syCash->fresh()->balance;

        $this->withToken($this->adminToken)
            ->postJson("/api/staff/trips/{$ride->id}/cancel", [
                'reason' => 'Unfunded refund must move no money',
            ]);

        $this->assertEquals($passengerBefore, (float) $this->passengerWallet->fresh()->balance);
        $this->assertEquals($syCashBefore, (float) $this->syCash->fresh()->balance);
        $this->assertSame(0, WalletTransaction::where('wallet_id', $this->passengerWallet->id)
            ->where('type', 'like', '%refund%')->count(), 'no refund row may be written');
    }

    public function test_booking_cancel_is_refused_and_leaves_the_booking_untouched_when_refund_is_unfunded(): void
    {
        [, $booking] = $this->chargedBooking(15_000.0);
        $this->drainSyCash();

        $response = $this->withToken($this->adminToken)
            ->postJson("/api/staff/bookings/{$booking->id}/cancel", [
                'reason' => 'Unfunded refund must not cancel the booking',
            ]);

        $this->assertNotSame(200, $response->getStatusCode(), 'an unfunded refund must not report success');
        $this->assertSame('confirmed', $booking->fresh()->status, 'the booking must NOT be cancelled');
        $this->assertEquals(0.0, (float) $this->passengerWallet->fresh()->balance);
    }

    /** Create a ride and a confirmed, fully-paid e-pay booking, with the escrow snapshot set. */
    private function chargedBooking(float $paid, int $seats = 2): array
    {
        $ride = RideBuilder::for($this->driver)
            ->paymentMethod('e-pay')
            ->create();

        $booking = Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $ride->id,
            'seats' => $seats,
            'status' => 'confirmed',
            'communication_number' => '0912345678',
            'unit_price' => $paid / $seats,
            'amount_paid' => $paid,
            'payment_method' => 'e-pay',
            'escrow_held' => $paid,
        ]);

        return [$ride, $booking];
    }

    /** Empty SyCash so the staff refund cannot be funded. Runs AFTER the booking is charged. */
    private function drainSyCash(): void
    {
        $this->syCash->update(['balance' => 0]);
    }

    private function staffLogin(string $username, string $role): string
    {
        $employee = Employee::create([
            'username' => $username,
            'email' => "{$username}@test.com",
            'password' => 'Password123!',
            'first_name' => 'L12',
            'last_name' => 'Probe',
            'role' => $role,
            'is_active' => true,
            'token_version' => 0,
        ]);

        return $this->postJson('/api/admin/login', [
            'username' => $employee->username,
            'password' => 'Password123!',
        ])->json('tokens.access_token');
    }
}
