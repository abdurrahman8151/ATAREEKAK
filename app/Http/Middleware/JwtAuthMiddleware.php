<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Support\JwtAuthHandler;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Status codes:
 *   -1 = banned
 *    0 = logged out
 *    1 = active
 */
class JwtAuthMiddleware
{
    public function __construct(private readonly JwtAuthHandler $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->auth->handle($request, $next);
    }
}
