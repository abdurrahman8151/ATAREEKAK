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

        return $response->header('X-Cache-Status', $status);
    }
}
