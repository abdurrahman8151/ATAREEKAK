<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\NoshowReport;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Services\JwtService;
use App\Services\Payment\WalletTransactionService;
use App\Services\Ride\Noshowservice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RV-02 (L1) — a settled booking must never be settled a second time.
 *
 * V16 refuted RV-02's headline claim (cancelling ALL seats via `cancel-seats`
 * and via `cancelBooking` do produce the same settlement ledger and score), so
 * this covers the RESIDUE that V16 explicitly left standing:
 *
 *   `applyPenalty()` loaded the booking with no lock and no status re-check, so
 *   it settled a booking that another path had ALREADY settled — the passenger
 *   confirming completion (which releases escrow 95/5) or any cancel flow. It
 *   then flipped the booking to `no_show` and moved the same fare a second time,
 *   out of SyCash, i.e. out of OTHER bookings' escrow.
 *
 * R2 §1.3 also recorded that a skipped settlement left the report `pending`, so
 * the scheduler retried it every minute; the report now ends terminal as `void`.
 */
class NoshowSettlementGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private User $passenger;

    private Wallet $driverWallet;

    private Wallet $passengerWallet;

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
        $this->driverWallet = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => '0911'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 0,
        ]);
        $this->driver->update(['wallet_id' => $this->driverWallet->id]);

        $this->passenger = User::factory()->create(['is_verified_passenger' => true]);
        $this->passenger->profile()->create(['full_name' => 'P', 'number_of_rides' => 0]);
        $this->passengerWallet = Wallet::create([
            'user_id' => $this->passenger->id,
            'phone_number' => '0922'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 1000000,
        ]);
        $this->passenger->update(['wallet_id' => $this->passengerWallet->id]);
    }

    private function insertRide(): Ride
    {
        DB::statement(
            "INSERT INTO rides (driver_id, pickup_address, destination_address,
                pickup_location, destination_location, departure_time, available_seats,
                price_per_seat, payment_method, booking_type, status, distance, duration,
                communication_number, created_at, updated_at)
             VALUES (?, 'دمشق', 'حلب',
                ST_GeomFromText('POINT(33.5138 36.2765)', 4326),
                ST_GeomFromText('POINT(36.2021 37.1343)', 4326),
                ?, ?, 50000, 'e-pay', 'direct', 'active', 320.5, 240, '0911000000', NOW(), NOW())",
            [$this->driver->id, now()->subMinutes(5)->format('Y-m-d H:i:s'), 3]
        );

        return Ride::latest('id')->first();
    }

    /** One confirmed booking with its fare escrowed into SyCash. */
    private function escrowedBooking(): array
    {
        $ride = $this->insertRide();

        $booking = Booking::create([
            'user_id' => $this->passenger->id,
            'ride_id' => $ride->id,
            'seats' => 1,
            'status' => 'confirmed',
            'communication_number' => '0900000000',
        ]);

        app(WalletTransactionService::class)->chargePassengerForBooking($booking, $ride, $this->passenger);

        return [$ride, $booking];
    }

    /** An expired, still-pending report accusing the passenger of no-show. */
    private function expiredReport(Ride $ride, Booking $booking): NoshowReport
    {
        return NoshowReport::create([
            'ride_id' => $ride->id,
            'booking_id' => $booking->id,
            'reporter_id' => $this->driver->id,
            'reporter_role' => 'driver',
            'target_id' => $this->passenger->id,
            'target_role' => 'passenger',
            'payment_method' => 'e-pay',
            'status' => 'pending',
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function test_no_show_settlement_skips_a_booking_already_released(): void
    {
        [$ride1, $b1] = $this->escrowedBooking();
        [, $b2] = $this->escrowedBooking();   // second booking: its 50,000 must survive

        $report = $this->expiredReport($ride1, $b1);

        // The passenger confirms completion: escrow is released 95/5 here.
        $token = app(JwtService::class)->generateTokenPair($this->passenger)['access_token'];

        $this->withToken($token)
            ->postJson("/api/bookings/{$b1->id}/passenger-confirm")
            ->assertOk();

        $driverAfter = (float) $this->driverWallet->fresh()->balance;
        $syCashAfter = (float) $this->syCash->fresh()->balance;

        app(Noshowservice::class)->resolveExpiredReports();

        $this->assertSame(
            $driverAfter,
            (float) $this->driverWallet->fresh()->balance,
            'RV-02: the driver must not be paid twice for the same booking'
        );
        $this->assertSame(
            $syCashAfter,
            (float) $this->syCash->fresh()->balance,
            'RV-02: SyCash must not be drained again — b2\'s escrow must survive'
        );
        $this->assertSame(
            'void',
            $report->fresh()->status,
            'RV-02: the report must end terminal instead of retrying forever'
        );
    }

    public function test_no_show_settlement_skips_a_cancelled_booking(): void
    {
        [$ride, $booking] = $this->escrowedBooking();
        $report = $this->expiredReport($ride, $booking);

        // Cancelled between the report being filed and its expiry (scenario B).
        $booking->update(['status' => 'cancelled']);

        $driverBefore = (float) $this->driverWallet->fresh()->balance;
        $syCashBefore = (float) $this->syCash->fresh()->balance;

        app(Noshowservice::class)->resolveExpiredReports();

        $this->assertSame($driverBefore, (float) $this->driverWallet->fresh()->balance);
        $this->assertSame($syCashBefore, (float) $this->syCash->fresh()->balance);
        $this->assertSame('void', $report->fresh()->status);
        $this->assertSame(
            'cancelled',
            $booking->fresh()->status,
            'RV-02: a cancelled booking must not be flipped to no_show'
        );
    }

    public function test_a_genuine_expired_report_still_settles(): void
    {
        // The guard must not disable the feature: a still-confirmed booking is
        // settled exactly as before.
        [$ride, $booking] = $this->escrowedBooking();
        $report = $this->expiredReport($ride, $booking);

        $driverBefore = (float) $this->driverWallet->fresh()->balance;

        app(Noshowservice::class)->resolveExpiredReports();

        $this->assertSame('resolved_reporter_wins', $report->fresh()->status);
        $this->assertSame('no_show', $booking->fresh()->status);
        $this->assertGreaterThan(
            $driverBefore,
            (float) $this->driverWallet->fresh()->balance,
            'RV-02: a legitimate settlement must still pay the driver'
        );
    }
}
