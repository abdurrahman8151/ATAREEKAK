<?php

namespace Tests\Feature\Review;

use App\Exceptions\Domain\AuthorizationViolation;
use App\Exceptions\Domain\BusinessRuleViolation;
use App\Exceptions\Domain\ConflictViolation;
use App\Exceptions\Domain\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * RV-13(a) / owner decision D3 = A (2026-10-04): a domain rule's MESSAGE is masked in production and
 * logged instead.
 *
 * THE DEFECT THIS CLOSES. `Handler.php` had two renderables for the same kind of refusal:
 *
 *   - `\InvalidArgumentException` -> `config('app.debug') ? $e->getMessage() : 'The request could not
 *     be processed.'`  (masked in production)
 *   - `DomainException` -> `response()->json($e->toArray(), ...)`  (ALWAYS the raw message)
 *
 * So the newer, purpose-built domain exceptions leaked unconditionally while the base class they
 * were built to replace masked correctly. That is exactly why migrating the 61 sites that throw a
 * bare `\InvalidArgumentException` was BLOCKED rather than merely large: moving one site across
 * would have silently unmasked its message to clients. With masking in place the migration becomes
 * behaviour-preserving and can proceed site by site.
 *
 * WHAT MUST NOT CHANGE: the response SHAPE. Same keys, same HTTP status, and `code` stays - it is an
 * authored enum the client branches on, not a leak. Only `message` is replaced.
 */
class RV13DomainExceptionMaskingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Three routes that throw each domain subclass, so every one is proven masked rather than
        // inferred from a sibling's behaviour.
        Route::middleware('api')->group(function () {
            Route::get('/rv13/rule', fn () => throw new BusinessRuleViolation(
                'INTERNAL: wallet row 41 is locked by transaction 9002',
                errorCode: 'WALLET_LOCKED'
            ));
            Route::get('/rv13/authz', fn () => throw new AuthorizationViolation(
                'INTERNAL: guard saw employee role=system_admin on user table',
                errorCode: 'FORBIDDEN'
            ));
            Route::get('/rv13/conflict', fn () => throw new ConflictViolation(
                'INTERNAL: unique index users_phone_uq collided on 0911234567',
                errorCode: 'CONFLICT'
            ));
        });
    }

    /**
     * The headline: in production the internal text must not reach the client.
     *
     * @test
     */
    public function a_domain_rule_message_is_masked_in_production(): void
    {
        Config::set('app.debug', false);

        $response = $this->getJson('/rv13/rule');

        $response->assertStatus(422);
        $this->assertStringNotContainsString(
            'wallet row 41',
            $response->getContent(),
            'the raw message leaked to the client in production'
        );
        $this->assertStringNotContainsString('INTERNAL', $response->getContent());
    }

    /**
     * The detail must be LOGGED, or masking would be destruction rather than concealment.
     *
     * @test
     */
    public function the_masked_detail_is_logged_with_the_code_and_route(): void
    {
        Config::set('app.debug', false);
        Log::spy();

        $this->getJson('/rv13/rule')->assertStatus(422);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context) {
                return $message === 'Domain rule violation masked for production'
                    && str_contains((string) $context['detail'], 'wallet row 41')
                    && $context['code'] === 'WALLET_LOCKED'
                    && $context['path'] === 'rv13/rule';
            });
    }

    /**
     * The shape is unchanged - a client branching on `code` must not break.
     *
     * @test
     */
    public function the_code_and_status_are_unchanged_by_masking(): void
    {
        Config::set('app.debug', false);

        $response = $this->getJson('/rv13/rule');

        $response->assertJson([
            'status' => 'error',
            'code' => 'WALLET_LOCKED',
            'status_code' => 422,
            'message' => 'The request could not be processed.',
        ]);
    }

    /**
     * All THREE subclasses are masked. Proving only one would let a sibling regress unnoticed.
     *
     * @test
     */
    public function every_domain_subclass_is_masked_not_just_the_one(): void
    {
        Config::set('app.debug', false);

        foreach (['rv13/rule', 'rv13/authz', 'rv13/conflict'] as $path) {
            $body = $this->getJson('/'.$path)->getContent();

            $this->assertStringNotContainsString('INTERNAL', $body, "{$path} leaked its detail");
        }
    }

    /**
     * And each subclass keeps its own status - masking must not flatten them all to one code.
     *
     * @test
     */
    public function each_subclass_keeps_its_own_http_status(): void
    {
        Config::set('app.debug', false);

        $this->getJson('/rv13/rule')->assertStatus(422);
        $this->getJson('/rv13/authz')->assertStatus(403);
        $this->getJson('/rv13/conflict')->assertStatus(409);
    }

    /**
     * In DEBUG the developer still gets the real message - masking is a production control, not a
     * blanket. Without this, the fix would make local diagnosis harder and people would work around it.
     *
     * @test
     */
    public function debug_mode_still_shows_the_real_message(): void
    {
        Config::set('app.debug', true);

        $this->getJson('/rv13/rule')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'INTERNAL: wallet row 41 is locked by transaction 9002']);
    }

    /**
     * THE POINT OF THE WHOLE TASK, as a structural guard: the two handlers for the same kind of
     * refusal must not drift apart again. This is what made the migration unsafe in the first place.
     *
     * @test
     */
    public function the_base_invalid_argument_handler_and_the_domain_handler_mask_identically(): void
    {
        $src = (string) file_get_contents(app_path('Exceptions/Handler.php'));

        $this->assertSame(
            substr_count($src, "'The request could not be processed.'"),
            2,
            'both the \InvalidArgumentException and the DomainException branch must mask to the same '
            .'string - if they diverge, migrating a site across will change what clients see'
        );
    }

    /**
     * And the masking must actually be conditional on debug - a hard replacement would be caught by
     * the debug test above, but an unconditional mask behind a `true` would not.
     *
     * @test
     */
    public function masking_is_conditional_on_debug_not_hardcoded(): void
    {
        $src = (string) file_get_contents(app_path('Exceptions/Handler.php'));

        $this->assertStringContainsString(
            "if (! config('app.debug')) {",
            $src,
            'the mask must be gated on app.debug, not applied unconditionally'
        );
    }
}
