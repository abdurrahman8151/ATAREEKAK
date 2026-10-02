<?php

namespace Tests\Unit\Providers;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Tests\TestCase;

/**
 * RouteServiceProviderTest — Unit tests for RouteServiceProvider.
 *
 * COVERS:
 *   HOME constant
 *   boot() — rate limiter registration and route file loading
 *   API routes registered with /api prefix
 *   Rate limiter closure — returns Limit, keys by user ID or IP
 *
 * RV-37: the "keys by user id" test persists a factory user to read its id, so it
 * needs `RefreshDatabase`; without it that row was COMMITTED to the scratch database
 * and every later count-style assertion in the suite saw a phantom user.
 */
class RouteServiceProviderTest extends TestCase
{
    use RefreshDatabase;
    // ─── HOME constant ────────────────────────────────────────────────────────

    public function test_home_constant_equals_slash_home(): void
    {
        $this->assertEquals('/home', RouteServiceProvider::HOME);
    }

    public function test_home_constant_is_a_string(): void
    {
        $this->assertIsString(RouteServiceProvider::HOME);
    }

    // ─── Provider registration ────────────────────────────────────────────────

    public function test_provider_is_registered_in_the_application(): void
    {
        $provider = $this->app->getProvider(RouteServiceProvider::class);
        $this->assertNotNull($provider);
        $this->assertInstanceOf(RouteServiceProvider::class, $provider);
    }

    public function test_provider_extends_service_provider(): void
    {
        $this->assertTrue(
            is_subclass_of(RouteServiceProvider::class, ServiceProvider::class)
        );
    }

    // ─── Route loading ────────────────────────────────────────────────────────

    public function test_api_routes_are_registered_with_api_prefix(): void
    {
        $hasApiRoute = collect($this->app['router']->getRoutes()->getRoutes())
            ->contains(fn ($r) => str_starts_with($r->uri(), 'api/'));

        $this->assertTrue($hasApiRoute);
    }

    public function test_auth_login_route_is_registered(): void
    {
        $route = collect($this->app['router']->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/auth/login');

        $this->assertNotNull($route, 'Expected api/auth/login route to be registered');
    }

    public function test_router_is_resolvable_from_container(): void
    {
        $this->assertInstanceOf(Router::class, $this->app->make(Router::class));
    }

    // ─── Rate limiter ─────────────────────────────────────────────────────────

    public function test_api_rate_limiter_is_registered(): void
    {
        $this->assertIsCallable(RateLimiter::limiter('api'));
    }

    public function test_api_rate_limiter_returns_limit_instance(): void
    {
        $limiter = RateLimiter::limiter('api');
        $request = Request::create('/api/test', 'GET');

        $this->assertInstanceOf(Limit::class, $limiter($request));
    }

    public function test_api_rate_limiter_allows_60_requests_per_minute(): void
    {
        $limiter = RateLimiter::limiter('api');
        $request = Request::create('/api/test', 'GET');

        $limit = $limiter($request);

        $this->assertEquals(60, $limit->maxAttempts);
    }

    public function test_api_rate_limiter_keys_by_user_id_when_authenticated(): void
    {
        $user = User::factory()->create();
        $limiter = RateLimiter::limiter('api');

        $request = Request::create('/api/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $limit = $limiter($request);

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertStringContainsString((string) $user->id, $limit->key);
    }

    public function test_api_rate_limiter_keys_by_ip_when_not_authenticated(): void
    {
        $limiter = RateLimiter::limiter('api');
        $request = Request::create('/api/test', 'GET');
        $request->server->set('REMOTE_ADDR', '10.0.0.1');

        $limit = $limiter($request);

        $this->assertStringContainsString('10.0.0.1', $limit->key);
    }
}
