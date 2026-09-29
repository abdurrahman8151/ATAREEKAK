<?php

namespace Tests\Feature\Review;

use App\Models\Employee;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RV-04 (Review R2 §1.3) — the MISSING direction of the staff/user token split.
 *
 * The suite already covers user-token → staff-route (`StaffAdminIdentityAttributionTest
 * ::test_user_token_is_rejected_by_staff_guard`). The reverse was never tested and is
 * broken: user and staff tokens are signed with the SAME secret, both carry
 * `type=access`, and `JwtAuthMiddleware` checks nothing but `type` — so a staff access
 * token whose `sub` (employee id) collides with a user id and whose `ver` matches that
 * user's `token_version` is accepted as that user.
 *
 * Written first, expected to FAIL before the fix (R2 asks for exactly this), then kept
 * as the pin for the audience separation.
 */
class StaffTokenAudienceTest extends TestCase
{
    use RefreshDatabase;

    /** Log an employee in and return [employee, access_token]. */
    private function staffToken(): array
    {
        $employee = Employee::create([
            'username' => 'rv04_'.uniqid(),
            'email' => 'rv04_'.uniqid().'@test.com',
            'password' => 'Password123!',
            'first_name' => 'Rv04',
            'last_name' => 'Tester',
            'role' => 'system_admin',
            'is_active' => true,
            'token_version' => 1,
        ]);

        $token = $this->postJson('/api/staff/login', [
            'identifier' => $employee->username,
            'password' => 'Password123!',
        ])->json('tokens.access_token');

        $this->assertNotEmpty($token, 'staff login must yield an access token');

        return [$employee, $token];
    }

    public function test_staff_token_is_rejected_by_the_user_guard(): void
    {
        [$employee, $staffToken] = $this->staffToken();

        // Make the collision real: a user whose id equals the employee id and whose
        // token_version equals the employee's `ver` claim. This is the exact condition
        // R2 §1.3 describes (users default 1, UserFactory sets 0, employees 0 or 1).
        // The id is set at INSERT time: re-pointing a created row's primary key
        // breaks the users->profiles FK on teardown.
        User::factory()->create([
            'id' => $employee->id,
            'status' => 1,
            'token_version' => 1,
        ]);

        $response = $this->withToken($staffToken)->getJson('/api/user');

        $this->assertSame(
            401,
            $response->status(),
            'RV-04: a staff token must not authenticate as user #'.$employee->id
            .' on a user-only route'
        );
    }

    public function test_user_token_is_still_accepted_by_the_user_guard(): void
    {
        $user = User::factory()->create(['status' => 1]);
        $token = app(JwtService::class)->generateTokenPair($user)['access_token'];

        $this->withToken($token)->getJson('/api/user')->assertOk();
    }

    public function test_production_boot_refuses_an_empty_jwt_secret(): void
    {
        // The guard lives in AppServiceProvider::boot(); re-run it under a
        // production env with a blank secret and require the failure.
        $this->app['env'] = 'production';
        config(['jwt.secret' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/JWT_SECRET must be set/');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_production_boot_refuses_a_short_jwt_secret(): void
    {
        $this->app['env'] = 'production';
        config(['jwt.secret' => 'too-short-to-be-safe']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/at least 32 bytes/');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_testing_environment_is_exempt_from_the_secret_guard(): void
    {
        // The suite itself runs on a dummy secret; the guard must not fire here.
        config(['jwt.secret' => 'short']);
        $this->app['env'] = 'testing';

        (new AppServiceProvider($this->app))->boot();

        $this->assertTrue(true, 'testing must stay exempt');
    }
}
