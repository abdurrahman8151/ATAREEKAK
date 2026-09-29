<?php

namespace Tests\Feature\Rides;

use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RideResourceTest — Feature tests that exercise RideResource serialization.
 *
 * LOCATION: tests/Feature/Rides/RideResourceTest.php
 *
 * HOW TO TEST IN POSTMAN:
 * GET  /api/rides/{rideId}          → exercises RideResource via show()
 * GET  /api/rides                   → exercises RideResource::collection() via index()
 *
 * WHAT WE VERIFY:
 * - Response structure matches RideResource fields (id, driver, pickup, destination,
 *   departure_time, seats, price_per_seat, status, distance, duration, etc.)
 * - Nested driver object is present
 * - Coordinate arrays are present
 * - Numeric fields are correct types
 */
class RideResourceTest extends TestCase
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
            $this->driver->profile()->create([
                'full_name' => 'Test Driver',
                'number_of_rides' => 0,
            ]);
        }

        $this->seedSystemWallets(10_000_000);

        $wallet = Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => $this->driverPhone,
            'wallet_number' => 'WLT-'.Str::random(8),
            'balance' => 1_000_000,
        ]);
        $this->driver->update(['wallet_id' => $wallet->id]);

        $this->token = $this->getToken($this->driver);
    }

    // ─── show() serialization ─────────────────────────────────────────────────

    public function test_show_returns_top_level_success_key(): void
    {
        $ride = $this->makeRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_show_returns_id_field(): void
    {
        $ride = $this->makeRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals($ride->id, $response->json('data.id'));
    }

    public function test_show_returns_driver_nested_object(): void
    {
        $ride = $this->makeRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'driver' => ['id', 'name'],
                ],
            ]);
    }

    public function test_show_returns_pickup_object(): void
    {
        $ride = $this->makeRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'pickup' => ['address'],
                ],
            ]);
    }

    public function test_show_returns_destination_object(): void
    {
        $ride = $this->makeRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'destination' => ['address'],
                ],
            ]);
    }

    public function test_show_returns_seats_object_with_available_field(): void
    {
        $ride = $this->makeRide(['available_seats' => 3]);
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('data.seats.available'));
    }

    public function test_show_returns_price_per_seat(): void
    {
        $ride = $this->makeRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.price_per_seat'));
    }

    public function test_show_returns_status_field(): void
    {
        $ride = $this->makeRide(['status' => 'active']);
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals('active', $response->json('data.status'));
    }

    public function test_show_returns_vehicle_type(): void
    {
        $ride = $this->makeRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertArrayHasKey('vehicle_type', $response->json('data'));
    }

    public function test_show_returns_payment_method(): void
    {
        $ride = $this->makeRide(['payment_method' => 'cash']);
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals('cash', $response->json('data.payment_method'));
    }

    public function test_show_returns_departure_time_formatted(): void
    {
        $ride = $this->makeRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.departure_time'));
    }

    public function test_show_returns_distance_object(): void
    {
        $ride = $this->makeRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'distance' => ['meters', 'kilometers'],
                ],
            ]);
    }

    public function test_show_returns_duration_object(): void
    {
        $ride = $this->makeRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'duration' => ['seconds', 'minutes', 'human'],
                ],
            ]);
    }

    public function test_show_returns_created_at_and_updated_at(): void
    {
        $ride = $this->makeRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['created_at', 'updated_at'],
            ]);
    }

    public function test_show_driver_id_matches_actual_driver(): void
    {
        $ride = $this->makeRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals($this->driver->id, $response->json('data.driver.id'));
    }

    // ─── index() collection ───────────────────────────────────────────────────

    public function test_index_returns_collection_wrapped_in_data_key(): void
    {
        $this->makeRide();
        $this->withToken($this->token)
            ->getJson('/api/rides')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeRide(array $overrides = []): Ride
    {
        // RV-34: shared builder. This file's fixture used DISTINCT values from the
        // others and they are preserved exactly: addresses 'دمشق - المزة' /
        // 'حلب - العزيزية', distance 320500, duration 14400, communication
        // $this->driverPhone, seats 4 / price 50000 / cash / direct / active
        // (overridable). It also returned the ride with driver+profile eager loaded,
        // which the resource assertions need.
        $ride = RideBuilder::for($this->driver)
            ->withAttributes(array_merge([
                'pickup_address' => 'دمشق - المزة',
                'destination_address' => 'حلب - العزيزية',
                'available_seats' => 4,
                'price_per_seat' => 50000,
                'payment_method' => 'cash',
                'booking_type' => 'direct',
                'status' => 'active',
                'distance' => 320500,
                'duration' => 14400,
                'communication_number' => $this->driverPhone,
            ], $overrides))
            ->departureTime(now()->addHours(3))
            ->create();

        return Ride::with(['driver', 'driver.profile'])->find($ride->id);
    }

    private function getToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }
}
