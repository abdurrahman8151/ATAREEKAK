<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Services\Ride\RideSearchService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * V3 — the route-buffer search strategy used to raise on every ride that carried a route.
 *
 * The finding (R1 sec 3 / sec 9, r2 sec 9) was recorded as "Route-buffer units (ST_Buffer)"
 * and the evidence line already said the real thing: "error 3618 on LINESTRING; strategy B
 * 500s". It was never fixed, so every one of these tests is a regression test for a live P0.
 *
 * Why it was reachable in production and not just in a test: `applyRouteMatching()` is an
 * `orWhere` on EVERY `searchRides()` call, and `route_geometry` is client-settable — it is
 * accepted by `CreateRideRequest` and persisted by `RideRepository`. So one ride saved with a
 * route made the whole ride-search endpoint return 500 for everyone, on every search.
 *
 * Root cause: `ST_Buffer` is not implemented for a LINESTRING in a GEOGRAPHIC reference system
 * (MySQL error 3618), and `ST_GeomFromGeoJSON()` returns SRID 4326 by default — so the original
 * `ST_Buffer(ST_GeomFromGeoJSON(...), degrees)` was always a geographic buffer. Relabelling the
 * parsed route to Cartesian with `ST_SRID(..., 0)` makes the buffer legal, and the radius stays
 * in degrees, which is the unit `rides.search.route_buffer_degrees` is configured in.
 *
 * The unit in the row title is now genuinely pinned rather than assumed: `route_buffer_degrees`
 * is consumed as degrees by the buffer, and a test below varies it and watches the reach move.
 */
class V3RouteBufferTest extends TestCase
{
    use RefreshDatabase;

    /** Damascus -> Aleppo, as GeoJSON: [lng, lat], exactly as RFC 7946 stores it. */
    private const ROUTE_DAMASCUS_ALEPPO = '{"type":"LineString","coordinates":[[36.2765,33.5138],[37.1343,36.2021]]}';

    /** Midpoint of that line is lng 36.70540, lat 34.85795; this point sits ~2 km off it. */
    private const NEAR_LAT = 34.8555;

