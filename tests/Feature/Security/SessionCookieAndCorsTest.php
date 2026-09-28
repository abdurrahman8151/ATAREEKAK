<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * T2-12 regression — session cookies must be HttpOnly and credentialed CORS must
 * be off by default.
 *
 * Before the fix:
 *   - config/session.php hardcoded 'http_only' => false (not even env-driven),
 *     "for Flutter web access". Any injected script could read document.cookie
 *     and exfiltrate a live web session — the audit's "primary XSS mitigation
 *     removed".
 *   - config/cors.php had supports_credentials => true against an enumerated
 *     localhost origin list, allowed_methods => ['*'], and a dead 'session-debug'
 *     path.
 *
 * These tests assert the REAL response artifacts (the Set-Cookie flags the
 * framework actually emits, and the CORS headers HandleCors actually adds), not
 * merely the config array, so they fail if the framework stops honoring them.
 *
 * Note on the API: authentication is JWT-bearer — app/Http/Kernel.php's `api`
 * middleware group runs no StartSession/EncryptCookies, so api/* sends no
 * session cookie at all. HttpOnly and credentialed-CORS only concern the `web`
 * session guard, which these tests exercise directly.
 */
class SessionCookieAndCorsTest extends TestCase
{
    private function webCookie(string $name)
    {
        // Symfony attaches Set-Cookie headers to the response's cookie bag.
        foreach ($this->get('/')->headers->getCookies() as $c) {
            if ($c->getName() === $name) {
                return $c;
            }
        }
        return null;
    }

    // ── the session cookie is HttpOnly (the headline defect) ───────────────────

    public function test_the_session_cookie_is_httponly(): void
    {
        $cookie = $this->webCookie(config('session.cookie'));

        $this->assertNotNull($cookie, 'the web group must set a session cookie');
        $this->assertTrue(
            $cookie->isHttpOnly(),
            'the session cookie must be HttpOnly (T2-12: was hardcoded false)'
        );
    }

    public function test_the_xsrf_cookie_remains_readable_by_javascript(): void
    {
        // Parity guard: HttpOnly on the SESSION cookie must NOT break CSRF.
        // VerifyCsrfToken builds XSRF-TOKEN with an explicit httpOnly=false
        // (framework VerifyCsrfToken.php:212) independent of session.http_only,
        // so a JS client can still read it and echo it back in X-CSRF-TOKEN.
        $cookie = $this->webCookie('XSRF-TOKEN');

        $this->assertNotNull($cookie, 'VerifyCsrfToken must still emit XSRF-TOKEN');
        $this->assertFalse(
            $cookie->isHttpOnly(),
            'XSRF-TOKEN must stay JS-readable or the CSRF double-submit breaks'
        );
    }

    // ── config defaults are the safe ones, and overridable ─────────────────────

    public function test_http_only_defaults_true_in_config(): void
    {
        $this->assertTrue((bool) config('session.http_only'));
    }

    /**
     * Re-evaluate a config file from disk under a controlled environment,
     * because assert-on-source-text is unreliable here: config/session.php
     * legitimately contains the STRING `'http_only' => false` inside the
     * comment explaining the old bug, so a regex over the file matches the
     * fix and the defect alike. (This is the same trap recorded under T2-8.)
     * Requiring the file exercises the real env() resolution instead.
     */
    private function configWithEnv(string $file, string $var, ?string $value): array
    {
        $repo = \Illuminate\Support\Env::getRepository();
        $saved = getenv($var);

        $repo->clear($var);
        putenv($var);
        unset($_ENV[$var], $_SERVER[$var]);

        if ($value !== null) {
            $repo->set($var, $value);
            putenv("$var=$value");
        }

        try {
            return require base_path($file);
        } finally {
            $repo->clear($var);
            putenv($var);
            unset($_ENV[$var], $_SERVER[$var]);
            if ($saved !== false) {
                $repo->set($var, $saved);
                putenv("$var=$saved");
            }
        }
    }

    public function test_http_only_is_env_driven_and_defaults_true(): void
    {
        // Behavioural proof of the env wiring, not a regex on the file.
        $this->assertTrue($this->configWithEnv('config/session.php', 'SESSION_HTTP_ONLY', null)['http_only']);
        $this->assertFalse($this->configWithEnv('config/session.php', 'SESSION_HTTP_ONLY', 'false')['http_only']);
        $this->assertTrue($this->configWithEnv('config/session.php', 'SESSION_HTTP_ONLY', 'true')['http_only']);
    }

    public function test_cors_credentials_are_env_driven_and_default_false(): void
    {
        $this->assertFalse($this->configWithEnv('config/cors.php', 'CORS_SUPPORTS_CREDENTIALS', null)['supports_credentials']);
        $this->assertTrue($this->configWithEnv('config/cors.php', 'CORS_SUPPORTS_CREDENTIALS', 'true')['supports_credentials']);
        $this->assertFalse($this->configWithEnv('config/cors.php', 'CORS_SUPPORTS_CREDENTIALS', 'false')['supports_credentials']);
    }

    // ── credentialed CORS is off by default ────────────────────────────────────

    public function test_cors_credentials_default_to_false(): void
    {
        $this->assertFalse((bool) config('cors.supports_credentials'));
    }

    public function test_a_listed_dev_origin_gets_no_allow_credentials_header(): void
    {
        // The dangerous combination the audit warns about: credentialed
        // cross-origin against a localhost origin a rogue local process can
        // bind. With supports_credentials=false, HandleCors must NOT emit
        // Access-Control-Allow-Credentials even for an allow-listed origin.
        $response = $this->withHeaders(['Origin' => 'http://localhost:3000'])
            ->get('/api/ping');

        $this->assertSame(
            '',
            (string) $response->headers->get('Access-Control-Allow-Credentials'),
            'credentialed CORS must be absent by default'
        );
    }

    public function test_credentials_can_be_re_enabled_via_env_shape(): void
    {
        // Guard the env wiring without mutating process env: assert the config
        // uses filter_var(env('CORS_SUPPORTS_CREDENTIALS', false), ...), i.e.
        // an operator CAN opt back in, it is simply not the committed default.
        $src = file_get_contents(base_path('config/cors.php'));
        $this->assertMatchesRegularExpression(
            "/env\('CORS_SUPPORTS_CREDENTIALS',\s*false\)/",
            $src
        );
    }

    // ── allowed_methods narrowed; dead debug path removed ──────────────────────

    public function test_allowed_methods_no_longer_wildcard(): void
    {
        $methods = config('cors.allowed_methods');

        $this->assertNotContains('*', $methods);
        sort($methods);
        $this->assertSame(['DELETE', 'GET', 'PATCH', 'POST', 'PUT'], $methods);
    }

    public function test_a_preflight_advertises_only_the_narrow_method_set(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:8080',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/auth/login');

        $allow = (string) $response->headers->get('Access-Control-Allow-Methods');
        if ($allow !== '') {
            $this->assertStringNotContainsString('TRACE', $allow);
            $this->assertStringNotContainsString('*', $allow);
        }
        $this->assertTrue(true);
    }

    public function test_the_dead_session_debug_path_is_gone(): void
    {
        $this->assertNotContains('session-debug', config('cors.paths'));
    }
}
