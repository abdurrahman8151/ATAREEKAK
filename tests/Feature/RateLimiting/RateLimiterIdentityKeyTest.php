<?php

namespace Tests\Feature\RateLimiting;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * T2-10 regression — the named rate limiters must key public auth endpoints on
 * a stable account identity in addition to IP, and the duplicate config must be
 * gone.
 *
 * Before the fix every limiter resolved to `user()?->id ?: ip()`. On the public
 * groups (login, signup, OTP send/verify, password reset) no user is resolved,
 * so they keyed PURELY on IP. That is wrong two ways: an attacker rotating IPv6
 * source addresses got a fresh 5/min allowance each time (defeating the
 * per-account cap), while everyone behind one NAT/carrier gateway shared a
 * single strict bucket and throttled each other out.
 *
 * The suite calls the ACTUAL registered limiter closure (RateLimiter::limiter),
 * because tests/TestCase.php disables ThrottleRequests globally, so an
 * HTTP-level 429 assertion would never fire. Asserting the returned Limit
 * bucket keys is the faithful, deterministic instrument for this control.
 *
 * No real credential appears here — only synthetic emails/phones/ips.
 *
 * RV-37: two tests persist a factory user only to read its id for the bucket key.
 * Without a database trait those rows were COMMITTED, and every later count-style
 * assertion in the suite (AdminDriverServiceTest's aggregates) saw them.
 */
class RateLimiterIdentityKeyTest extends TestCase
{
    use RefreshDatabase;

    private function authPerMinute(): int
    {
        return (int) config('rate-limiting.limits.auth');
    }

    private function multiplier(): int
    {
        return max(1, (int) config('rate-limiting.ip_backstop_multiplier'));
    }

    /** @return array<int,array{key:string,max:int}> resolved buckets for a request */
    private function buckets(Request $request): array
    {
        $closure = RateLimiter::limiter('auth');
        $this->assertNotNull($closure, 'the auth limiter must be registered by RouteServiceProvider');

        $result = $closure($request);

        // Unlimited / single Limit / array of Limits are all possible shapes.
        if ($result instanceof Limit) {
            $result = [$result];
        }

        $out = [];
        foreach ($result as $limit) {
            $this->assertInstanceOf(Limit::class, $limit);
            $out[] = ['key' => $limit->key, 'max' => $limit->maxAttempts];
        }

        return $out;
    }

    private function requestWith(array $params, ?string $ip = '203.0.113.7', mixed $user = null): Request
    {
        $server = $ip !== null ? ['REMOTE_ADDR' => $ip] : [];
        $req = Request::create('/api/auth/login', 'POST', $params, [], [], $server);
        if ($user !== null) {
            $req->setUserResolver(fn () => $user);
        }

        return $req;
    }

    private function keys(array $buckets): array
    {
        return array_column($buckets, 'key');
    }

    // ── the per-account bucket exists and is stable across rotating IPs ─────────

    public function test_email_requests_get_a_per_account_bucket_alongside_the_ip_bucket(): void
    {
        $buckets = $this->buckets($this->requestWith(['email' => 'victim@example.com']));

        $keys = $this->keys($buckets);
        $this->assertContains('account:email:victim@example.com', $keys);
        $this->assertContains('ip:203.0.113.7', $keys);
        $this->assertCount(2, $buckets, 'an identified public request must hit two independent buckets');
    }

    public function test_the_same_email_from_different_ips_shares_one_account_bucket(): void
    {
        // This is THE vulnerability: pre-fix, a new source IP was a brand-new
        // bucket, so rotating addresses reset the allowance.
        $a = $this->keys($this->buckets($this->requestWith(['email' => 'victim@example.com'], '2001:db8::1')));
        $b = $this->keys($this->buckets($this->requestWith(['email' => 'victim@example.com'], '2001:db8::2')));

        $this->assertContains('account:email:victim@example.com', $a);
        $this->assertContains('account:email:victim@example.com', $b);
    }

    public function test_email_bucket_is_case_insensitive(): void
    {
        $mixed = $this->keys($this->buckets($this->requestWith(['email' => 'Victim@Example.COM'])));
        $lower = $this->keys($this->buckets($this->requestWith(['email' => 'victim@example.com'])));

        $this->assertContains('account:email:victim@example.com', $mixed);
        $this->assertContains('account:email:victim@example.com', $lower);
    }

    public function test_the_per_ip_bucket_is_looser_than_the_per_account_bucket(): void
    {
        $buckets = $this->buckets($this->requestWith(['email' => 'victim@example.com']));
        $byKey = [];
        foreach ($buckets as $b) {
            $byKey[$b['key']] = $b['max'];
        }

        $this->assertSame($this->authPerMinute(), $byKey['account:email:victim@example.com']);
        $this->assertSame(
            $this->authPerMinute() * $this->multiplier(),
            $byKey['ip:203.0.113.7'],
            'the shared-IP flood guard must be the higher ceiling, not the strict one'
        );
    }

    public function test_distinct_emails_from_one_ip_do_not_share_an_account_bucket(): void
    {
        // A busy gateway must not let one user starve another: each email keeps
        // its own strict bucket.
        $x = $this->keys($this->buckets($this->requestWith(['email' => 'a@example.com'], '198.51.100.1')));
        $y = $this->keys($this->buckets($this->requestWith(['email' => 'b@example.com'], '198.51.100.1')));

        $this->assertContains('account:email:a@example.com', $x);
        $this->assertContains('account:email:b@example.com', $y);
        $this->assertNotContains('account:email:b@example.com', $x);
    }

    // ── phone canonicalisation: same number, one bucket ────────────────────────

    public function test_phone_spellings_collapse_to_one_bucket(): void
    {
        $spellings = ['+963983337214', '963983337214', '0983337214', '00963983337214', '0983 337 214'];
        $last9 = substr(preg_replace('/\D/', '', '+963983337214'), -9);

        foreach ($spellings as $phone) {
            $keys = $this->keys($this->buckets($this->requestWith(['phone_number' => $phone])));
            $this->assertContains(
                'account:phone:'.$last9,
                $keys,
                "phone spelling '{$phone}' must land in the shared national-digit bucket"
            );
        }
    }

    public function test_phone_bucket_does_not_depend_on_the_country_code_spelling(): void
    {
        $plus = $this->keys($this->buckets($this->requestWith(['phone_number' => '+963911223344'])));
        $bare = $this->keys($this->buckets($this->requestWith(['phone_number' => '0911223344'])));

        $shared = array_intersect($plus, $bare);
        $hasPhone = false;
        foreach ($shared as $k) {
            if (str_starts_with($k, 'account:phone:')) {
                $hasPhone = true;
            }
        }
        $this->assertTrue($hasPhone, 'both spellings of the same subscriber must share one phone bucket');
    }

    // ── staff/admin identifier fields ──────────────────────────────────────────

    public function test_staff_login_identifier_gets_an_account_bucket(): void
    {
        $keys = $this->keys($this->buckets($this->requestWith(['identifier' => 'Admin_One'])));
        $this->assertContains('account:identifier:admin_one', $keys);
    }

    public function test_admin_username_gets_an_account_bucket(): void
    {
        $keys = $this->keys($this->buckets($this->requestWith(['username' => 'sysadmin'])));
        $this->assertContains('account:username:sysadmin', $keys);
    }

    // ── no identity: fall back to the plain IP bucket at the category limit ─────

    public function test_a_request_with_no_identity_uses_only_the_ip_bucket(): void
    {
        // /auth/refresh carries only a refresh token, no account field.
        $buckets = $this->buckets($this->requestWith(['refresh_token' => 'abc123']));

        $keys = $this->keys($buckets);
        $this->assertSame(['ip:203.0.113.7'], $keys);
        $this->assertSame($this->authPerMinute(), $buckets[0]['max']);
    }

    // ── authenticated path is unchanged in substance ───────────────────────────

    public function test_authenticated_request_keys_on_the_user_id(): void
    {
        $user = User::factory()->create();
        $keys = $this->keys($this->buckets($this->requestWith([], '203.0.113.7', $user)));

        $this->assertSame(['user:'.$user->getAuthIdentifier()], $keys);
    }

    public function test_authenticated_user_overrides_any_email_field(): void
    {
        // Even if an authenticated request posts an email, the identity is the
        // session/user, not the body — a logged-in caller must not be bucketed
        // against a third-party address.
        $user = User::factory()->create();
        $keys = $this->keys($this->buckets($this->requestWith(['email' => 'someone@example.com'], '1.2.3.4', $user)));

        $this->assertNotContains('account:email:someone@example.com', $keys);
        $this->assertSame(['user:'.$user->getAuthIdentifier()], $keys);
    }

    // ── the resolver must never throw on hostile/garbage input ──────────────────

    public function test_malformed_identifiers_do_not_throw_and_still_yield_buckets(): void
    {
        foreach ([
            ['email' => 'not-an-email'],
            ['email' => ''],
            ['email' => '@'],
            ['phone_number' => 'abc'],
            ['phone_number' => '1'],
            ['phone_number' => '!!!'],
            ['email' => 'x@y', 'phone_number' => 'garbage'],
        ] as $payload) {
            $buckets = $this->buckets($this->requestWith($payload));
            $this->assertNotEmpty($buckets, 'resolver must always return at least one bucket');
        }

        // An "email" that is not an email falls through to no-identity → IP only.
        $keys = $this->keys($this->buckets($this->requestWith(['email' => 'not-an-email'])));
        $this->assertSame(['ip:203.0.113.7'], $keys);
    }

    public function test_a_request_without_a_resolvable_ip_still_yields_a_bucket(): void
    {
        // CLI / forged-context safety: no REMOTE_ADDR must not crash the limiter.
        $req = Request::create('/api/auth/login', 'POST', ['email' => 'a@b.co']);
        $req->server->remove('REMOTE_ADDR');

        $buckets = $this->buckets($req);
        $this->assertNotEmpty($buckets);
    }

    // ── the master toggle is still honoured by the REAL closure ───────────────

    public function test_disabling_throttling_returns_unlimited(): void
    {
        // The registered closure captured $enabled at boot, so to exercise the
        // toggle we re-run the provider's actual configureRateLimiting() with
        // the config mutated — this re-binds `auth` through the real code path,
        // not a hand-copy of it.
        config(['rate-limiting.enabled' => false]);
        $this->rebindLimiters();

        $closure = RateLimiter::limiter('auth');
        $result = $closure($this->requestWith(['email' => 'a@b.co']));

        // Limit::none() returns an Unlimited marker consumed by the middleware.
        $this->assertInstanceOf(Unlimited::class, $result);

        config(['rate-limiting.enabled' => true]);
        $this->rebindLimiters();
    }

    private function rebindLimiters(): void
    {
        $provider = new RouteServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'configureRateLimiting');
        $method->setAccessible(true);
        $method->invoke($provider);
    }

    // ── the duplicate config file is gone ──────────────────────────────────────

    public function test_the_duplicate_underscore_config_no_longer_exists(): void
    {
        $this->assertFileDoesNotExist(
            base_path('config/rate_limiting.php'),
            'the byte-identical unused duplicate must be removed (single source of truth)'
        );
        $this->assertFileExists(base_path('config/rate-limiting.php'));
    }

    public function test_the_loaded_config_matches_the_hyphen_file_the_app_reads(): void
    {
        // The app reads `rate-limiting`; prove the identity multiplier really
        // came from that file and defaults to a sane value.
        $this->assertGreaterThanOrEqual(1, config('rate-limiting.ip_backstop_multiplier'));
        $this->assertArrayHasKey('auth', config('rate-limiting.limits'));
    }

    protected function tearDown(): void
    {
        // Restore defaults mutated by the disabled-toggle test.
        config(['rate-limiting.enabled' => true]);
        parent::tearDown();
    }
}
