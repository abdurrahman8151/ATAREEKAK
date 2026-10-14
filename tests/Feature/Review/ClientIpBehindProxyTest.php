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

    /** The exact value set in render.yaml (Render internal 10/8 plus the Cloudflare ranges). */
    private const RENDER_TRUSTED_PROXIES = '127.0.0.1/32,10.0.0.0/8,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,104.16.0.0/13,104.24.0.0/14,108.162.192.0/18,131.0.72.0/22,141.101.64.0/18,162.158.0.0/15,172.64.0.0/13,173.245.48.0/20,188.114.96.0/20,190.93.240.0/20,197.234.240.0/22,198.41.128.0/17,2400:cb00::/32,2606:4700::/32,2803:f800::/32,2405:b500::/32,2405:8100::/32,2a06:98c0::/29,2c0f:f248::/32';

    public function test_render_chain_resolves_to_the_real_client(): void
    {
        config(['trustedproxy.proxies' => self::RENDER_TRUSTED_PROXIES]);

        // Observed on Render: client, then Cloudflare, then Render's internal 10.x hop.
        $this->assertSame(
            '203.0.113.5',
            $this->resolvedIpFor('10.226.90.65', '203.0.113.5, 172.71.195.123, 10.226.90.65'),
            'V4: the Render chain must resolve to the client, not a proxy hop'
        );
    }

    public function test_the_roadrunner_loopback_hop_resolves_to_the_real_client(): void
    {
        config(['trustedproxy.proxies' => self::RENDER_TRUSTED_PROXIES]);

        // Production (live check): RoadRunner forwards with REMOTE_ADDR 127.0.0.1, then the
        // Render chain. Loopback must be trusted or the header is ignored.
        $this->assertSame(
            '91.186.247.28',
            $this->resolvedIpFor('127.0.0.1', '91.186.247.28, 162.158.94.26, 10.30.83.227'),
            'V4: the RoadRunner loopback peer must be trusted so the real client is used'
        );
    }

    public function test_a_spoofed_forwarded_prefix_does_not_change_the_client(): void
    {
        config(['trustedproxy.proxies' => self::RENDER_TRUSTED_PROXIES]);

        // The attacker prepends a fake address. Trusted hops are skipped right-to-left, so the
        // first untrusted entry (the real client) still wins over the forged prefix.
        $this->assertSame(
            '203.0.113.5',
            $this->resolvedIpFor('10.226.90.65', '198.51.100.9, 203.0.113.5, 172.71.195.123, 10.226.90.65'),
            'V4: a client-supplied XFF prefix must not choose the rate-limit identity'
        );
    }

    public function test_an_untrusted_peer_with_a_forged_header_is_ignored(): void
    {
        config(['trustedproxy.proxies' => self::RENDER_TRUSTED_PROXIES]);

        // 198.51.100.9 is NOT in any trusted range, so its forged XFF must be discarded.
        $this->assertSame(
            '198.51.100.9',
            $this->resolvedIpFor('198.51.100.9', '10.9.9.9, 203.0.113.5'),
            'V4: a direct untrusted peer must not be able to set its IP via XFF'
        );
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
