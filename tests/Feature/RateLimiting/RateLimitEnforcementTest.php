<?php

namespace Tests\Feature\RateLimiting;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * T4-1 — rate limiting is now actually exercised end-to-end.
 *
 * Until T4-1, tests/TestCase.php called withoutMiddleware(ThrottleRequests::class)
 * for EVERY test, so a 429 could never fire under the suite: the whole throttle
 * layer (T2-3 OTP caps, T2-10 identity+IP buckets) was structurally untestable and
 * its enforcement was "verified" only by inspecting bucket KEYS, never by observing
 * a rejection. This suite is the missing proof: it drives real HTTP through the real
 * throttle middleware and asserts the actual 429, the Retry-After header, and the
 * per-bucket behaviour.
 *
 * It deliberately does NOT opt out of throttling — this is the one suite that needs
 * the middleware live. Cache is the array driver (reset per test), so each method
 * starts with an empty limiter store.
 */
class RateLimitEnforcementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Deliberately NO withMiddleware()/withoutMiddleware() call here. The
        // point of this suite is that throttling is ON BY DEFAULT now (T4-1
        // removed the global opt-out in tests/TestCase.php); re-enabling it by
        // hand would mask a regression if someone re-added the blind disable.
        // The array cache driver (phpunit.xml) gives every test method a fresh
        // limiter store, so no manual clearing is needed either.
    }

    public function test_the_suite_blind_spot_is_actually_gone(): void
    {
        // Pins the T4-1 mechanism itself: TestCase::setUp must NOT globally
        // disable ThrottleRequests, and phpunit must no longer exclude the
        // middleware layer from coverage. Both halves are what made the audit's
        // highest-severity classes invisible; this fails the moment either is
        // re-introduced.
        $case = (string) file_get_contents(base_path('tests/TestCase.php'));

        if (preg_match('/protected function setUp\(\): void\s*\{(.*?)\n    \}/s', $case, $m) === 1) {
            $this->assertStringNotContainsString(
                'withoutMiddleware',
                $m[1],
                'TestCase::setUp must not globally disable middleware again (T4-1)'
            );
        } else {
            $this->fail('could not locate TestCase::setUp to verify the blind spot is gone');
        }

        $xml = (string) file_get_contents(base_path('phpunit.xml'));
        $this->assertStringNotContainsString(
            '<directory>app/Http/Middleware</directory>',
            $xml,
            'middleware must stay inside the coverage boundary (T4-1)'
        );
    }

    /** A unique-but-well-formed email so this test never shares a bucket with another. */
    private function freshEmail(): string
    {
        return 'rl_' . uniqid() . '@example.test';
    }

    public function test_auth_endpoint_returns_429_once_the_account_limit_is_exceeded(): void
    {
        $limit = (int) config('rate-limiting.limits.auth');
        $this->assertGreaterThan(0, $limit, 'auth limit must be configured');

        $email = $this->freshEmail();
        $body  = ['email' => $email, 'password' => 'wrong-password-always'];

        // The configured number of attempts all get a non-429 (they may be 401/422
        // — we only care that they were NOT rate-limited).
        for ($i = 0; $i < $limit; $i++) {
            $r = $this->postJson('/api/auth/login', $body);
            $this->assertNotSame(
                429,
                $r->getStatusCode(),
                "attempt " . ($i + 1) . " of $limit must not be throttled"
            );
        }

        // Attempt limit+1 against the SAME account bucket must be throttled.
        $blocked = $this->postJson('/api/auth/login', $body);
        $blocked->assertStatus(429);
    }

    public function test_a_throttled_response_is_429_with_retry_after(): void
    {
        $limit = (int) config('rate-limiting.limits.auth');
        $email = $this->freshEmail();
        $body  = ['email' => $email, 'password' => 'x'];

        for ($i = 0; $i <= $limit; $i++) {
            $r = $this->postJson('/api/auth/login', $body);
        }

        $r->assertStatus(429);
        // ThrottleRequests THROWS on the limit-exceeded path (buildException), so
        // the only header on the 429 itself is Retry-After — the X-RateLimit-*
        // counters are added by addHeaders() on the success path, asserted next.
        // Asserting them on the 429 would be asserting behaviour the framework
        // does not have.
        $retry = $r->headers->get('Retry-After');
        $this->assertNotNull($retry, 'a 429 must carry Retry-After');
        $this->assertIsNumeric($retry);
        $this->assertGreaterThan(0, (int) $retry);
    }

    public function test_a_non_throttled_response_carries_the_rate_counters(): void
    {
        // Success path: addHeaders() sets X-RateLimit-Limit/-Remaining so clients
        // can self-regulate. This is the header contract that the global
        // withoutMiddleware() used to make unobservable.
        $limit = (int) config('rate-limiting.limits.auth');
        $r = $this->postJson('/api/auth/login', ['email' => $this->freshEmail(), 'password' => 'x']);

        $this->assertNotSame(429, $r->getStatusCode());
        $r->assertHeader('X-RateLimit-Limit', (string) $limit);
        $remaining = (int) $r->headers->get('X-RateLimit-Remaining');
        $this->assertGreaterThanOrEqual(0, $remaining);
        $this->assertLessThanOrEqual($limit - 1, $remaining, 'the request must have consumed one allowance');
    }

    public function test_distinct_accounts_do_not_share_the_strict_bucket(): void
    {
        // The T2-10 point: a shared IP must NOT let one account exhaust another's
        // allowance. Two different emails from the same test IP.
        $limit = (int) config('rate-limiting.limits.auth');

        $a = ['email' => $this->freshEmail(), 'password' => 'x'];
        $b = ['email' => $this->freshEmail(), 'password' => 'x'];

        // Drive account A exactly to its limit.
        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/auth/login', $a);
        }
        // A is now throttled:
        $this->postJson('/api/auth/login', $a)->assertStatus(429);

        // But B, same IP, must still have its full allowance (its own account
        // bucket). The looser IP backstop (limit x multiplier) is not exhausted.
        $this->assertNotSame(
            429,
            $this->postJson('/api/auth/login', $b)->getStatusCode(),
            'a second account on the same IP must not be starved by the first'
        );
    }

    public function test_throttle_can_be_disabled_by_the_master_toggle(): void
    {
        // Proves the enabled flag in the registered closure is live end-to-end:
        // re-register the limiters with the toggle off and confirm no 429 appears
        // even past the limit. We rebind via the provider like the real boot does.
        config(['rate-limiting.enabled' => false]);
        $provider = new \App\Providers\RouteServiceProvider($this->app);
        $ref = new \ReflectionMethod($provider, 'configureRateLimiting');
        $ref->setAccessible(true);
        $ref->invoke($provider);

        $email = $this->freshEmail();
        $body  = ['email' => $email, 'password' => 'x'];
        $limit = (int) config('rate-limiting.limits.auth');

        for ($i = 0; $i < $limit + 5; $i++) {
            $this->assertNotSame(429, $this->postJson('/api/auth/login', $body)->getStatusCode());
        }

        // restore for teardown hygiene
        config(['rate-limiting.enabled' => true]);
        $ref->invoke($provider);
    }
}
