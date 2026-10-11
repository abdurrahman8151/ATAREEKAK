<?php

namespace Tests\Feature\Review;

use App\Enums\StaffRole;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * RV-45 evidence: a DomainException refusal from EmployeeManagementService must reach the client as
 * a curated 403 with the real sentence, not the generic 500.
 *
 * Refusals under test (EmployeeManagementService):
 *   - rotatePassword() :287 - a restricted role's password cannot be rotated through the API
 *   - toggleActive()   :317 - a restricted role's account cannot be deactivated through the API
 *
 * The controller maps \DomainException to 403 with the message (toggleActive :193, resetPassword
 * :230). The allowed path (a non-restricted target) must still succeed, so the refusal is proven to
 * depend on the role, not on the route.
 */
class RV45RestrictedRoleRefusalTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('admin.system_admin', [
            'email' => 'sysadmin@test.com',
            'password' => 'syspass',
            'username' => 'sysadmin',
            'first_name' => 'System',
            'last_name' => 'Admin',
            'phone' => '0910000001',
            'wallet_prefix' => 'SYS',
            'permissions' => ['*'],
        ]);
    }

    public function test_toggle_active_on_a_restricted_role_is_a_curated_403(): void
    {
        $sycash = $this->makeEmployee(StaffRole::SYCASH, 'sycash_target');

        $response = $this->withToken($this->adminToken())
            ->patchJson("/api/employees/{$sycash->id}/toggle-active");

        $response->assertStatus(403)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Your role (System Administrator) cannot view this account.');

        $this->assertTrue((bool) $sycash->fresh()->is_active, 'A refused deactivation must not change the account.');
    }

    public function test_reset_password_on_a_restricted_role_is_a_curated_403(): void
    {
        $sycash = $this->makeEmployee(StaffRole::SYCASH, 'sycash_pw_target');
        $hashBefore = $sycash->fresh()->password;

        $response = $this->withToken($this->adminToken())
            ->patchJson("/api/employees/{$sycash->id}/reset-password", [
                'new_password' => 'newSecurePass123',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('status', 'error');
        $this->assertSame('Your role (System Administrator) cannot view this account.', (string) $response->json('message'));

        $this->assertSame($hashBefore, $sycash->fresh()->password, 'A refused rotation must not change the password.');
    }

    public function test_toggle_active_on_a_non_restricted_role_still_succeeds(): void
    {
        $agent = $this->makeEmployee(StaffRole::SUPPORT_AGENT, 'agent_allowed_target');

        $this->withToken($this->adminToken())
            ->patchJson("/api/employees/{$agent->id}/toggle-active")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertFalse((bool) $agent->fresh()->is_active);
    }

    private function makeEmployee(StaffRole $role, string $username): Employee
    {
        return Employee::create([
            'username' => $username,
            'email' => "{$username}@staff.test",
            'password' => bcrypt('pass123'),
            'first_name' => 'Test',
            'last_name' => 'Target',
            'role' => $role->value,
            'is_active' => true,
            'token_version' => 0,
        ]);
    }
}
