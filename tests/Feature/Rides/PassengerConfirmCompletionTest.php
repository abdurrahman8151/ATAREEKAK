<?php

namespace Tests\Feature\Rides;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Services\JwtService;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Passenger confirm-completion flow (T1-1 regression coverage).
 *
 * Intended money flow:
 *   book (e-pay)            → passenger wallet → SyCash escrow
 *   passenger confirms      → THAT passenger's escrow → driver (95%) + Primary (5%)
 *   last passenger confirms → ride status becomes finished
 *
 * The driver has no finish/confirm action; the ride closes purely on
 * passenger confirmations, so per-passenger release is the only payout path.
 *
 * NOTE: the rides table uses SPATIAL indexes, so this suite requires MySQL —
 * it cannot run on the SQLite connection configured in phpunit.xml.
 */
class PassengerConfirmCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private User $p1;

    private User $p2;

    private Wallet $driverWallet;

    private Wallet $syCash;

    private Wallet $primary;

    private string $p1Token;

    private string $p2Token;

    protected function setUp(): void
    {
        // The rides table is defined with SPATIAL indexes, which SQLite cannot
        // create. RefreshDatabase migrates inside parent::setUp(), so this guard
        // must run first — otherwise the suite errors instead of skipping.
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped(
                'Requires MySQL: the rides table uses SPATIAL indexes unsupported by SQLite.'
            );
        }

        parent::setUp();

        // System wallets are located by phone only — config/admin.php no longer
        // holds email/password credentials.
        $this->syCash = Wallet::create([
            'user_id' => null, 'phone_number' => config('admin.sycash.phone'), 'balance' => 0,
        ]);
        $this->primary = Wallet::create([
            'user_id' => null, 'phone_number' => config('admin.system_admin.phone'), 'balance' => 0,
        ]);

        $this->driver = User::factory()->create(['is_verified_driver' => true]);
        $this->driver->profile()->create(['full_name' => 'Driver', 'number_of_rides' => 0]);
        $this->driverWallet = Wallet::create([
            'user_id' => $this->driver->id, 'phone_number' => '0911'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10), 'balance' => 0,
        ]);
        $this->driver->update(['wallet_id' => $this->driverWallet->id]);

        foreach ([['p1', '0922', 'p1Token'], ['p2', '0933', 'p2Token']] as [$prop, $prefix, $tokenProp]) {
            $u = User::factory()->create(['is_verified_passenger' => true]);
            $u->profile()->create(['full_name' => $prop, 'number_of_rides' => 0]);
            $w = Wallet::create([
                'user_id' => $u->id, 'phone_number' => $prefix.rand(100000, 999999),
                'wallet_number' => 'WLT-'.Str::random(10), 'balance' => 1_000_000,
            ]);
            $u->update(['wallet_id' => $w->id]);
            $this->{$prop} = $u;
            $this->{$tokenProp} = app(JwtService::class)->generateTokenPair($u)['access_token'];
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function insertRide(array $o = []): Ride
    {
        $departure = $o['departure_time'] ?? now()->subMinutes(5);

        DB::statement("
            INSERT INTO rides (
                driver_id, pickup_address, destination_address,
                pickup_location, destination_location,
                departure_time, available_seats, price_per_seat,
                payment_method, booking_type, status, distance, duration,
                communication_number, created_at, updated_at
            ) VALUES (
                ?, 'دمشق', 'حلب',
                ST_GeomFromText('POINT(33.5138 36.2765)', 4326),
                ST_GeomFromText('POINT(36.2021 37.1343)', 4326),
                ?, ?, 50000, ?, ?, ?, 320.5, 240, ?, NOW(), NOW()
            )
        ", [
            $this->driver->id, $departure->format('Y-m-d H:i:s'),
            $o['available_seats'] ?? 3, $o['payment_method'] ?? 'e-pay',
            $o['booking_type'] ?? 'direct', $o['status'] ?? 'active', '0911000000',
        ]);

        return Ride::latest('id')->first();
    }

    private function book(Ride $ride, User $passenger): Booking
    {
        $booking = Booking::create([
            'user_id' => $passenger->id, 'ride_id' => $ride->id, 'seats' => 1,
            'status' => 'confirmed', 'communication_number' => '0900000000',
        ]);

        app(WalletTransactionService::class)->chargePassengerForBooking($booking, $ride, $passenger);

        return $booking;
    }

    private function ledgerCount(int $bookingId, string $type): int
    {
        return DB::table('wallet_transactions')
            ->where('reference', "booking:{$bookingId}")
            ->where('type', $type)
            ->count();
    }

    // ── Money flow ────────────────────────────────────────────────────────────

    public function test_each_passenger_releases_their_own_escrow_on_confirm(): void
    {
        $ride = $this->insertRide();
        $b1 = $this->book($ride, $this->p1);
        $b2 = $this->book($ride, $this->p2);

        // Escrow holds both fares; driver has nothing yet
        $this->assertEquals(100_000.0, (float) $this->syCash->fresh()->balance);
        $this->assertEquals(0.0, (float) $this->driverWallet->fresh()->balance);

        // ── Passenger 1 confirms boarding ────────────────────────────────────
        $r1 = $this->withToken($this->p1Token)
            ->postJson("/api/bookings/{$b1->id}/passenger-confirm");

        $r1->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertFalse($r1->json('ride_finished'));
        $this->assertEquals('completed', $b1->fresh()->status);
        $this->assertEquals(47_500.0, (float) $this->driverWallet->fresh()->balance, 'driver receives 95% of p1 fare');
        $this->assertEquals(2_500.0, (float) $this->primary->fresh()->balance, 'platform receives 5% of p1 fare');
        $this->assertEquals(50_000.0, (float) $this->syCash->fresh()->balance, 'only p1 escrow released');
        $this->assertEquals('launched', $ride->fresh()->status);

        // ── Passenger 2 confirms boarding ────────────────────────────────────
        $r2 = $this->withToken($this->p2Token)
            ->postJson("/api/bookings/{$b2->id}/passenger-confirm");

        $r2->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertTrue($r2->json('ride_finished'));
        $this->assertEquals(95_000.0, (float) $this->driverWallet->fresh()->balance, 'driver has both fares');
        $this->assertEquals(5_000.0, (float) $this->primary->fresh()->balance);
        $this->assertEquals(0.0, (float) $this->syCash->fresh()->balance, 'escrow fully drained');
        $this->assertEquals('finished', $ride->fresh()->status, 'ride finishes once all passengers confirmed');
    }

    public function test_ride_finishes_only_after_the_last_passenger_confirms(): void
    {
        $ride = $this->insertRide();
        $b1 = $this->book($ride, $this->p1);
        $b2 = $this->book($ride, $this->p2);

        $this->withToken($this->p1Token)->postJson("/api/bookings/{$b1->id}/passenger-confirm")->assertStatus(200);
        $this->assertNotEquals('finished', $ride->fresh()->status);

        $this->withToken($this->p2Token)->postJson("/api/bookings/{$b2->id}/passenger-confirm")->assertStatus(200);
        $this->assertEquals('finished', $ride->fresh()->status);
    }

    public function test_legacy_awaiting_confirmation_ride_is_still_confirmable(): void
    {
        // Rows created before LAUNCHED replaced awaiting_confirmation must remain
        // confirmable — otherwise those passengers can never release their escrow.
        $ride = $this->insertRide(['status' => 'awaiting_confirmation']);
        $b1 = $this->book($ride, $this->p1);

        $this->withToken($this->p1Token)
            ->postJson("/api/bookings/{$b1->id}/passenger-confirm")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertEquals(47_500.0, (float) $this->driverWallet->fresh()->balance);
        $this->assertEquals('finished', $ride->fresh()->status);
    }

    // ── Exactly-once payout ───────────────────────────────────────────────────

    public function test_confirming_twice_pays_the_driver_only_once(): void
    {
        $ride = $this->insertRide();
        $b1 = $this->book($ride, $this->p1);

        $this->withToken($this->p1Token)->postJson("/api/bookings/{$b1->id}/passenger-confirm")->assertStatus(200);
        $this->withToken($this->p1Token)->postJson("/api/bookings/{$b1->id}/passenger-confirm")->assertStatus(500);

        $this->assertEquals(47_500.0, (float) $this->driverWallet->fresh()->balance);
        $this->assertEquals(1, $this->ledgerCount($b1->id, 'ride_earning'));
        $this->assertEquals(1, $this->ledgerCount($b1->id, 'escrow_release'));
    }

    public function test_ride_wide_lump_sum_release_never_fires(): void
    {
        // releaseEarningsToDriver() writes 'escrow_released' (plural). It is a
        // ride-wide payout that would double-pay a booking already released
        // per-passenger, so it must stay unreachable.
        $ride = $this->insertRide();
        $b1 = $this->book($ride, $this->p1);
        $b2 = $this->book($ride, $this->p2);

        $this->withToken($this->p1Token)->postJson("/api/bookings/{$b1->id}/passenger-confirm")->assertStatus(200);
        $this->withToken($this->p2Token)->postJson("/api/bookings/{$b2->id}/passenger-confirm")->assertStatus(200);

        $this->assertEquals(0, $this->ledgerCount($b1->id, 'escrow_released'));
        $this->assertEquals(0, $this->ledgerCount($b2->id, 'escrow_released'));
        $this->assertEquals(95_000.0, (float) $this->driverWallet->fresh()->balance);
    }

    // ── Denied paths must not move money ──────────────────────────────────────

    public function test_confirm_requires_authentication(): void
    {
        $ride = $this->insertRide();
        $b1 = $this->book($ride, $this->p1);

        $this->postJson("/api/bookings/{$b1->id}/passenger-confirm")->assertStatus(401);

        $this->assertEquals(0.0, (float) $this->driverWallet->fresh()->balance);
        $this->assertEquals(50_000.0, (float) $this->syCash->fresh()->balance);
    }

    public function test_another_passenger_cannot_confirm_someone_elses_booking(): void
    {
        $ride = $this->insertRide();
        $b1 = $this->book($ride, $this->p1);

        $this->withToken($this->p2Token)
            ->postJson("/api/bookings/{$b1->id}/passenger-confirm")
            ->assertStatus(500);

        $this->assertEquals(0.0, (float) $this->driverWallet->fresh()->balance);
        $this->assertEquals(50_000.0, (float) $this->syCash->fresh()->balance);
    }

    public function test_confirm_before_departure_is_rejected(): void
    {
        $ride = $this->insertRide(['departure_time' => now()->addHours(2)]);
        $b1 = $this->book($ride, $this->p1);

        $this->withToken($this->p1Token)
            ->postJson("/api/bookings/{$b1->id}/passenger-confirm")
            ->assertStatus(500);

        $this->assertEquals(0.0, (float) $this->driverWallet->fresh()->balance);
    }

    public function test_cancelled_ride_cannot_be_confirmed(): void
    {
        $ride = $this->insertRide(['status' => 'cancelled']);
        $b1 = $this->book($ride, $this->p1);

        $this->withToken($this->p1Token)
            ->postJson("/api/bookings/{$b1->id}/passenger-confirm")
            ->assertStatus(500);

        $this->assertEquals(0.0, (float) $this->driverWallet->fresh()->balance);
    }

    public function test_finished_ride_cannot_be_confirmed_again(): void
    {
        $ride = $this->insertRide(['status' => 'finished']);
        $b1 = $this->book($ride, $this->p1);

        $this->withToken($this->p1Token)
            ->postJson("/api/bookings/{$b1->id}/passenger-confirm")
            ->assertStatus(500);

        $this->assertEquals(0.0, (float) $this->driverWallet->fresh()->balance);
    }
}
