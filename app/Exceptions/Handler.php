<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (TokenExpiredException $e) {
            return response()->json(['message' => 'Token has expired'], 401);
        });

        $this->renderable(function (TokenInvalidException $e) {
            return response()->json(['message' => 'Token is invalid'], 401);
        });

        $this->renderable(function (JWTException $e) {
            return response()->json(['message' => 'Token not provided'], 401);
        });

        // Catch-all for API routes — returns JSON instead of an HTML error page.
        // In debug mode the real message is exposed; in production a generic
        // message is shown so stack traces never leak to clients.
        $this->renderable(function (Throwable $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {

                $status = 500;
                $headers = [];

                if (method_exists($e, 'getStatusCode')) {
                    $status = $e->getStatusCode();
                } elseif ($e instanceof ModelNotFoundException) {
                    $status = 404;
                } elseif ($e instanceof ValidationException) {
                    $status = 422;
                } elseif ($e instanceof AuthenticationException) {
                    $status = 401;
                } elseif ($e instanceof HttpException) {
                    $status = $e->getStatusCode();
                }

                // Preserve the exception's own headers on the rebuilt JSON
                // response. HttpExceptionInterface::getHeaders() carries
                // contract-mandated headers — most importantly Retry-After on a
                // 429 ThrottleRequestsException. Rebuilding the response without
                // them silently stripped the retry timing every throttled client
                // depends on. Previously invisible because the test suite disabled
                // the throttle middleware globally (audit T4-1).
                if (method_exists($e, 'getHeaders')) {
                    $headers = $e->getHeaders();
                }

                return response()->json([
                    'status' => 'error',
                    'message' => config('app.debug')
                        ? $e->getMessage()
                        : 'An unexpected error occurred.',
                    'code' => $status,
                ], $status, $headers);
            }
        });
    }
}
