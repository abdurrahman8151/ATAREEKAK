<?php

namespace Tests\Feature\Rides;

use App\Models\Photo;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

class RideTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private User $driver;

    private string $token;

    private string $driverPhone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driverPhone = '091'.rand(1000000, 9999999);

        $this->driver = User::factory()->create([
            'is_verified_driver' => true,
            'verification_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        if (! $this->driver->profile) {
            $this->driver->profile()->create(['full_name' => 'Test Driver', 'number_of_rides' => 0]);
        }

        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $type) {
            Photo::create([
                'user_id' => $this->driver->id,
                'type' => $type,
                'path' => "verifications/{$type}/test.jpg",
            ]);
        }

        $this->seedSystemWallets(10_000_000);

        $wallet = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => $this->driverPhone,
            'wallet_number' => 'WLT-DRV-'.Str::random(6),
            'balance' => 1_000_000,
        ]);
        $this->driver->update(['wallet_id' => $wallet->id]);

        $this->token = $this->getToken($this->driver);
    }

    public function test_verified_driver_can_create_ride(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/rides/create-with-route', $this->validRidePayload())
            ->assertStatus(201);
    }

    public function test_unverified_driver_cannot_create_ride(): void
    {
        $unverified = User::factory()->create([
            'is_verified_driver' => false,
            'password' => bcrypt('password123'),
        ]);

        // Controller returns 422 (InvalidArgumentException) not 500
        $this->withToken($this->getToken($unverified))
            ->postJson('/api/rides/create-with-route', $this->validRidePayload())
            ->assertStatus(422);
    }

    public function test_ride_creation_does_not_charge_any_fee(): void
    {
        // FIX: ride creation fees were removed from RideService (see the
        // skipped chargeRideCreationFee() tests in WalletTransactionServiceTest).
        // Creating a ride should leave the driver's wallet balance untouched.
        $walletBefore = (float) Wallet::where('user_id', $this->driver->id)->value('balance');

        $this->withToken($this->token)
            ->postJson('/api/rides/create-with-route', array_merge($this->validRidePayload(), [
                'price_per_seat' => 10_000,
                'available_seats' => 4,
            ]))->assertStatus(201);

        $walletAfter = (float) Wallet::where('user_id', $this->driver->id)->value('balance');
        $this->assertEquals($walletBefore, $walletAfter);
    }

    public function test_ride_creation_fails_with_past_departure_time(): void
    {
        $payload = array_merge($this->validRidePayload(), [
            'departure_time' => now()->subHour()->toISOString(),
        ]);

        $this->withToken($this->token)
            ->postJson('/api/rides/create-with-route', $payload)
            ->assertStatus(422);
    }

    public function test_ride_creation_requires_authentication(): void
    {
        $this->postJson('/api/rides/create-with-route', $this->validRidePayload())
            ->assertStatus(401);
    }

    public function test_driver_can_get_own_rides(): void
    {
        $this->makeRide();
        $this->withToken($this->token)->getJson('/api/rides')->assertStatus(200);
    }

    public function test_can_get_ride_details(): void
    {
        $ride = $this->makeRide();
        $this->withToken($this->token)->getJson("/api/rides/{$ride->id}")->assertStatus(200);
    }

    public function test_get_nonexistent_ride_returns_404(): void
    {
        $this->withToken($this->token)->getJson('/api/rides/99999')->assertStatus(404);
    }

    public function test_driver_can_cancel_own_ride(): void
    {
        $ride = $this->makeRide();
        $this->withToken($this->token)->patchJson("/api/rides/{$ride->id}/cancel")->assertStatus(200);
        $this->assertDatabaseHas('rides', ['id' => $ride->id, 'status' => 'cancelled']);
    }

    public function test_driver_cannot_cancel_others_ride(): void
    {
        $other = User::factory()->create();
        $ride = $this->makeRide(['driver_id' => $other->id]);
        $this->withToken($this->token)->patchJson("/api/rides/{$ride->id}/cancel")->assertStatus(422);
    }

    public function test_already_cancelled_ride_cannot_be_cancelled_again(): void
    {
        $ride = $this->makeRide(['status' => 'cancelled']);
        $this->withToken($this->token)->patchJson("/api/rides/{$ride->id}/cancel")->assertStatus(422);
    }

    public function test_driver_can_finish_active_ride(): void
    {
        $ride = $this->makeRide(['departure_time' => now()->subMinutes(10)]);
        $this->withToken($this->token)->postJson("/api/rides/{$ride->id}/finish")->assertSuccessful();
    }

    public function test_cannot_finish_ride_before_departure_time(): void
    {
        $ride = $this->makeRide(['departure_time' => now()->addHours(2)]);
        $this->withToken($this->token)->postJson("/api/rides/{$ride->id}/finish")->assertStatus(400);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    private function makeRide(array $overrides = []): Ride
    {
        // RV-34: shared builder. Values from the previous fixture: seats 4, price
        // 50000, cash, direct, distance 320.5, duration 240, communication
        // $this->driverPhone, and driver_id overridable via $overrides['driver_id'].
        return RideBuilder::forUserId($overrides['driver_id'] ?? $this->driver->id)
            ->withAttributes(array_merge([
                'available_seats' => 4,
                'price_per_seat' => 50000,
                'payment_method' => 'cash',
                'booking_type' => 'direct',
                'status' => 'active',
                'distance' => 320.5,
                'duration' => 240,
                'communication_number' => $this->driverPhone,
            ], $overrides))
            ->departureTime($overrides['departure_time'] ?? now()->addHours(3))
            ->create();
    }

    /**
     * FIX: added vehicle_type — required field in createRideWithRoute validator.
     * Without it, Laravel returns 422 before any business logic runs.
     */
    private function validRidePayload(): array
    {
        return [
            'pickup_lat' => 33.5138,
            'pickup_lng' => 36.2765,
            'destination_lat' => 36.2021,
            'destination_lng' => 37.1343,
            'pickup_address' => 'دمشق - ساحة المرجة',
            'destination_address' => 'حلب - العزيزية',
            'departure_time' => now()->addHours(48)->toISOString(),
            'available_seats' => 3,
            'price_per_seat' => 50_000,
            'vehicle_type' => 'Toyota Camry',   // ← FIX: was missing
            'payment_method' => 'cash',
            'booking_type' => 'direct',
            'communication_number' => $this->driverPhone,
            'route_index' => 0,
        ];
    }

    private function getToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }
}
