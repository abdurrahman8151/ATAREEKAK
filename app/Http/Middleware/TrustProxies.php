<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * RV-05: this was left null, so behind nginx every request appeared to come
     * from the proxy container. Consequences: every `ip:` rate-limit bucket
     * (RouteServiceProvider lines 71/75) collapsed into ONE bucket shared by all
     * users, `GateDocumentation`'s allow-list could never match a real client,
     * and access logs recorded the proxy for every request.
     *
     * Now driven by TRUSTED_PROXIES (comma-separated CIDRs/addresses). It is
     * deliberately NOT '*' and NOT hard-coded to a network: the container
     * network differs per deployment, and '*' would let any client that can set
     * X-Forwarded-For choose its own IP whenever nginx appends to that header.
     * Unset => trust nobody (the previous behaviour), so this cannot widen trust
     * by accident. nginx-docker.conf overwrites XFF with $remote_addr, which is
     * what makes honouring the header safe.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct()
    {
        // Read through config (not env()) so `php artisan config:cache` cannot
        // silently blank this in production — an env() call outside a config
        // file returns null once the config is cached.
        $trusted = config('trustedproxy.proxies');

        $trusted = is_string($trusted) ? trim($trusted) : $trusted;

        // '0' is a deliberate escape hatch for tests/deployments that must keep
        // trusting nothing even though an env var exists.
        if ($trusted === null || $trusted === '' || $trusted === '0') {
            $this->proxies = null;

            return;
        }

        $this->proxies = array_values(array_filter(array_map(
            'trim',
            is_array($trusted) ? $trusted : explode(',', $trusted)
        ), fn ($entry) => $entry !== ''));
    }
}