    private const NEAR_LNG = 36.7245;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('V3 needs MySQL spatial semantics.');
        }
    }

    private function makeRoutedRide(string $routeGeoJson = self::ROUTE_DAMASCUS_ALEPPO): User
    {
        $driver = User::factory()->create();

        $ride = RideBuilder::for($driver)
            ->withAttributes([
                'available_seats' => 4,
                'price_per_seat' => 50000,
                'payment_method' => 'cash',
                'booking_type' => 'direct',
                'status' => 'active',
                'distance' => 320500,
                'duration' => 14400,
                'communication_number' => '0911000000',
            ])
            // Endpoints in Damascus/Aleppo proper: >140 km from the search points used below,
            // so strategy A can never satisfy the query and only strategy B can match.
            ->rawPickup('POINT(33.5138 36.2765)')
            ->rawDestination('POINT(36.2021 37.1343)')
            ->departureTime(now()->addDay()->toDateString().' 10:00:00')
            ->create();

        // Must be assigned as an ARRAY, not as a JSON string. Ride casts route_geometry to
        // `array`, so handing the mutator an already-encoded string stores a JSON *string*
        // ("{\"type\":...}") rather than a JSON object. The row then parses fine
        // (ST_GeomFromGeoJSON accepts the unquoted text) but JSON_EXTRACT(..., '$.coordinates')
        // returns NULL, which makes strategy B's own guard reject the ride. Production assigns a
        // real array (RideRepository:147), so the fixture has to match production here.
        $ride->forceFill(['route_geometry' => json_decode($routeGeoJson, true)])->save();

        return $driver;
    }

    private function search(float $lat, float $lng)
    {
        return app(RideSearchService::class)->searchRides([
            'departure_date' => now()->addDay()->toDateString(),
            'seats_required' => 1,
            'source_lat' => $lat,
            'source_lng' => $lng,
            'dest_lat' => $lat,
            'dest_lng' => $lng,
        ]);
    }

    public function test_a_search_does_not_raise_when_a_ride_carries_a_route(): void
    {
        $this->makeRoutedRide();

        // THE bug: this raised MySQL 3618 as a QueryException, which the HTTP layer turns into
        // a 500 for the entire search endpoint — not a missed match, a failed request.
        $results = $this->search(self::NEAR_LAT, self::NEAR_LNG);

        $this->assertCount(1, $results, 'a routed ride must not break ride search');
    }

    public function test_a_search_point_two_km_off_the_route_now_matches(): void
    {
        $this->makeRoutedRide();

        // Strategy B was "decorative at best" (the old test's words). It now works: the point
        // is ~2 km from the route and the configured buffer is 0.05 deg (~5.5 km).
        $this->assertCount(1, $this->search(self::NEAR_LAT, self::NEAR_LNG));
    }

    public function test_a_search_point_far_from_the_route_does_not_match(): void
    {
        $this->makeRoutedRide();

        // The denied path: a buffer that matches everything is not a filter.
        $this->assertCount(0, $this->search(35.5, 37.5));
    }

    public function test_the_transposed_probe_point_does_not_match(): void
    {
        $this->makeRoutedRide();

        // Guards the one lng-first string this codebase has. In Cartesian SRID 0 the ordinate
        // order is literal x,y, and GeoJSON is [lng, lat]; if someone "normalises" routeProbeWkt()
        // to lat-first for consistency with the 4326 columns, the comparison silently inverts and
        // every route stops matching. This asserts the inversion is detectable.
        $this->assertCount(0, $this->search(self::NEAR_LNG, self::NEAR_LAT));
    }

    public function test_the_buffer_is_consumed_in_degrees(): void
    {
        $this->makeRoutedRide();

        // With the buffer at 0.05 deg the ~2 km point matches. Shrink it below the actual
        // offset and the same point must stop matching — which can only happen if the radius
        // is being read in degrees, not silently interpreted as metres.
        Config::set('rides.search.route_buffer_degrees', 0.001);

        $this->assertCount(0, $this->search(self::NEAR_LAT, self::NEAR_LNG),
            'a 0.001 deg (~111 m) buffer must not contain a point ~2 km off the route');
    }

    public function test_a_long_route_is_not_truncated(): void
    {
        // 801 vertices (~12.7 kB of GeoJSON). An implementation that reassembles the line with
        // GROUP_CONCAT would silently lose everything past group_concat_max_len (1024 by
        // default) and this ride would stop matching its own start point.
        $points = [];
        for ($i = 0; $i <= 800; $i++) {
            $points[] = '['.(36.0 + $i * 0.001).','.(33.0 + $i * 0.001).']';
        }
        $long = '{"type":"LineString","coordinates":['.implode(',', $points).']}';

        $this->assertGreaterThan(4096, strlen($long), 'fixture must exceed group_concat_max_len');
        $this->makeRoutedRide($long);

        // Vertex #400 is exactly (lng 36.400, lat 33.400) — 80% of the way along the line,
        // far beyond any 1024-character truncation point.
        $this->assertCount(1, $this->search(33.400, 36.400));
    }

    public function test_endpoint_matching_still_works_for_a_ride_with_no_route(): void
    {
        $driver = User::factory()->create();
        RideBuilder::for($driver)
            ->withAttributes([
                'available_seats' => 4,
                'price_per_seat' => 50000,
                'payment_method' => 'cash',
                'booking_type' => 'direct',
                'status' => 'active',
                'communication_number' => '0911000000',
            ])
            ->rawPickup('POINT(33.5138 36.2765)')
            ->rawDestination('POINT(33.5200 36.2800)')
            ->departureTime(now()->addDay()->toDateString().' 10:00:00')
            ->create();

        // Strategy A, untouched by V3. The point is a few hundred metres from the endpoints.
        $this->assertCount(1, $this->search(33.5150, 36.2780));
    }

    public function test_the_original_expression_is_still_an_error_on_this_server(): void
    {
        // Proves the diagnosis rather than restating it: the exact expression that shipped is
        // still rejected, so the fix cannot be dismissed as "MySQL accepted it after all".
        $this->expectException(QueryException::class);

        DB::selectOne(
            "SELECT ST_Contains(
                ST_Buffer(ST_GeomFromGeoJSON(?), 0.05),
                ST_GeomFromText('POINT(36.7 34.8)', 4326)
            ) AS inside",
            [self::ROUTE_DAMASCUS_ALEPPO]
        );
    }
}
