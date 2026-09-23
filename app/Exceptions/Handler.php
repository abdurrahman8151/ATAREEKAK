<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
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

                if (method_exists($e, 'getStatusCode')) {
                    $status = $e->getStatusCode();
                } elseif ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                    $status = 404;
                } elseif ($e instanceof \Illuminate\Validation\ValidationException) {
                    $status = 422;
                } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    $status = 401;
                } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                    $status = $e->getStatusCode();
                }

                return response()->json([
                    'status'  => 'error',
                    'message' => config('app.debug')
                        ? $e->getMessage()
                        : 'An unexpected error occurred.',
                    'code'    => $status,
                ], $status);
            }
        });
    }
}
