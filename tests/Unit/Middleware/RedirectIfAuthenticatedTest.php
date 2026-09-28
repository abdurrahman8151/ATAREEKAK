<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\RedirectIfAuthenticated;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * RedirectIfAuthenticatedTest — Unit tests for RedirectIfAuthenticated.
 *
 * COVERS:
 *   handle() — unauthenticated requests are forwarded to $next;
 *              authenticated users receive a 302 redirect to /home
 */
class RedirectIfAuthenticatedTest extends TestCase
{
    use RefreshDatabase;

    // ─── Instantiation ────────────────────────────────────────────────────────

    public function test_middleware_can_be_resolved_from_container(): void
    {
        $this->assertInstanceOf(
            RedirectIfAuthenticated::class,
            $this->app->make(RedirectIfAuthenticated::class)
        );
    }

    // ─── Unauthenticated pass-through ─────────────────────────────────────────

    public function test_unauthenticated_request_passes_through(): void
    {
        $called = false;

        (new RedirectIfAuthenticated)->handle(
            Request::create('/home', 'GET'),
            function () use (&$called) {
                $called = true;

                return response('ok');
            }
        );

        $this->assertTrue($called);
    }

    public function test_unauthenticated_request_returns_next_response(): void
    {
        $response = (new RedirectIfAuthenticated)->handle(
            Request::create('/home', 'GET'),
            fn () => response('passed', 200)
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_next_closure_receives_original_request(): void
    {
        $original = Request::create('/home', 'GET');
        $received = null;

        (new RedirectIfAuthenticated)->handle($original, function ($req) use (&$received) {
            $received = $req;

            return response('ok');
        });

        $this->assertSame($original, $received);
    }

    // ─── Authenticated redirect ───────────────────────────────────────────────

    public function test_authenticated_user_does_not_pass_through(): void
    {
        $user = User::factory()->create();
        Auth::login($user);

        $called = false;
        (new RedirectIfAuthenticated)->handle(
            Request::create('/home', 'GET'),
            function () use (&$called) {
                $called = true;

                return response('ok');
            }
        );

        $this->assertFalse($called);
    }

    public function test_authenticated_user_receives_302_redirect(): void
    {
        $user = User::factory()->create();
        Auth::login($user);

        $response = (new RedirectIfAuthenticated)->handle(
            Request::create('/home', 'GET'),
            fn () => response('ok')
        );

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function test_redirect_target_is_home_path(): void
    {
        $user = User::factory()->create();
        Auth::login($user);

        $response = (new RedirectIfAuthenticated)->handle(
            Request::create('/home', 'GET'),
            fn () => response('ok')
        );

        $this->assertStringContainsString('/home', $response->headers->get('Location', ''));
    }

    // ─── Explicit guard ───────────────────────────────────────────────────────

    public function test_unauthenticated_with_explicit_web_guard_passes_through(): void
    {
        $called = false;

        (new RedirectIfAuthenticated)->handle(
            Request::create('/home', 'GET'),
            function () use (&$called) {
                $called = true;

                return response('ok');
            },
            'web'
        );

        $this->assertTrue($called);
    }

    public function test_authenticated_with_explicit_web_guard_is_redirected(): void
    {
        $user = User::factory()->create();
        Auth::guard('web')->login($user);

        $called = false;
        (new RedirectIfAuthenticated)->handle(
            Request::create('/home', 'GET'),
            function () use (&$called) {
                $called = true;

                return response('ok');
            },
            'web'
        );

        $this->assertFalse($called);
    }
}
