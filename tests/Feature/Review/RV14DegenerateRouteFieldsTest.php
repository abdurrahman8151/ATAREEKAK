<?php

namespace Tests\Feature\Review;

use App\Models\Photo;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * RV-14 - the `??`-inside-the-`empty()`-guard quirk in `createRideWithRoute`.
 *
 * The guard that decides whether to ask the routing service for a route tested `empty()`:
 *
 *     if (empty($validated['route_geometry']) || empty($validated['distance']) || empty($validated['duration'])) {
 *
 * but the three fills INSIDE that guard used `??`, which only replaces null/absent. The two tests
 * disagree on exactly the degenerate values, and all three are reachable through
 * `CreateRideRequest` (`distance`/`duration` are `numeric|min:0`, so 0 passes; `route_geometry` is
 * `array`, so [] passes):
 *
 *   distance        = 0     empty()=true   ??=false   -> keeps the client's 0
 *   duration        = 0     empty()=true   ??=false   -> keeps the client's 0
 *   route_geometry  = []    empty()=true   ??=false   -> keeps the client's []
 *
 * So a client could get a real server-computed GEOMETRY stored beside a distance of 0 - a ride that
 * draws a line on the map and reports itself as zero-length. Latent, because the Flutter client
 * sends none of the three (it derives distance/duration in metres itself and sends no geometry), so
 * nothing in production tripped it; but the endpoint is public and accepts it.
 *
 * D6 = B is already answered and corroborated from the Flutter client (`R2 sec 85`): server-derived
 * metres are authoritative, so the fix is to make the fill test the same thing the guard tests.
 *
 * @see RideValidationParityTest  (the sibling pin for this endpoint's validation rules)
 */
class RV14DegenerateRouteFieldsTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    /**
     * Integers on purpose: `rides.distance` is a rounded decimal column, so a value like 12345.6
     * comes back as 12346.0 and a tight delta would fail for a reason unrelated to this file.
     * What matters is server-vs-client, and 0 vs 12000 says that unambiguously.
     */
    private const SERVER_DISTANCE = 12000;

    private const SERVER_DURATION = 900;

    private const SERVER_GEOMETRY = [[36.2765, 33.5138], [37.1343, 36.2021]];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // The cash-ride path needs the platform wallets (the creation fee is routed to Primary
        // Admin), and a cash ride requires the driver to hold a wallet of their own. Both are
        // fixtures, not the subject of this file.
        $this->seedSystemWallets(10_000_000.0);

        $driver = User::factory()->create([
            'is_verified_driver' => true, 'is_verified_passenger' => true,
            'verification_status' => 'approved', 'password' => bcrypt('password123'),
        ]);
        if (! $driver->profile) {
            $driver->profile()->create(['full_name' => 'RV14 Driver', 'number_of_rides' => 0]);
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

    /**
     * `RouteCalculationService` is `final`, so it cannot be mocked; instead the upstream HTTP call
     * is faked. That is BETTER here - the real service runs, and the distance/duration/geometry the
     * controller receives are exactly the ones declared below.
     */
    private function fakeRouting(): void
    {
        Http::fake([
            '*' => Http::response([
                'routes' => [[
                    'summary' => ['distance' => self::SERVER_DISTANCE, 'duration' => self::SERVER_DURATION],
                    'geometry' => ['coordinates' => self::SERVER_GEOMETRY],
                ]],
            ], 200),
        ]);
    }

    private function createRide(array $overrides): Ride
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/rides/create-with-route', $this->payload($overrides));

        $response->assertStatus(201);

        return Ride::query()->latest('id')->firstOrFail();
    }

    /**
     * THE PIN. distance=0 / duration=0 / route_geometry=[] all pass validation, all trip the
     * guard, and all survived the `??` fill. The server's route must win for every one of them,
     * otherwise the ride stores a real geometry beside a zero distance.
     *
     * @test
     */
    public function a_zero_distance_from_the_client_is_replaced_by_the_server_route(): void
    {
        $this->fakeRouting();

        $ride = $this->createRide([
            'distance' => 0,
            'duration' => 0,
            'route_geometry' => [],
        ]);

        $this->assertEqualsWithDelta(
            self::SERVER_DISTANCE, (float) $ride->distance, 0.001,
            'a client distance of 0 must not be stored beside a server-derived geometry'
        );
        $this->assertEqualsWithDelta(
            self::SERVER_DURATION, (float) $ride->duration, 0.001,
            'a client duration of 0 must not survive the guard that was meant to replace it'
        );
        $this->assertSame('LineString', $ride->route_geometry['type'] ?? null);
        $this->assertSame(self::SERVER_GEOMETRY, $ride->route_geometry['coordinates'] ?? null);
    }

    /**
     * THE COMPLEMENT, and the reason the fix is `empty()` and not "always overwrite": a client that
     * supplies all three values must still keep them, and the routing service must not be called at
     * all - which is also what proves the guard still works rather than having been widened.
     *
     * @test
     */
    public function complete_client_route_data_is_kept_and_the_routing_service_is_not_called(): void
    {
        Http::fake();

        $ride = $this->createRide([
            'distance' => 4200,
            'duration' => 300,
            'route_geometry' => ['type' => 'LineString', 'coordinates' => [[1.5, 2.5], [3.5, 4.5]]],
        ]);

        $this->assertEqualsWithDelta(4200, (float) $ride->distance, 0.001);
        $this->assertEqualsWithDelta(300, (float) $ride->duration, 0.001);
        $this->assertSame([[1.5, 2.5], [3.5, 4.5]], $ride->route_geometry['coordinates'] ?? null);

        // No outbound route request = getRouteDetails was never entered = the guard was not
        // entered, because the client supplied all three values.
        Http::assertNothingSent();
    }

    /**
     * The third degenerate value on its own. `route_geometry => []` is legal (`nullable|array`) and
     * survives `??`, so the ride would have kept an EMPTY geometry while still being given the
     * server's distance - the inverse of the case above, and just as broken.
     *
     * @test
     */
    public function an_empty_client_geometry_is_replaced_by_the_server_geometry(): void
    {
        $this->fakeRouting();

        $ride = $this->createRide(['route_geometry' => []]);

        $this->assertSame('LineString', $ride->route_geometry['type'] ?? null);
        $this->assertSame(self::SERVER_GEOMETRY, $ride->route_geometry['coordinates'] ?? null);
    }
}
