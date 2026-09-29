<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private User $driver;

    private User $passenger;

    private Ride $ride;

    private string $driverToken;

    private string $passengerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = User::factory()->create([
            'is_verified_driver' => true,
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $this->passenger = User::factory()->create([
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        // Only the SyCash system wallet is seeded here: this file's own driver
        // wallet already occupies config('admin.system_admin.phone'), and
        // wallets.phone_number is UNIQUE.
        $this->syCashWallet(10_000_000);

        $dw = Wallet::create(['user_id' => $this->driver->id, 'phone_number' => '0912345678', 'wallet_number' => 'WLT-DRV-001', 'balance' => 1_000_000]);
        $this->driver->update(['wallet_id' => $dw->id]);

        $pw = Wallet::create(['user_id' => $this->passenger->id, 'phone_number' => '0911111111', 'wallet_number' => 'WLT-PAS-001', 'balance' => 1_000_000]);
        $this->passenger->update(['wallet_id' => $pw->id]);

        $this->ride = $this->makeRide($this->driver);
        $this->driverToken = $this->getToken($this->driver);
        $this->passengerToken = $this->getToken($this->passenger);
    }

    public function test_verified_passenger_can_book_ride(): void
    {
        $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats' => 1, 'communication_number' => '0912345678',
                'idempotency_key' => Str::uuid()->toString(),
            ])->assertStatus(201);
    }

    public function test_driver_cannot_book_own_ride(): void
    {
        $response = $this->withToken($this->driverToken)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats' => 1, 'communication_number' => '0912345678',
                'idempotency_key' => Str::uuid()->toString(),
            ]);
        $this->assertNotEquals(201, $response->status());
    }

    public function test_unverified_passenger_cannot_book(): void
    {
        $unverified = User::factory()->create(['is_verified_passenger' => false, 'password' => bcrypt('password123')]);
        $token = $this->getToken($unverified);

        $response = $this->withToken($token)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats' => 1, 'communication_number' => '0912345678',
                'idempotency_key' => Str::uuid()->toString(),
            ]);
        $this->assertNotEquals(201, $response->status());
    }

    public function test_cannot_book_more_seats_than_available(): void
    {
        $response = $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats' => 10, 'communication_number' => '0912345678',
                'idempotency_key' => Str::uuid()->toString(),
            ]);
        $this->assertNotEquals(201, $response->status());
    }

    public function test_cannot_book_zero_seats(): void
    {
        $response = $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats' => 0, 'communication_number' => '0912345678',
                'idempotency_key' => Str::uuid()->toString(),
            ]);
        $this->assertContains($response->status(), [400, 422]);
    }

    public function test_booking_reduces_available_seats(): void
    {
        $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats' => 2, 'communication_number' => '0912345678',
                'idempotency_key' => Str::uuid()->toString(),
            ]);

        $this->ride->refresh();
        $this->assertEquals(2, $this->ride->available_seats);
    }

    public function test_ride_becomes_full_when_all_seats_booked(): void
    {
        $this->withToken($this->passengerToken)
            ->postJson("/api/rides/{$this->ride->id}/book", [
                'seats' => 4, 'communication_number' => '0912345678',
                'idempotency_key' => Str::uuid()->toString(),
            ]);

        $this->ride->refresh();
        $this->assertEquals('full', $this->ride->status);
    }

    public function test_idempotency_prevents_duplicate_bookings(): void
    {
        $key = Str::uuid()->toString();
        $payload = ['seats' => 1, 'communication_number' => '0912345678', 'idempotency_key' => $key];

        $r1 = $this->withToken($this->passengerToken)->postJson("/api/rides/{$this->ride->id}/book", $payload);
        $r2 = $this->withToken($this->passengerToken)->postJson("/api/rides/{$this->ride->id}/book", $payload);

        $r1->assertStatus(201);
        $r2->assertStatus(201);
        $this->assertEquals($r1->json('data.id'), $r2->json('data.id'));
    }

    public function test_driver_can_accept_pending_booking(): void
    {
        $reqRide = $this->makeRide($this->driver, ['booking_type' => 'request', 'payment_method' => 'cash']);

        $booking = Booking::create([
            'user_id' => $this->passenger->id, 'ride_id' => $reqRide->id,
            'seats' => 1, 'status' => 'pending', 'communication_number' => '0912345678',
        ]);

        $this->withToken($this->driverToken)
            ->postJson("/api/bookings/{$booking->id}/accept")
            ->assertStatus(200);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
    }

    public function test_driver_can_reject_pending_booking(): void
    {
        $reqRide = $this->makeRide($this->driver, ['booking_type' => 'request', 'payment_method' => 'cash']);

        $booking = Booking::create([
            'user_id' => $this->passenger->id, 'ride_id' => $reqRide->id,
            'seats' => 1, 'status' => 'pending', 'communication_number' => '0912345678',
        ]);

        $this->withToken($this->driverToken)
            ->postJson("/api/bookings/{$booking->id}/reject")
            ->assertStatus(200);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
    }

    public function test_passenger_can_view_own_bookings(): void
    {
        Booking::create([
            'user_id' => $this->passenger->id, 'ride_id' => $this->ride->id,
            'seats' => 1, 'status' => 'confirmed', 'communication_number' => '0912345678',
        ]);

        $this->withToken($this->passengerToken)->getJson('/api/bookings')->assertStatus(200);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeRide(User $driver, array $overrides = []): Ride
    {
        // RV-34: shared builder. Values from the previous fixture: seats 4, price
        // 50000, cash/direct/active (overridable), distance 320.5, duration 240,
        // communication 0912345678, departure now+3h. The old fixture's
        // ST_GeomFromText had NO SRID; the builder always writes 4326, which this
        // schema requires for ST_Distance_Sphere.
        return RideBuilder::for($driver)
            ->withAttributes(array_merge([
                'available_seats' => 4,
                'price_per_seat' => 50000,
                'payment_method' => 'cash',
                'booking_type' => 'direct',
                'status' => 'active',
                'distance' => 320.5,
                'duration' => 240,
                'communication_number' => '0912345678',
            ], $overrides))
            ->departureTime(now()->addHours(3))
            ->create();
    }

    private function getToken(User $user): string
    {
        $r = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123']);

        return $r->json('tokens.access_token');
    }
}
