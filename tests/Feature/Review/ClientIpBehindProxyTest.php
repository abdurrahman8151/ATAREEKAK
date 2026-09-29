<?php

namespace Tests\Feature\Review;

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * RV-05 (Review R2, VERIFY V4) — the client IP behind a reverse proxy.
 *
 * V4 recorded that `TrustProxies::$proxies` was null, so behind nginx every
 * request looked like it came from the proxy container. That collapses every
 * `ip:`-keyed rate-limit bucket (RouteServiceProvider lines 71 and 75) into one
 * bucket shared by all users, makes GateDocumentation's allow-list unmatchable,
 * and logs the proxy address for every request.
 *
 * The fix has TWO coupled halves and both are covered here:
 *   (a) Laravel honours X-Forwarded-For only from a configured proxy
 *       (TRUSTED_PROXIES), and only when it is set;
 *   (b) nginx overwrites XFF with $remote_addr instead of appending to it, so
 *       the header Laravel trusts cannot be supplied by the client. Without (b),
 *       enabling (a) would itself create the spoofing bypass V4 refuted.
 */
class ClientIpBehindProxyTest extends TestCase
{
    /** Probe route: returns the IP Laravel resolved for the request. */
    private function probe(): void
    {
        Route::middleware('api')->get('/__rv05_ip', fn (Request $r) => response()->json([
            'ip' => $r->ip(),
        ]));
    }

    private function resolvedIpFor(string $remoteAddr, ?string $xff): string
    {
        $this->probe();

        $server = ['REMOTE_ADDR' => $remoteAddr];
        $headers = $xff === null ? [] : ['X-Forwarded-For' => $xff];

        return $this->withServerVariables($server)
            ->withHeaders($headers)
            ->getJson('/__rv05_ip')
            ->json('ip');
    }

    public function test_with_no_trusted_proxies_configured_the_header_is_ignored(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->assertSame(
            '10.0.0.5',
            $this->resolvedIpFor('10.0.0.5', '203.0.113.9'),
            'RV-05: with trust disabled the proxy header must NOT be honoured (unchanged default)'
        );
    }

    public function test_an_explicit_zero_also_means_trust_nobody(): void
    {
        config(['trustedproxy.proxies' => '0']);

        $this->assertSame('10.0.0.5', $this->resolvedIpFor('10.0.0.5', '203.0.113.9'));
    }

    public function test_a_configured_proxy_makes_the_real_client_ip_visible(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.5']);

        $this->assertSame(
            '203.0.113.9',
            $this->resolvedIpFor('10.0.0.5', '203.0.113.9'),
            'RV-05: a trusted proxy must expose the real client, not the proxy address'
        );
    }

    public function test_a_cidr_range_is_supported(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8, 172.16.0.0/12']);

        $this->assertSame('203.0.113.9', $this->resolvedIpFor('10.0.0.5', '203.0.113.9'));
        $this->assertSame('203.0.113.9', $this->resolvedIpFor('172.16.4.4', '203.0.113.9'));
    }

    public function test_an_untrusted_source_cannot_spoof_its_own_address(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.5']);

        // Remote address is NOT in the trust list: its XFF must be ignored, so a
        // direct caller cannot choose its own rate-limit bucket.
        $this->assertSame(
            '198.51.100.7',
            $this->resolvedIpFor('198.51.100.7', '203.0.113.9'),
            'RV-05: an untrusted peer must not be able to spoof its address'
        );
    }

    public function test_two_clients_get_separate_ip_keys(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.5']);

        $a = $this->resolvedIpFor('10.0.0.5', '203.0.113.9');
        $b = $this->resolvedIpFor('10.0.0.5', '203.0.113.10');

        $this->assertNotSame($a, $b, 'RV-05: two clients must not share one rate-limit bucket');
        $this->assertSame('ip:'.$a !== 'ip:'.$b, true, 'the ip: bucket keys differ');
    }

    public function test_the_middleware_parses_the_configuration(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.5, 172.16.0.0/12']);
        $mw = new TrustProxies;
        $this->assertSame(['10.0.0.5', '172.16.0.0/12'], $this->proxiesOf($mw));

        config(['trustedproxy.proxies' => '']);
        $this->assertNull($this->proxiesOf(new TrustProxies), 'empty config => trust nobody');

        config(['trustedproxy.proxies' => null]);
        $this->assertNull($this->proxiesOf(new TrustProxies), 'unset config => trust nobody');
    }

    public function test_nginx_overwrites_the_forwarded_header_instead_of_appending(): void
    {
        $conf = (string) file_get_contents(base_path('nginx-docker.conf'));

        $directives = [];
        foreach (explode("\n", $conf) as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '#')) {
                continue;
            }
            if (str_contains($trimmed, 'X-Forwarded-For')) {
                $directives[] = trim($trimmed);
            }
        }

        $this->assertNotEmpty($directives, 'nginx must set X-Forwarded-For explicitly');
        foreach ($directives as $directive) {
            $this->assertStringContainsString(
                '$remote_addr',
                $directive,
                'RV-05: XFF must be overwritten with $remote_addr'
            );
            $this->assertStringNotContainsString(
                'proxy_add_x_forwarded_for',
                $directive,
                'RV-05: appending lets a client inject the header value Laravel trusts'
            );
        }
    }

    /** Read the protected $proxies property without changing its visibility. */
    private function proxiesOf(TrustProxies $middleware): mixed
    {
        $ref = new \ReflectionProperty($middleware, 'proxies');
        $ref->setAccessible(true);

        return $ref->getValue($middleware);
    }
}
