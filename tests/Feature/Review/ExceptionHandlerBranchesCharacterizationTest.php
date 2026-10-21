<?php

namespace Tests\Feature\Review;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Characterization of App\Exceptions\Handler::register(), pinned on the UNCHANGED handler before its
 * renderers are moved.
 *
 * The domain-exception branches are pinned by DomainExceptionMappingTest and RV13DomainExceptionMaskingTest,
 * and the 404/500 split by RV45RideNotFoundTest. Four contracts had NO pin:
 *  - the three JWT renderers: 401 "Token has expired", 401 "Token is invalid", 401 "Token not provided".
 *  - the ValidationException renderer's exact shape (message + errors only), which proves it won instead of
 *    the catch-all rebuilding the response with status/code keys.
 *  - the catch-all's preservation of the exception's own headers (Retry-After on a 429).
 *  - the catch-all's debug-versus-production message.
 *
 * The real handler is resolved from the container and driven through its public render(), so the real
 * renderCallbacks and their registration order are exercised. Nothing is mocked.
 */
class ExceptionHandlerBranchesCharacterizationTest extends TestCase
{
    private function renderJson(\Throwable $e): JsonResponse
    {
        $request = Request::create('/api/characterization', 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        return app(ExceptionHandler::class)->render($request, $e);
    }

    /**
     * The order is load-bearing: Laravel iterates renderCallbacks in insertion order and returns the first
     * callback that matches and returns non-null, so the catch-all Throwable renderer MUST stay last.
     */
    public function test_the_renderers_are_registered_in_the_load_bearing_order(): void
    {
        $handler = app(ExceptionHandler::class);

        $property = new \ReflectionProperty($handler, 'renderCallbacks');
        $property->setAccessible(true);

        $types = [];
        foreach ($property->getValue($handler) as $callback) {
            $types[] = (string) (new \ReflectionFunction($callback))->getParameters()[0]->getType();
        }

        $this->assertSame([
            'PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException',
            'PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException',
            'PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException',
            'Illuminate\Validation\ValidationException',
            'App\Exceptions\Domain\DomainException',
            'InvalidArgumentException',
            'Throwable',
        ], $types);
    }

    public function test_an_expired_token_is_401_with_its_exact_message(): void
    {
        $response = $this->renderJson(new TokenExpiredException('expired'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => 'Token has expired'], $response->getData(true));
    }

    public function test_an_invalid_token_is_401_with_its_exact_message(): void
    {
        $response = $this->renderJson(new TokenInvalidException('invalid'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => 'Token is invalid'], $response->getData(true));
    }

    public function test_a_missing_token_is_401_with_its_exact_message(): void
    {
        $response = $this->renderJson(new JWTException('absent'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => 'Token not provided'], $response->getData(true));
    }

    public function test_a_validation_failure_keeps_the_errors_bag_and_its_own_shape(): void
    {
        $response = $this->renderJson(ValidationException::withMessages([
            'email' => ['The email field is required.'],
        ]));

        $this->assertSame(422, $response->getStatusCode());

        $json = $response->getData(true);
        // Only these two keys: the catch-all would have rebuilt it as status/message/code.
        $this->assertSame(['message', 'errors'], array_keys($json));
        $this->assertSame(['The email field is required.'], $json['errors']['email']);
    }

    public function test_the_catch_all_preserves_the_exceptions_own_headers(): void
    {
        $response = $this->renderJson(new HttpException(429, 'Too Many Attempts.', null, ['Retry-After' => '60']));

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->headers->get('Retry-After'));
    }

    public function test_the_catch_all_hides_the_message_in_production_and_shows_it_in_debug(): void
    {
        config(['app.debug' => false]);
        $production = $this->renderJson(new \RuntimeException('internal detail'));

        $this->assertSame(500, $production->getStatusCode());
        $this->assertSame([
            'status' => 'error',
            'message' => 'An unexpected error occurred.',
            'code' => 500,
        ], $production->getData(true));

        config(['app.debug' => true]);
        $debug = $this->renderJson(new \RuntimeException('internal detail'));

        $this->assertSame(500, $debug->getStatusCode());
        $this->assertSame('internal detail', $debug->getData(true)['message']);
    }
}
