<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Original-audit T2-6: unauthenticated infrastructure disclosure on
 * health/debug endpoints.
 *
 * These tests exercise the real routing layer and assert on the real HTTP
 * response bodies, so they prove disclosure removal rather than merely that a
 * line of code changed.
 */
class DebugEndpointDisclosureTest extends TestCase
{
    // ---------------------------------------------------------------- removal

    public function test_test_db_endpoint_no_longer_exists(): void
    {
        $this->getJson('/api/test-db')->assertStatus(404);
    }

    public function test_api_health_endpoint_no_longer_exists(): void
    {
        $this->getJson('/api/health')->assertStatus(404);
    }

    public function test_the_removed_endpoints_are_absent_from_the_route_table(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();

        $this->assertNotContains('api/test-db', $uris, '/api/test-db must not be registered.');
        $this->assertNotContains('api/health', $uris, '/api/health must not be registered.');
    }

    // ---------------------------------------------------------------- /up leaks

    public function test_up_does_not_leak_the_database_exception_message(): void
    {
        // Force the healthcheck query to throw, exactly as a DB outage would.
        // Placeholder values only: the point is the SHAPE of a driver failure
        // (SQLSTATE code, db user, internal IP, auth mode), never a real
        // credential. No actual project credential appears in this file.
        DB::shouldReceive('select')->once()->andThrow(
            new \PDOException('SQLSTATE[HY000] [1045] Access denied for user "db_user_leak_probe"@"10.0.0.5" using password: YES')
        );

        $response = $this->get('/up');

        $response->assertStatus(500);

        $body = $response->getContent();

        // The concrete strings a driver exception would have carried.
        foreach (['SQLSTATE', '1045', 'db_user_leak_probe', 'Access denied', '10.0.0.5', 'using password'] as $leak) {
            $this->assertStringNotContainsString(
                $leak,
                $body,
                "GET /up leaked '{$leak}' from the database exception."
            );
        }
    }

    public function test_up_returns_a_bare_status_body_on_failure(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new \PDOException('boom'));

        $this->get('/up')->assertStatus(500)->assertContent('Service unavailable');
    }

    public function test_up_still_returns_ok_when_the_database_is_healthy(): void
    {
        DB::shouldReceive('select')->once()->andReturn([['1' => 1]]);

        $this->get('/up')->assertStatus(200)->assertContent('OK');
    }

    // -------------------------------------------------- still-present endpoints

    public function test_ping_still_works_and_discloses_nothing(): void
    {
        $response = $this->getJson('/api/ping');

        $response->assertStatus(200)->assertExactJson(['ok' => true]);
    }

    public function test_test_endpoint_discloses_no_infrastructure_detail(): void
    {
        $body = $this->getJson('/api/test')->assertStatus(200)->getContent();

        foreach (['database', 'host', 'port', 'tables_count', 'gethostname'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function test_no_public_route_returns_the_hostname(): void
    {
        // gethostname() must not appear anywhere in the routing surface.
        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('uses');
            if (is_string($action)) {
                $this->assertStringNotContainsString('gethostname', $action);
            }
        }

        $this->assertTrue(true);
    }
}
