<?php

namespace Tests\Feature\T3Batch;

use App\Http\Middleware\GateDocumentation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Concerns\SimulatesProductionBoot;
use Tests\TestCase;

/**
 * T3 batch — environment-driven guards.
 *   T3-12  docs served anonymously -> GateDocumentation (local/testing open, elsewhere IP allowlist)
 *   T3-14  QUEUE_CONNECTION silently resolving to `sync` -> fail fast outside local/testing
 */
class EnvironmentGuardsBatchTest extends TestCase
{
    use SimulatesProductionBoot;

    /** @var string|null the container 'env' value we override per test */
    private ?string $originalEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = $this->app['env'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->app['env'] = $this->originalEnv ?? 'testing';
        parent::tearDown();
    }

    private function runGate(string $ip, array $allowedIps, string $env): void
    {
        $this->app['env'] = $env;
        config(['l5-swagger.defaults.routes.docs_allowed_ips' => $allowedIps]);

        $request = Request::create('/docs', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);

        (new GateDocumentation)->handle($request, fn ($r) => new Response('ok'));
    }

    // ── T3-12 ───────────────────────────────────────────────────────────────
    public function test_docs_are_served_in_local_and_testing(): void
    {
        $this->runGate('203.0.113.7', [], 'local');
        $this->runGate('203.0.113.7', [], 'testing');
        $this->assertTrue(true, 'reached here == allowed through');
    }

    public function test_docs_are_hidden_outside_local_without_an_allowlist(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->runGate('203.0.113.7', [], 'production');
    }

    public function test_docs_allow_the_listed_ip_in_production(): void
    {
        $this->runGate('198.51.100.9', ['198.51.100.9'], 'production');
        $this->assertTrue(true);
    }

    public function test_docs_still_hide_unlisted_ips_when_an_allowlist_exists(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->runGate('203.0.113.7', ['198.51.100.9'], 'production');
    }

    public function test_the_gate_is_wired_into_every_docs_route_slot(): void
    {
        $mw = config('l5-swagger.defaults.routes.middleware');

        foreach (['api', 'asset', 'docs', 'oauth2_callback'] as $slot) {
            $this->assertContains(
                GateDocumentation::class,
                $mw[$slot] ?? [],
                "l5-swagger middleware slot '$slot' must carry the gate (it was [] before T3-12)"
            );
        }
    }

    public function test_the_generated_spec_path_is_git_ignored(): void
    {
        // T3-12: the committed generated spec must not be re-addable. Asserting
        // the ignore rule exists (a deterministic file read) rather than shelling
        // out to git, which would pass vacuously if the subprocess returned null.
        $gitignore = (string) file_get_contents(base_path('.gitignore'));

        $this->assertStringContainsString(
            '/storage/api-docs',
            $gitignore,
            'the generated api-docs directory must be git-ignored after T3-12'
        );
    }

    // ── T3-14 ────────────────────────────────────────────────────────────────
    private function bootProvider(string $env, string $queueDriver): void
    {
        $this->app['env'] = $env;
        config(['queue.default' => $queueDriver]);
        // RV-16: same reasoning as the queue guard these tests exist to probe.
        // A production boot simulation is only realistic without the OTP testing
        // modes that phpunit.xml sets for the suite, so they are cleared for the
        // boot and restored after. See SimulatesProductionBoot.
        $this->bootProviderWithoutOtpTestingModes($env);
    }

    public function test_booting_with_sync_queue_outside_local_fails_fast(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/QUEUE_CONNECTION/');
        $this->bootProvider('production', 'sync');
    }

    public function test_booting_with_redis_queue_is_allowed(): void
    {
        $this->bootProvider('production', 'redis');
        $this->assertTrue(true);
    }

    public function test_testing_and_local_remain_exempt_from_the_queue_guard(): void
    {
        $this->bootProvider('testing', 'sync');
        $this->bootProvider('local', 'sync');
        $this->assertTrue(true, 'the suite runs on sync; the guard must not brick it');
    }

    public function test_the_config_default_is_still_sync_so_the_guard_matters(): void
    {
        // Documenting the hazard the audit described: config/queue.php falls back
        // to 'sync' when the env var is absent. The guard is what turns that
        // silent fallback into a boot failure in production.
        $config = require base_path('config/queue.php');
        $this->assertSame('sync', $config['default'] ?? null, 'config default fallback is sync');
    }
}
