<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CacheStatusHeader
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $status = $request->attributes->get('cache_status', 'BYPASS');

        // Decision 1b: `$response->header()` only exists on Illuminate\Http\Response. The
        // staff document route returns a BinaryFileResponse (it streams the file), and calling
        // ->header() on it threw "Call to undefined method ...::header()" -> 500. The Symfony
        // `headers` bag is present on BOTH response types, so setting the header through it keeps
        // the X-Cache-Status behaviour for every existing JSON route and works for binary ones too.
        $response->headers->set('X-Cache-Status', $status);

        return $response;
    }
}
