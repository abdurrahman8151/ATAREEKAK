<?php

namespace Tests\Feature\Review;

use App\Models\Photo;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * RV-55 regression guard.
 *
 * POST /api/rides/create-with-route with NO distance/duration must never store null in the
 * NOT NULL rides.distance / rides.duration columns (SQLSTATE 1048), even when the routing
 * service is down and the server must fall back to its own estimate.
 */
class RV55NoRouteDataGuardTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSystemWallets(10_000_000.0);

        $driver = User::factory()->create([
            'is_verified_driver' => true, 'is_verified_passenger' => true,
            'verification_status' => 'approved', 'password' => bcrypt('password123'),
        ]);
        if (! $driver->profile) {
            $driver->profile()->create(['full_name' => 'RV55 Driver', 'number_of_rides' => 0]);
        }
        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $t) {
            Photo::firstOrCreate(
                ['user_id' => $driver->id, 'type' => $t],
                ['path' => "verifications/$t.jpg"],
            );
        }
        Wallet::create([
            'user_id' => $driver->id,
            'phone_number' => '096'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.substr(bin2hex(random_bytes(5)), 0, 12),
            'balance' => 100_000,
        ]);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => $driver->email, 'password' => 'password123',
        ])->json('tokens.access_token');

        $this->assertNotEmpty($this->token, 'driver login must yield a token');
    }

    private function payload(): array
    {
        return [
            'pickup_lat' => 33.5138, 'pickup_lng' => 36.2765,
            'destination_lat' => 36.2021, 'destination_lng' => 37.1343,
            'pickup_address' => 'Damascus', 'destination_address' => 'Aleppo',
            'departure_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'available_seats' => 3,
            'price_per_seat' => 5000,
            'vehicle_type' => 'sedan',
            'payment_method' => 'cash',
            'booking_type' => 'direct',
            'communication_number' => '0912345678',
        ];
    }

    /** @test */
    public function create_without_route_data_and_routing_down_stores_a_real_distance_and_duration(): void
    {
        Http::fake(['*' => Http::response('upstream down', 503)]);

        $this->withToken($this->token)
            ->postJson('/api/rides/create-with-route', $this->payload())
            ->assertStatus(201);

        $ride = Ride::query()->latest('id')->firstOrFail();

        $this->assertNotNull($ride->getRawOriginal('distance'), 'distance must never be null (SQLSTATE 1048)');
        $this->assertNotNull($ride->getRawOriginal('duration'), 'duration must never be null (SQLSTATE 1048)');
        $this->assertGreaterThan(0, (float) $ride->getRawOriginal('distance'));
        $this->assertGreaterThan(0, (float) $ride->getRawOriginal('duration'));
    }

    /**
     * The finding names POST /api/rides (RideController::create, the validated path), not
     * /create-with-route. Probe the endpoint it actually names.
     *
     * @test
     */
    public function post_api_rides_without_route_data_never_stores_null(): void
    {
        Http::fake(['*' => Http::response('upstream down', 503)]);

        $this->withToken($this->token)
            ->postJson('/api/rides', $this->payload())
            ->assertStatus(201);

        $ride = Ride::query()->latest('id')->firstOrFail();

        $this->assertNotNull($ride->getRawOriginal('distance'), 'distance must never be null (SQLSTATE 1048)');
        $this->assertNotNull($ride->getRawOriginal('duration'), 'duration must never be null (SQLSTATE 1048)');
        $this->assertGreaterThan(0, (float) $ride->getRawOriginal('distance'));
        $this->assertGreaterThan(0, (float) $ride->getRawOriginal('duration'));
    }
}
