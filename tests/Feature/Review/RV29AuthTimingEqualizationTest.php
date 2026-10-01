<?php

namespace Tests\Feature\Review;

use App\Enums\StaffRole;
use App\Models\Employee;
use App\Services\Admin\AdminAuthService;
use App\Services\Staff\EmployeeAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * RV-29 (auth timing oracle) — staff and admin login must not leak, through TIMING, whether an
 * account exists.
 *
 * The defect: both authenticate() implementations returned as soon as the login identifier was
 * not found, BEFORE any Hash::check. bcrypt (cost 12) is deliberately expensive (~100ms), so the
 * "no such account" path was an order of magnitude faster than the "wrong password" path. An
 * attacker could therefore enumerate valid usernames/emails by measuring responses, even though
 * the HTTP layer already returned a uniform 401 with the same body.
 *
 * The fix spends equivalent work on the unknown-identifier path by comparing the submitted
 * password against a fixed dummy hash that can never authenticate anyone.
 *
 * TIMING IS FLAKY TO ASSERT DIRECTLY, so this test proves the MECHANISM instead: the expensive
 * hash comparison must run on the unknown-identifier path. If the dummy Hash::check is removed,
 * `Hash::check` is never called for an unknown identifier and this test fails. A separate
 * behavioural assertion confirms the path still returns null (no auth is ever granted).
 */
class RV29AuthTimingEqualizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a counting decorator over the REAL hasher for the current test.
     *
     * The Hash facade resolves to $app['hash'] and calls ->check()/->make() on it, so binding
     * this object swaps the hasher while still forwarding to the real implementation - the real
     * bcrypt work really happens, we only observe that it happened. (A Mockery facade mock would
     * replace the behaviour rather than measure it.)
     */
    private function spyOnHashing(): object
    {
        $inner = app('hash');

        $spy = new class($inner)
        {
            public int $checks = 0;

            public function __construct(private object $inner) {}

            public function check($value, $hashedValue, array $options = [])
            {
                $this->checks++;

                return $this->inner->check($value, $hashedValue, $options);
            }

            public function make($value, array $options = [])
            {
                return $this->inner->make($value, $options);
            }

            public function info($hashedValue)
            {
                return $this->inner->info($hashedValue);
            }

            public function needsRehash($hashedValue, array $options = [])
            {
                return $this->inner->needsRehash($hashedValue, $options);
            }

            public function __call($method, $args)
            {
                return $this->inner->{$method}(...$args);
            }
        };

        app()->instance('hash', $spy);

        return $spy;
    }

    /** @test */
    public function staff_login_does_the_hash_work_even_for_an_unknown_identifier(): void
    {
        $service = app(EmployeeAuthService::class);
        $spy = $this->spyOnHashing();

        $result = $service->authenticate('definitely-not-an-employee', 'some-password');

        // Correctness first: an unknown identifier must NOT authenticate.
        $this->assertNull($result, 'an unknown identifier must never authenticate');

        // The mechanism: the expensive comparison ran even though no employee exists.
        $this->assertGreaterThanOrEqual(1, $spy->checks,
            'RV-29: the unknown-identifier path MUST run check() (against the dummy hash) so '
            .'login timing does not reveal whether the account exists');
    }

    /** @test */
    public function staff_login_with_a_known_employee_and_wrong_password_still_fails(): void
    {
        $employee = Employee::create([
            'username' => 'timing_employee',
            'email' => 'timing_employee@example.com',
            'password' => Hash::make('correct-password'),
            'first_name' => 'Timing',
            'last_name' => 'Employee',
            'role' => StaffRole::SUPPORT_AGENT,
            'is_active' => true,
        ]);

        $result = app(EmployeeAuthService::class)->authenticate('timing_employee', 'wrong-password');

        $this->assertNull($result, 'a wrong password must still be rejected');
    }

    /** @test */
    public function admin_login_does_the_hash_work_even_for_an_unknown_identifier(): void
    {
        $service = app(AdminAuthService::class);
        $spy = $this->spyOnHashing();

        $result = $service->authenticate('definitely-not-an-admin', 'some-password');

        $this->assertNull($result, 'an unknown identifier must never authenticate');

        $this->assertGreaterThanOrEqual(1, $spy->checks,
            'RV-29: the admin unknown-identifier path MUST run check() (against the dummy '
            .'hash) so admin login timing does not reveal whether the account exists');
    }
}
