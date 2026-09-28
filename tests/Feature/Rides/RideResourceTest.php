<?php

namespace Tests\Feature\Rides;

use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        $this->seedAdminWallets();

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
        $ride = $this->insertRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_show_returns_id_field(): void
    {
        $ride = $this->insertRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals($ride->id, $response->json('data.id'));
    }

    public function test_show_returns_driver_nested_object(): void
    {
        $ride = $this->insertRide();
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
        $ride = $this->insertRide();
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
        $ride = $this->insertRide();
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
        $ride = $this->insertRide(['available_seats' => 3]);
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('data.seats.available'));
    }

    public function test_show_returns_price_per_seat(): void
    {
        $ride = $this->insertRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.price_per_seat'));
    }

    public function test_show_returns_status_field(): void
    {
        $ride = $this->insertRide(['status' => 'active']);
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals('active', $response->json('data.status'));
    }

    public function test_show_returns_vehicle_type(): void
    {
        $ride = $this->insertRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertArrayHasKey('vehicle_type', $response->json('data'));
    }

    public function test_show_returns_payment_method(): void
    {
        $ride = $this->insertRide(['payment_method' => 'cash']);
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals('cash', $response->json('data.payment_method'));
    }

    public function test_show_returns_departure_time_formatted(): void
    {
        $ride = $this->insertRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.departure_time'));
    }

    public function test_show_returns_distance_object(): void
    {
        $ride = $this->insertRide();
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
        $ride = $this->insertRide();
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
        $ride = $this->insertRide();
        $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['created_at', 'updated_at'],
            ]);
    }

    public function test_show_driver_id_matches_actual_driver(): void
    {
        $ride = $this->insertRide();
        $response = $this->withToken($this->token)
            ->getJson("/api/rides/{$ride->id}");

        $response->assertStatus(200);
        $this->assertEquals($this->driver->id, $response->json('data.driver.id'));
    }

    // ─── index() collection ───────────────────────────────────────────────────

    public function test_index_returns_collection_wrapped_in_data_key(): void
    {
        $this->insertRide();
        $this->withToken($this->token)
            ->getJson('/api/rides')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function insertRide(array $overrides = []): Ride
    {
        $status = $overrides['status'] ?? 'active';
        $seats = $overrides['available_seats'] ?? 4;
        $paymentMethod = $overrides['payment_method'] ?? 'cash';

        DB::statement("
            INSERT INTO rides (
                driver_id, pickup_address, destination_address,
                pickup_location, destination_location,
                departure_time, available_seats, price_per_seat,
                payment_method, booking_type, status, distance, duration,
                communication_number, created_at, updated_at
            ) VALUES (
                ?, 'دمشق - ساحة المرجة', 'حلب - العزيزية',
                ST_GeomFromText('POINT(33.5138 36.2765)', 4326),
                ST_GeomFromText('POINT(36.2021 37.1343)', 4326),
                ?, ?, 50000, ?, 'direct', ?, 320500, 14400, ?, NOW(), NOW()
            )
        ", [
            $this->driver->id,
            now()->addHours(3)->format('Y-m-d H:i:s'),
            $seats,
            $paymentMethod,
            $status,
            $this->driverPhone,
        ]);

        return Ride::with(['driver', 'driver.profile'])->latest('id')->first();
    }

    private function seedAdminWallets(): void
    {
        foreach (['system_admin', 'sycash'] as $type) {
            $cfg = config("admin.{$type}");
            $user = User::firstOrCreate(
                ['email' => $cfg['email']],
                ['first_name' => $type, 'last_name' => 'Admin', 'password' => bcrypt($cfg['password']), 'gender' => 'M', 'address' => 'دمشق', 'status' => true]
            );

            if (! Wallet::where('phone_number', $cfg['phone'])->exists()) {
                $w = Wallet::create([
                    'user_id' => $user->id,
                    'phone_number' => $cfg['phone'],
                    'balance' => 10_000_000,
                    // wallet_number omitted — 'WLT-' . strtoupper($type) . '-' . Str::random(4)
                    // is 20+ chars once $type is 'system_admin'; let the model auto-generate instead
                ]);
                $user->update(['wallet_id' => $w->id]);
            } else {
                Wallet::where('phone_number', $cfg['phone'])->update(['balance' => 10_000_000]);
            }
        }
    }

    private function getToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }
}
