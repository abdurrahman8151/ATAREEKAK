<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * T3-12 — l5-swagger served its UI, JSON spec and assets with NO middleware
 * at all (config/l5-swagger.php 'middleware' arrays were empty), so the full
 * API surface — every admin/staff route, parameter and error code — was
 * enumerable by anyone who knew the path.
 *
 * Policy implemented here:
 *   - local / testing environments: allowed (developer ergonomics kept).
 *   - everything else: allowed only for IPs listed in DOCS_ALLOWED_IPS
 *     (comma-separated .env value). Empty/unset = nobody outside local.
 *
 * 404 is returned rather than 403 so the endpoint does not even confirm its
 * own existence to a scanner.
 */
class GateDocumentation
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->ip() === null) {
            // No resolvable client address (e.g. some proxied contexts) —
            // treat as external and deny, fail closed.
            abort(404);
        }

        if (app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        $allowed = (array) config('l5-swagger.defaults.routes.docs_allowed_ips', []);

        if (in_array($request->ip(), $allowed, true)) {
            return $next($request);
        }

        abort(404);
    }
}
