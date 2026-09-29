<?php

namespace Tests\Feature\Review;

use App\Http\Controllers\API\RideController;
use App\Http\Middleware\TrustProxies;
use App\Models\Ride;
use App\Models\User;
use App\Services\JwtService;
use App\Services\Ride\RideSearchService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * APP_FUTURE_SONNET Wave 0 — the read-only V1..V10 checks, executed and
 * PINNED as passing records of current truth.
 *
 * These tests record, not aspire: each asserts what the system DOES today.
 * When the corresponding RV-nn fix lands, the recording test is updated to
 * the fixed truth — that update IS part of that task's verification, never a
 * quiet weakening (this file's rules: new tests only, no existing test edited
 * to pass).
 */
class WaveZeroVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** Damascus (33.5138, 36.2765) -> Aleppo (36.2021, 37.1343): ~309 km. */
    public function test_v1_axis_order_is_transposed_under_srid_4326(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('V1 needs MySQL spatial semantics.');
        }

        $asWritten = (float) DB::selectOne(
            "SELECT ST_Distance_Sphere(
                ST_GeomFromText('POINT(36.2765 33.5138)', 4326),
                ST_GeomFromText('POINT(37.1343 36.2021)', 4326)) / 1000 AS km"
        )->km;

        $correct = (float) DB::selectOne(
            "SELECT ST_Distance_Sphere(
                ST_GeomFromText('POINT(33.5138 36.2765)', 4326),
                ST_GeomFromText('POINT(36.2021 37.1343)', 4326)) / 1000 AS km"
        )->km;

        // Recorded (MySQL 8.2): lng-first as the code writes = 258 km; the
        // real city distance ~309 km only appears when 4326 receives lat-first
        // (or when axis-order=long-lat is requested). Every stored geometry
        // and every query in this app uses lng-first + SRID 4326, so distances
        // are systematically wrong AND maps place cities in the wrong country.
        $this->assertEqualsWithDelta(258, $asWritten, 2);
        $this->assertEqualsWithDelta(309, $correct, 2);
        $this->assertGreaterThan(40, $correct - $asWritten);
    }

    public function test_v2_rides_has_no_spatial_index(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('V2 reads MySQL information_schema.');
        }

        $spatial = DB::select(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rides'
               AND INDEX_TYPE = 'SPATIAL'"
        );

        // The audit's "2 spatial indexes" is stale: migration
        // 2025_05_20_143208 dropped the point() columns (taking their spatial
        // indexes with them) and recreated plain geometry columns; nothing
        // re-added an index. RV-25's cost claims change accordingly.
        $this->assertCount(0, $spatial);
    }

    public function test_v3_route_buffer_strategy_cannot_match_a_point_2km_off_the_line(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('V3 needs MySQL spatial semantics.');
        }

        $driver = User::factory()->create();
        $date = now()->addDays(2)->toDateString();

        // Raw insert: the spatial columns are NOT NULL without defaults and
        // bypass fill() by design (mutators own geometry). Endpoints are the
        // real city coords; the ride is >140 km from the SEARCH points, so
        // only strategy B (route proximity) could ever match it.
        // RV-34: shared builder. THE GEOMETRY HERE IS DELIBERATELY TRANSPOSED
        // (lng-first): this is the V1/V3 fixture whose route geometry is what makes
        // ST_Buffer(LINESTRING) fail on MySQL 8 (error 3618). Preserved verbatim —
        // the transposition is finding V1, owned by RV-25.
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
            ->rawPickup('POINT(36.2765 33.5138)')
            ->rawDestination('POINT(37.1343 36.2021)')
            ->departureTime($date.' 10:00:00')
            ->create();

        // Route polyline straight through the search area (the midpoint sits
        // ~2 km from the search points below).
        $line = '{"type":"LineString","coordinates":[[36.2765,33.5138],[37.1343,36.2021]]}';
        $ride->forceFill(['route_geometry' => $line])->save();

        // Search endpoints near the LINE midpoint (~36.705/34.858), pushed ~2 km
        // off it; ride endpoints are >140 km away so A is impossible.
        $svc = app(RideSearchService::class);

        try {
            $results = $svc->searchRides([
                'departure_date' => $date,
                'seats_required' => 1,
                'source_lat' => 34.8555, 'source_lng' => 36.7245, // ~2 km off, on neither endpoint
                'dest_lat' => 34.8555, 'dest_lng' => 36.7245,
            ]);
        } catch (\Throwable $e) {
            // Recording outcome 2 of 2 (the ACTUAL one, settled via raw probe):
            // MySQL 8.2 refuses ST_Buffer on LINESTRING at all (error 3618,
            // "not been implemented"), so strategy B never matches — it raises,
            // and any ride WITH route_geometry breaks the whole search with a
            // 500. (Settled by wave-0 probe; either way RV-25 is confirmed.)
            $this->assertInstanceOf(QueryException::class, $e);

            return;
        }

        // Recording outcome 1: no error, but the 2 km-off point does NOT match,
        // i.e. 0.05 is not read as ~5 km as documented (meters, or SRID rules
        // silently excluding it). Strategy B is decorative at best.
        $this->assertCount(
            0,
            $results,
            'V3: strategy B is expected NOT to match by design intent — if this '
            .'fails, the buffer works as documented and RV-25 must be re-scoped.'
        );
    }

    public function test_v4_trust_proxies_null_makes_every_client_share_one_ip_bucket(): void
    {
        $default = (new ReflectionProperty(TrustProxies::class, 'proxies'))->getDefaultValue();
        $this->assertNull($default, 'RV-05 premise: $proxies unset');

        // Exactly what arrives at RoadRunner through the compose nginx:
        // REMOTE_ADDR = the nginx container, XFF = the real client.
        $request = Request::create('/api/ping', 'GET', [], [], [], [
            'REMOTE_ADDR' => '172.18.0.42',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 70.41.3.18',
        ]);

        // With no trusted proxies, Symfony ignores XFF: ->ip() is the proxy.
        // RateServiceProvider's ip: buckets therefore key on the NGINX CONTAINER
        // IP for every request behind the LB — one global bucket (RV-05 CONFIRMED
        // for the "collapses" half). The spoofed left-most XFF is NOT honoured
        // (the "trust too wide" half is refuted — clients cannot forge the key).
        $this->assertSame('172.18.0.42', $request->ip());
        $this->assertNotSame('203.0.113.9', $request->ip());
    }

    public function test_v5_validation_errors_are_stripped_from_the_api_envelope(): void
    {
        $user = User::factory()->create();
        $token = app(JwtService::class)->generateTokenPair($user)['access_token'];

        $response = $this->withToken($token)->postJson('/api/wallet/request-charge', [
            'amount' => 0, // fails min:1 in WalletChargeRequest
        ]);

        $response->assertStatus(422);
        // Recorded current truth (APP_DEBUG=true in tests): the message is the
        // raw validation text and the ERRORS BAG IS GONE — clients cannot show
        // field-level errors (RV-13 half 1 CONFIRMED). With debug off the
        // message becomes the generic string while the status stays 422.
        // Recorded current truth (APP_DEBUG=true in tests): the FormRequest's
        // field-level `errors` bag is STRIPPED by the catch-all Handler and only
        // a generic message/code reaches the client (RV-13 half 1 CONFIRMED).
        $json = $response->json();
        $this->assertTrue(
            ! array_key_exists('errors', $json),
            'V5: errors bag currently absent from the 422 envelope'
        );
        $this->assertArrayHasKey('message', $json);
        $this->assertSame(422, $json['code'] ?? $response->status());
    }

    public function test_v6_the_committed_phpunit_xml_pins_ci_to_sqlite(): void
    {
        // 2>NUL is invalid on this platform for shell_exec; merge instead and
        // treat a "fatal" reply as "git unavailable".
        $committed = (string) shell_exec('git show HEAD:phpunit.xml 2>&1');
        if (str_contains($committed, 'fatal') || $committed === '') {
            $this->markTestSkipped('git not available in this environment.');
        }

        // PHPUnit <php> env vars are set BEFORE the app reads .env, and without
        // force="true" they still win over an already-set process env — the
        // workflows provision MySQL that nothing uses (RV-18 CONFIRMED).
        $this->assertStringContainsString('name="DB_CONNECTION"', $committed);
        $this->assertMatchesRegularExpression('/DB_CONNECTION"\s+value="sqlite"/', $committed);
        $this->assertMatchesRegularExpression('/DB_DATABASE"\s+value=":memory:"/', $committed);
    }

    public function test_v8_system_admin_config_is_missing_so_revenue_is_always_zero(): void
    {
        $this->assertNull(config('system_admin.phone'), 'V8: no config/system_admin.php exists');

        // And the two production call sites depend on it:
        $report = (string) file_get_contents(app_path('Services/Admin/AdminReportService.php'));
        $verify = (string) file_get_contents(app_path('Repositories/VerificationRepository.php'));
        $this->assertStringContainsString("config('system_admin.phone')", $report);
        $this->assertStringContainsString('system_admin', $verify);
    }

    public function test_v9_user_ratings_unique_blocks_per_ride_ratings(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('V9 indexes are MySQL/committed-schema specific.');
        }

        $unique = DB::select(
            "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_ratings' AND NON_UNIQUE = 0
             GROUP BY INDEX_NAME"
        );

        $hasPair = collect($unique)->contains(fn ($i) => str_contains($i->cols ?? '', 'rater_id') && str_contains($i->cols ?? '', 'rated_user_id'));
        $this->assertTrue(
            $hasPair,
            'V9: unique(rater_id, rated_user_id) still present — one rating per PAIR, '
            .'so "one rating per ride" (RV-30) requires the ride_id column to enter that index.'
        );
    }

    public function test_v10_post_rides_routes_to_a_nonexistent_method(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/rides' && in_array('POST', $r->methods(), true));

        $this->assertNotNull($route, 'POST /api/rides must exist for this check');

        $action = $route->getAction('uses');
        $this->assertIsString($action, 'expected Controller@method action');

        [$class, $method] = explode('@', $action);
        $this->assertSame(RideController::class, $class);

        // Recorded: the route targets createRide(), which does not exist (the
        // method is create()). Every call is a 500 BadMethodCallException and
        // the validated CreateRideRequest path is unreachable (RV-14 CONFIRMED).
        $this->assertFalse(
            method_exists($class, $method),
            "V10 expected {$class}::{$method}() to be missing — if it exists now, "
            .'RV-14 has landed: update this record to assert it resolves.'
        );
        $this->assertTrue(method_exists(RideController::class, 'create'));
    }
}
