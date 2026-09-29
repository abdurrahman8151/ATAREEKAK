<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * RV-06 — access policy for the Horizon dashboard.
 *
 * Before this, `HorizonServiceProvider::gate()` returned `true` for every
 * caller, justified by the comment "port 8080 is only exposed to localhost".
 * That is false for the real deployment: Render fronts the app with a public
 * web service, and `nginx-docker.conf` proxies `location /` — which includes
 * Horizon's `horizon` prefix — so `/horizon` answered the public internet with
 * a page that lists every queued job (payloads carry user ids, phone numbers
 * and notification text) and can retry or delete them.
 *
 * Policy, mirroring the existing GateDocumentation middleware so the app has
 * one house style:
 *   - local / testing: allowed (developer ergonomics).
 *   - everything else:  allowed ONLY with the shared secret from
 *     HORIZON_ACCESS_TOKEN, compared with hash_equals(). Unset secret = deny.
 *
 * Fail-closed on every ambiguous path: no token, empty token, or a request
 * without a resolvable object all deny.
 *
 * Deliberately NOT an IP allowlist: `TrustProxies::$proxies` is null in this
 * app, so behind nginx every request presents the proxy's own address and an
 * IP rule could only ever be all-or-nothing (that is RV-05's separate fix).
 * A secret works regardless of proxy topology.
 */
class HorizonAccess
{
    public static function allows(?Request $request = null): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        $expected = (string) config('horizon.access_token', '');

        if ($expected === '') {
            return false; // fail closed: no secret configured, nobody gets in
        }

        $request ??= request();

        $presented = (string) $request->header('X-Horizon-Token', '');

        if ($presented === '') {
            return false;
        }

        return hash_equals($expected, $presented);
    }
}
