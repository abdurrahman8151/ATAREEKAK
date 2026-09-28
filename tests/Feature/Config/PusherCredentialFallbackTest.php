<?php

namespace Tests\Feature\Config;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * T2-8 regression — Pusher credentials must not exist as in-code fallbacks,
 * and a production boot must hard-fail if broadcasting is enabled without them.
 *
 * Before the fix, config/broadcasting.php read:
 *     'key'    => env('PUSHER_APP_KEY',    '<literal>'),
 *     'secret' => env('PUSHER_APP_SECRET', '<literal>'),
 *     'app_id' => env('PUSHER_APP_ID',     '<literal>'),
 * in a file committed to a PUBLIC repository. Two independent problems: the
 * credential material (including the server-only Pusher secret) lived in
 * version control, and a deploy that lost its env silently kept running on
 * that shared credential instead of failing. The fix removes the defaults and
 * makes such a deploy refuse to boot.
 *
 * No real credential value appears in this file: assertions are about
 * emptiness and about the presence of literal text in the source.
 */
class PusherCredentialFallbackTest extends TestCase
{
    /** Evaluate the config file fresh, bypassing Laravel's cached config(). */
    private function freshConfig(): array
    {
        return require base_path('config/broadcasting.php');
    }

    private function withoutPusherEnv(callable $fn): mixed
    {
        $repo = Env::getRepository();
        $saved = [];
        foreach (['PUSHER_APP_KEY', 'PUSHER_APP_SECRET', 'PUSHER_APP_ID'] as $k) {
            $saved[$k] = getenv($k);
            $repo->clear($k);
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }

        try {
            return $fn();
        } finally {
            foreach ($saved as $k => $v) {
                if ($v !== false) {
                    $repo->set($k, $v);
                    putenv("$k=$v");
                }
            }
        }
    }

    // ── the literals are gone ────────────────────────────────────────────────
    //
    // NOTE: an earlier draft of this suite asserted it by regex-grepping
    // config/broadcasting.php for `env('PUSHER_APP_KEY', '...')`. That approach
    // is rejected on its own evidence: it matched the explanatory comment
    // describing the old code, so it could not distinguish "literal removed"
    // from "literal mentioned in prose". The behavioural test below evaluates
    // the real config with the environment cleared — which is what the
    // vulnerability actually is — so no source-text matching is needed.

    public function test_credentials_resolve_empty_when_the_environment_loses_them(): void
    {
        $resolved = $this->withoutPusherEnv(fn () => $this->freshConfig()['connections']['pusher']);

        // Pre-fix this returned the committed literals, i.e. a working app on a
        // publicly disclosed credential. Post-fix it is empty, which is what
        // the boot guard below then acts on.
        $this->assertEmpty($resolved['key'], 'key must fall back to nothing, not a committed literal.');
        $this->assertEmpty($resolved['secret'], 'secret must fall back to nothing, not a committed literal.');
        $this->assertEmpty($resolved['app_id'], 'app_id must fall back to nothing, not a committed literal.');
    }

    public function test_the_non_secret_cluster_default_is_preserved(): void
    {
        // Behaviour preservation: cluster is a region name, not a credential,
        // so the fix must not have stripped its sensible default.
        $this->withoutPusherEnv(function () {
            $cfg = $this->freshConfig();
            $this->assertSame('ap2', $cfg['connections']['pusher']['options']['cluster']);
        });
    }

    // ── the boot guard ───────────────────────────────────────────────────────

    private function bootWith(string $env, string $driver, ?string $key, ?string $secret): void
    {
        $this->app['env'] = $env;
        config([
            'broadcasting.default' => $driver,
            'broadcasting.connections.pusher.key' => $key,
            'broadcasting.connections.pusher.secret' => $secret,
            // T3-14 added a SECOND boot guard (sync queue forbidden outside
            // local/testing). These tests boot a production-like env to probe
            // the pusher guard in isolation, so they must present an otherwise
            // valid deploy — docker-compose sets QUEUE_CONNECTION=redis, which
            // is what this models. Without it the T3-14 guard (correctly) throws
            // first and the pusher behaviour under test is never reached.
            'queue.default' => 'redis',
        ]);

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_production_boot_refuses_pusher_without_credentials(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires PUSHER_APP_KEY and PUSHER_APP_SECRET');

        $this->bootWith('production', 'pusher', null, null);
    }

    public function test_production_boot_refuses_when_only_the_secret_is_missing(): void
    {
        // Both halves matter: a key alone cannot sign, so a missing secret must
        // not be waved through.
        $this->expectException(\RuntimeException::class);
        $this->bootWith('production', 'pusher', 'some-key', null);
    }

    public function test_production_boot_succeeds_with_credentials_present(): void
    {
        // The denied path must not over-reject: a correctly configured
        // production deploy still boots.
        $this->bootWith('production', 'pusher', 'probe-key', 'probe-secret');

        $this->assertSame('probe-key', config('broadcasting.connections.pusher.key'));
    }

    public function test_production_boot_succeeds_when_broadcasting_is_disabled(): void
    {
        // Guard must be conditional on the driver actually being selected, so
        // null/log drivers (tests, CI, workers) are unaffected.
        $this->bootWith('production', 'null', null, null);

        $this->assertSame('null', config('broadcasting.default'));
    }

    public function test_local_environment_is_never_blocked_by_the_guard(): void
    {
        // Development without credentials stays usable; the check is scoped to
        // real deployments so it cannot brick a laptop.
        $this->bootWith('local', 'pusher', null, null);

        $this->assertNull(config('broadcasting.connections.pusher.key'));
    }

    public function test_testing_environment_is_never_blocked_by_the_guard(): void
    {
        // The suite runs without real broadcast credentials. If the guard ever
        // applied here, every test would fail to boot — this pins that off.
        $this->bootWith('testing', 'pusher', null, null);

        $this->assertNull(config('broadcasting.connections.pusher.key'));
    }
}
