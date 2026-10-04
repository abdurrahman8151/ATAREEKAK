<?php

namespace Tests\Feature\Review;

use App\Models\Photo;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * RV-14: two routes for ONE action must answer to the same rules.
 *
 * THE DEFECT. `POST /rides/create-with-route` carried its own inline rule set while `POST /rides`
 * used `CreateRideRequest`, and the two had DRIFTED apart in ways that mattered:
 *
 *   price_per_seat        CreateRideRequest min:100|max:100000   vs  inline min:0   (NO bound)
 *   communication_number  regex:/^09\d{8}$/                     vs  inline 'string' (no check)
 *   notes                 max:500                               vs  inline max:1000
 *
 * So the weaker endpoint was the live one: a ride could be created for a price of 0, or with
 * "banana" as the contact number. Two endpoints for one action, answering to different rules, is
 * precisely what RV-14 calls "lying endpoints".
 *
 * These tests pin the parity. The endpoint now injects `CreateRideRequest`, so a rule can only be
 * changed in one place - and if someone reintroduces an inline rule set, the parity test fails.
 */
class RideValidationParityTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private string $token;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();

        // The cash-ride path needs the platform wallets (the creation fee is routed to Primary
        // Admin). Declaring the trait is not enough - it has to be CALLED.
        $this->seedSystemWallets(10_000_000.0);

        $this->driver = User::factory()->create([
            'is_verified_driver' => true, 'is_verified_passenger' => true,
            'verification_status' => 'approved', 'password' => bcrypt('password123'),
        ]);
        if (! $this->driver->profile) {
            $this->driver->profile()->create(['full_name' => 'Parity Driver', 'number_of_rides' => 0]);
        }
        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $t) {
            Photo::firstOrCreate(
                ['user_id' => $this->driver->id, 'type' => $t],
                ['path' => "verifications/$t.jpg"],
            );
        }

        // A cash ride requires the driver to hold a wallet (a real, separate gate from validation -
        // "You must create a wallet before creating a cash ride"). Without it the valid-payload test
        // would fail for a reason that has nothing to do with the parity this file is pinning.
        Wallet::create([
            'user_id' => $this->driver->id,
            'phone_number' => '096'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.substr(bin2hex(random_bytes(5)), 0, 12),
            'balance' => 100_000,
        ]);
        $this->token = $this->postJson('/api/auth/login', [
            'email' => $this->driver->email, 'password' => 'password123',
        ])->json('tokens.access_token');
        $this->assertNotEmpty($this->token, 'driver login must yield a token');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }

    /** @test */
    public function create_with_route_is_the_documented_endpoint_and_exists(): void
    {
        $this->assertTrue(
            Route::has('api.rides.create-with-route') || collect(Route::getRoutes())->contains(
                fn ($r) => $r->uri() === 'api/rides/create-with-route'
            ),
            'the create-with-route endpoint must remain routed'
        );
    }

    /**
     * THE PIN. A price of 0 was ACCEPTED before (inline rule `min:0`); the shared request rejects it
     * (`min:100`). If an inline rule set is ever reintroduced, this fails.
     *
     * @test
     */
    public function create_with_route_rejects_a_zero_price(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/rides/create-with-route', $this->payload(['price_per_seat' => 0]))
            ->assertStatus(422);
    }

    /** @test */
    public function create_with_route_rejects_a_price_above_the_bound(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/rides/create-with-route', $this->payload(['price_per_seat' => 99_999_999]))
            ->assertStatus(422);
    }

    /**
     * The phone rule was `'string'` inline - so "banana" was accepted as a contact number. The
     * shared request enforces the 09######## format.
     *
     * @test
     */
    public function create_with_route_rejects_a_malformed_contact_number(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/rides/create-with-route', $this->payload(['communication_number' => 'banana']))
            ->assertStatus(422);
    }

    /** @test */
    public function create_with_route_accepts_a_valid_payload(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/rides/create-with-route', $this->payload());

        // Surface WHY on failure - this is the test that would catch the shared request being
        // stricter than a legitimate client payload.
        if ($response->status() !== 201) {
            $this->fail(
                'A valid create-with-route payload was rejected (HTTP '.$response->status().'): '
                .json_encode($response->json('errors') ?? $response->json())
            );
        }

        $this->assertDatabaseHas('rides', [
            'driver_id' => $this->driver->id,
            'pickup_address' => 'Damascus',
        ]);
    }
}
