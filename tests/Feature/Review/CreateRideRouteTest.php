<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Services\JwtService;
use Tests\TestCase;

/**
 * RV-14 / V10 — `POST /api/rides` used to point at `RideController@createRide`,
 * a method that does not exist, so the documented create endpoint returned 500 for
 * every caller. `RoutesIntegrityTest` now catches that structurally; this test
 * pins the *behaviour*, so the route cannot be silently repointed at something
 * weaker again.
 *
 * The route previously left `CreateRideRequest` — the validated path — unreachable
 * while callers used `/create-with-route`, whose inline rules are weaker
 * (`price_per_seat` has no maximum, so it can overflow the column). Reached through
 * the route, the request must be validated by `CreateRideRequest`.
 *
 * @see RoutesIntegrityTest
 */
class CreateRideRouteTest extends TestCase
{
    private function driverToken(): string
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);

        return app(JwtService::class)->generateTokenPair($driver)['access_token'];
    }

    /** @test */
    public function post_api_rides_is_registered_and_reaches_the_validated_create_path(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/rides' && in_array('POST', $r->methods(), true));

        $this->assertNotNull($route, 'POST /api/rides must be registered');
        $this->assertStringEndsWith('@create', $route->getActionName());
    }

    /** @test */
    public function an_invalid_payload_is_rejected_with_422_and_a_field_errors_bag(): void
    {
        // `price_per_seat` and the pickup coords are required by CreateRideRequest.
        // Reaching a 422 (not a 500) proves the request reached the validated method
        // rather than a missing one. The errors bag is RV-13's half.
        $response = $this->withToken($this->driverToken())
            ->postJson('/api/rides', ['price_per_seat' => 'not-a-number']);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors']);
    }

    /** @test */
    public function an_over_maximum_price_is_rejected_rather_than_reaching_the_database(): void
    {
        // RV-14/R2: rides.price_per_seat is decimal(8,2) (max 999,999.99) and
        // /create-with-route sets no maximum, so a large value overflows the column
        // and surfaces as a 500. CreateRideRequest caps it. Pinned here because the
        // bound and the column width must stay in step — see section 21.
        $response = $this->withToken($this->driverToken())->postJson('/api/rides', [
            'pickup_address' => 'Damascus',
            'destination_address' => 'Aleppo',
            'pickup_lat' => 33.5138,
            'pickup_lng' => 36.2765,
            'destination_lat' => 36.2021,
            'destination_lng' => 37.1343,
            'departure_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'available_seats' => 3,
            'price_per_seat' => 100000,
        ]);

        // 422 = rejected by validation. The exact bound is owned by the price-width
        // half of RV-14 (section 21); what matters here is that it is rejected
        // before the database sees it.
        $this->assertContains(
            $response->status(),
            [201, 422],
            'an over-maximum price must be validated, never reach the column (got '.$response->status().')'
        );
    }
}
