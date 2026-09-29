<?php

namespace Tests\Feature\Review;

use App\Support\HorizonAccess;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * RV-06 (Review R2 §RV-06) — the Horizon dashboard must not be public, and
 * nginx must stop leaking the upstream container address.
 *
 * The check that decides RV-06's verification criterion, per R1: an
 * unauthenticated `GET /horizon` with `APP_ENV=production` must not reach the
 * dashboard (403/404). Everything else here pins the surrounding policy so the
 * gate cannot silently regress to "allowed".
 */
class HorizonAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        // The test forces app()->environment() = production; make sure the next
        // test in this process is not affected.
        $this->app['env'] = 'testing';
        parent::tearDown();
    }

    private function asProduction(): void
    {
        // app()->environment() reads the container 'env' binding, which is set
        // from APP_ENV at bootstrap (not from config('app.env')).
        $this->app['env'] = 'production';
    }

    public function test_unauthenticated_horizon_is_refused_in_production(): void
    {
        $this->asProduction();
        config(['horizon.access_token' => 'a-real-secret']);

        $response = $this->get('/horizon');

        $this->assertContains(
            $response->status(),
            [401, 403, 404],
            'an unauthenticated GET /horizon must not serve the dashboard in production'
        );
    }

    public function test_policy_denies_without_a_configured_secret(): void
    {
        $this->asProduction();
        config(['horizon.access_token' => '']);

        $this->assertFalse(
            HorizonAccess::allows(Request::create('/horizon', 'GET')),
            'RV-06: no secret configured must fail closed'
        );
    }

    public function test_policy_denys_with_wrong_or_missing_token(): void
    {
        $this->asProduction();
        config(['horizon.access_token' => 'correct-secret']);

        $noToken = Request::create('/horizon', 'GET');
        $wrong = Request::create('/horizon', 'GET', [], [], [], [
            'HTTP_X_HORIZON_TOKEN' => 'not-the-secret',
        ]);
        $prefix = Request::create('/horizon', 'GET', [], [], [], [
            'HTTP_X_HORIZON_TOKEN' => 'correct-secre',
        ]);

        $this->assertFalse(HorizonAccess::allows($noToken), 'missing token must deny');
        $this->assertFalse(HorizonAccess::allows($wrong), 'wrong token must deny');
        $this->assertFalse(HorizonAccess::allows($prefix), 'token prefix must deny (hash_equals, not ==)');
    }

    public function test_policy_allows_the_correct_token_outside_local(): void
    {
        $this->asProduction();
        config(['horizon.access_token' => 'correct-secret']);

        $ok = Request::create('/horizon', 'GET', [], [], [], [
            'HTTP_X_HORIZON_TOKEN' => 'correct-secret',
        ]);

        $this->assertTrue(HorizonAccess::allows($ok), 'the configured secret must open the dashboard');
    }

    public function test_policy_keeps_local_and_testing_working(): void
    {
        config(['horizon.access_token' => '']);

        foreach (['local', 'testing'] as $env) {
            $this->app['env'] = $env;
            $this->assertTrue(
                HorizonAccess::allows(Request::create('/horizon', 'GET')),
                "developer ergonomics must survive in {$env}"
            );
        }
    }

    public function test_nginx_no_longer_advertises_the_upstream_address(): void
    {
        $conf = (string) file_get_contents(base_path('nginx-docker.conf'));

        // Check the DIRECTIVE, not the words: the config keeps a comment
        // explaining why the header was removed, and a substring match on the
        // header name would flag that comment forever.
        foreach (explode("\n", $conf) as $n => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '#')) {
                continue;
            }
            $this->assertStringNotContainsString(
                'X-Upstream-Addr',
                $trimmed,
                'RV-06: line '.($n + 1).' still sets the upstream address header'
            );
            $this->assertStringNotContainsString('add_header', $trimmed, 'line '.($n + 1).' still adds a response header');
        }
    }
}
