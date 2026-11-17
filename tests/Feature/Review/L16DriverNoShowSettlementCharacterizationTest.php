<?php

namespace Tests\Feature\Review;

use App\Modules\Ride\Models\Booking;
use App\Modules\Ride\Models\NoshowReport;
use App\Modules\Ride\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\Money\Services\BookingCharge;
use App\Modules\Ride\Services\Noshowservice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * L16 (modular-monolith slice 14, MONEY + SCORE): characterization of the DRIVER-no-show branch of
 * Noshowservice::applyPenalty (now penaliseDriver).
 *
 * The existing NoshowSettlementGuardTest and MoneyPathBatchTest only settle reports where the PASSENGER is the
 * target. This file drives the other branch: the passenger accuses the driver, the report expires, and the
 * settlement runs through resolveExpiredReports(). It pins, on e-pay:
 *   - the booking becomes no_show;
 *   - the passenger is refunded seats x price_per_seat, moved out of SyCash escrow;
 *   - a driver_no_show_refund row is written and the escrow leg is debited;
 *   - the report resolves as resolved_reporter_wins.
 */
class L16DriverNoShowSettlementCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private User $passenger;

    private Wallet $syCash;

    protected function setUp(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: SPATIAL ride index + lockForUpdate semantics.');
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

        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $this->driver->profile()->create(['full_name' => 'D', 'number_of_rides' => 0]);
        $driverWallet = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => '0911'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 0,
        ]);
        $this->driver->update(['wallet_id' => $driverWallet->id]);

        $this->passenger = User::factory()->create(['is_verified_passenger' => true]);
        $this->passenger->profile()->create(['full_name' => 'P', 'number_of_rides' => 0]);
        $passengerWallet = Wallet::create([
            'user_id' => $this->passenger->id,
            'phone_number' => '0922'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 1000000,
        ]);
        $this->passenger->update(['wallet_id' => $passengerWallet->id]);
    }

    /** One confirmed e-pay booking with its fare escrowed into SyCash. */
    private function escrowedBooking(int $seats): array
    {
        $ride = RideBuilder::for($this->driver)->create();

        $booking = Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $ride->id,
            'seats' => $seats,
            'status' => 'confirmed',
            'communication_number' => '0900000000',
        ]);

        app(BookingCharge::class)->chargePassengerForBooking($booking, $ride, $this->passenger);

        return [$ride, $booking];
    }

    /** An expired, still-pending report where the PASSENGER accuses the DRIVER (the branch under test). */
    private function expiredReportAgainstDriver(Ride $ride, Booking $booking): NoshowReport
    {
        return NoshowReport::create([
            'ride_id' => $ride->id,
            'booking_id' => $booking->id,
            'reporter_id' => $this->passenger->id,
            'reporter_role' => 'passenger',
            'target_id' => $this->driver->id,
            'target_role' => 'driver',
            'payment_method' => 'e-pay',
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function test_a_driver_no_show_marks_the_booking_no_show(): void
    {
        [$ride, $booking] = $this->escrowedBooking(1);
        $report = $this->expiredReportAgainstDriver($ride, $booking);

        app(Noshowservice::class)->resolveExpiredReports();

        $this->assertSame('no_show', $booking->fresh()->status);
        $this->assertSame('resolved_reporter_wins', $report->fresh()->status, 'the passenger (reporter) wins');
    }

    public function test_a_driver_no_show_refunds_the_passenger_the_full_fare_from_escrow(): void
    {
        [$ride, $booking] = $this->escrowedBooking(2);
        $fare = (float) $booking->seats * (float) $ride->price_per_seat;
        $passengerBefore = (float) Wallet::forUser((int) $this->passenger->id)->fresh()->balance;
        $this->expiredReportAgainstDriver($ride, $booking);

        app(Noshowservice::class)->resolveExpiredReports();

        $this->assertEquals(
            $passengerBefore + $fare,
            (float) Wallet::forUser((int) $this->passenger->id)->fresh()->balance,
            'the passenger must get back seats x price_per_seat'
        );
        // The refund is a double-entry pair: one debit from SyCash and one credit to the passenger.
        $legs = WalletTransaction::where('type', 'driver_no_show_refund')->where('reference', "booking:{$booking->id}");
        $this->assertSame(1, (clone $legs)->where('amount', '<', 0)->count(), 'exactly one SyCash debit leg');
        $this->assertSame(1, (clone $legs)->where('amount', '>', 0)->count(), 'exactly one passenger credit leg');
    }

    public function test_a_driver_no_show_writes_no_driver_payout(): void
    {
        [$ride, $booking] = $this->escrowedBooking(1);
        $driverBefore = (float) Wallet::forUser((int) $this->driver->id)->fresh()->balance;
        $this->expiredReportAgainstDriver($ride, $booking);

        app(Noshowservice::class)->resolveExpiredReports();

        $this->assertEquals(
            $driverBefore,
            (float) Wallet::forUser((int) $this->driver->id)->fresh()->balance,
            'a driver who failed to show must receive nothing from the escrow'
        );
    }

    public function test_a_driver_no_show_is_not_settled_twice(): void
    {
        [$ride, $booking] = $this->escrowedBooking(1);
        $this->expiredReportAgainstDriver($ride, $booking);

        app(Noshowservice::class)->resolveExpiredReports();
        app(Noshowservice::class)->resolveExpiredReports();

        $this->assertSame(
            2,
            WalletTransaction::where('type', 'driver_no_show_refund')->where('reference', "booking:{$booking->id}")->count(),
            'a second run must not add refund legs: still exactly one debit and one credit'
        );
    }
}
