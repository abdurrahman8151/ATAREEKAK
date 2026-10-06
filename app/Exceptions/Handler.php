<?php

namespace App\Exceptions;

use App\Exceptions\Domain\DomainException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
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

        // RV-13 / V5: a validation failure must say WHICH field failed and why.
        //
        // This is registered BEFORE the catch-all below, deliberately. The catch-all
        // matches `Throwable`, so it also caught ValidationException, mapped it to
        // 422, and then REBUILT the response as
        // {"status":"error","message":…,"code":422} — discarding $e->errors().
        // Laravel's own default rendering of a ValidationException keeps the bag, so
        // restoring it here is also the smaller change: the client gets the field
        // errors it needs and the rest of the catch-all (status mapping, header
        // preservation, generic 500s) is left exactly as it was.
        //
        // Only claims api/* and JSON-negotiated requests; anything else returns null
        // so Laravel's normal (HTML) rendering still applies.
        $this->renderable(function (ValidationException $e, $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // ValidationException carries no contract headers (unlike HttpException,
            // which is why the catch-all below guards with method_exists), so only the
            // status is passed through. $e->status exists and defaults to 422.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        });

        // RV-13: domain rule violations are CLIENT errors.
        //
        // Registered before the catch-all below, and before anything else, because the catch-all
        // matches `Throwable` and would otherwise answer every one of these with 500.
        //
        // A bare `\InvalidArgumentException` is mapped too, on purpose: R2 sec 20.1 measured 61
        // services throwing it, and migrating them all to the new subclasses in one pass would be a
        // 61-site sweep. Until they are migrated, mapping the base class means the worst symptom -
        // a refused request reported as a server fault - is gone TODAY rather than after a large
        // refactor. It is deliberately conservative: 422 with a generic code, never the raw message
        // in production.
        $this->renderable(function (DomainException $e, $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $payload = $e->toArray();

            // RV-13(a), owner decision D3 = A (2026-10-04): MASK the message in production, log the
            // detail. Until this change, `toArray()` shipped the raw message unconditionally while
            // the `\InvalidArgumentException` branch two lines below already masked it - so the two
            // handlers for the same *kind* of refusal disagreed about whether internals were safe to
            // show, and the newer, safer one was the older branch.
            //
            // This is what made migrating the 61 `\InvalidArgumentException` sites a BLOCKED task
            // rather than a refactor: `Handler:95` masked, `DomainException::toArray()` did not, so
            // moving one site across would have silently UNMASKED its message to clients. With the
            // masking in place the migration becomes behaviour-preserving and can proceed site by site.
            //
            // The response SHAPE is unchanged - same keys, same status. Only `message` is replaced,
            // and the real text is logged at warning level with the code and route so a support
            // answer is still possible. `code` is deliberately kept: it is an authored enum, not a
            // leak, and the client needs it to branch.
            if (! config('app.debug')) {
                Log::warning('Domain rule violation masked for production', [
                    'exception' => $e::class,
                    'code' => $e->errorCode,
                    'detail' => $e->getMessage(),
                    'method' => $request->method(),
                    'path' => $request->path(),
                ]);

                $payload['message'] = 'The request could not be processed.';
            }

            return response()->json($payload, $e->httpStatus);
        });

        $this->renderable(function (\InvalidArgumentException $e, $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'The request could not be processed.',
                'code' => 'DOMAIN_RULE_VIOLATION',
                'status_code' => 422,
            ], 422);
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
