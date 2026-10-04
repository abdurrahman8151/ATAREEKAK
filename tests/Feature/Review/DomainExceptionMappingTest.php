<?php

namespace Tests\Feature\Review;

use App\Exceptions\Domain\AuthorizationViolation;
use App\Exceptions\Domain\BusinessRuleViolation;
use App\Exceptions\Domain\ConflictViolation;
use App\Exceptions\Domain\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * RV-13, foundation half: a domain rule violation is a CLIENT error, not a server error.
 *
 * THE DEFECT (R2 sec 20.1, measured): 61 services throw `\InvalidArgumentException`, and the
 * handler's catch-all had no branch for it, so any that reached the handler answered **500**. That is
 * wrong three times over - the client cannot tell a refusal from a fault, error monitoring drowns
 * real 500s in rule rejections, and retry-on-5xx logic hammers an endpoint that will never succeed.
 *
 * These tests pin the mapping itself, against a temporary route that throws each exception type. A
 * test route is used rather than an application endpoint so the assertion is about the EXCEPTION
 * MAPPING and cannot be disturbed by whatever a real endpoint decides to do with it.
 */
class DomainExceptionMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway route per test, so this file changes no application code and cannot be
        // disturbed by what a real endpoint decides to do with an exception.
        //
        // The path carries the `api/` PREFIX deliberately: the handler's renderables only fire for
        // `$request->is('api/*')`, so a route outside that prefix would silently test nothing.
        // No auth middleware is attached - this asserts the EXCEPTION MAPPING, not the auth gate.
        Route::get('api/__rv13/{kind}', function (string $kind) {
            throw match ($kind) {
                'business' => new BusinessRuleViolation('That ride is already full.', 'RIDE_FULL'),
                'forbidden' => new AuthorizationViolation('You can only confirm your own bookings.', 'NOT_YOUR_BOOKING'),
                'conflict' => new ConflictViolation('Insufficient balance.', 'INSUFFICIENT_BALANCE', ['required' => 50000]),
                'legacy' => new \InvalidArgumentException('You must be verified as a driver to create rides'),
                default => new \RuntimeException('a genuine bug'),
            };
        });
    }

    /** @test */
    public function a_business_rule_violation_is_422_not_500(): void
    {
        $response = $this->getJson('/api/__rv13/business');

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('code', 'RIDE_FULL');
        $response->assertJsonPath('status_code', 422);
    }

    /** @test */
    public function an_authorization_violation_is_403(): void
    {
        $this->getJson('/api/__rv13/forbidden')
            ->assertStatus(403)
            ->assertJsonPath('code', 'NOT_YOUR_BOOKING');
    }

    /**
     * 409 vs 422 is the distinction that lets a client retry: an insufficient-balance conflict is
     * fixed by topping up and sending the SAME request, not by changing it.
     */
    /** @test */
    public function a_state_conflict_is_409(): void
    {
        $response = $this->getJson('/api/__rv13/conflict');

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'INSUFFICIENT_BALANCE');
        $response->assertJsonPath('context.required', 50000);
    }

    /**
     * THE BIG ONE. A bare `\InvalidArgumentException` - what 61 services actually throw - used to be
     * answered 500. It must now be a 422 with a stable machine-readable code.
     *
     * The message is the one thing that must NOT change: in production it is replaced by a generic
     * string, because `config('app.debug')` leaking a domain message is the 121-`getMessage()`
     * problem this half does NOT fix.
     */
    /** @test */
    public function a_legacy_invalid_argument_exception_is_422_with_a_stable_code(): void
    {
        $response = $this->getJson('/api/__rv13/legacy');

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'DOMAIN_RULE_VIOLATION');
    }

    /**
     * A REAL bug must still be a 500. Mapping rule violations correctly is only safe if genuine
     * faults are not swept into the same bucket - otherwise this change would hide real failures.
     */
    /** @test */
    public function a_genuine_bug_is_still_500(): void
    {
        $this->getJson('/api/__rv13/bug')
            ->assertStatus(500);
    }

    /** @test */
    public function the_hierarchy_carries_its_own_status_and_code(): void
    {
        $this->assertInstanceOf(DomainException::class, new BusinessRuleViolation('x'));
        $this->assertInstanceOf(DomainException::class, new AuthorizationViolation('x'));
        $this->assertInstanceOf(DomainException::class, new ConflictViolation('x'));

        $this->assertSame(422, (new BusinessRuleViolation('x'))->httpStatus);
        $this->assertSame(403, (new AuthorizationViolation('x'))->httpStatus);
        $this->assertSame(409, (new ConflictViolation('x'))->httpStatus);

        // Defaults are the sane ones if a subclass forgets to state a code.
        $this->assertSame('BUSINESS_RULE_VIOLATION', (new BusinessRuleViolation('x'))->errorCode);
        $this->assertSame('FORBIDDEN', (new AuthorizationViolation('x'))->errorCode);
        $this->assertSame('CONFLICT', (new ConflictViolation('x'))->errorCode);
    }
}