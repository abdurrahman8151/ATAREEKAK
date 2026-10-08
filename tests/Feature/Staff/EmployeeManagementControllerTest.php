<?php

namespace Tests\Feature\Staff;

use App\Enums\StaffRole;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * EmployeeManagementControllerTest
 *
 * All /api/employees routes require `staff:system_admin` (routes/api.php:495).
 *
 * TWO TOKEN STRATEGIES used here:
 *   1. Admin JWT (system_admin path): obtained from /api/admin/login. Both doors mint the SAME
 *      staff token (EmployeeAuthService:71 -> StaffJwtService::generateTokenPair), so an admin-door
 *      token is accepted by the staff middleware and vice versa.
 *   2. Staff JWT (employee path): obtained from /api/staff/login with a named Employee role.
 *
 * R2 sec 121 (owner decision 2026-10-12) - THIS CLASS PRE-DATED THREE INDEPENDENT TIGHTENINGS.
 * They are separate causes, and one "the tests are stale" note would hide two of them:
 *
 *   (a) The route guard was `staff:admin,system_admin`; it is now `staff:system_admin` ONLY. The two
 *       role tests therefore assert the DENIAL (403). That is deliberate: they are the boundary
 *       test. Re-authenticating them as system_admin would turn them green by DELETING the only
 *       coverage that an admin-role employee cannot manage employees.
 *
 *   (b) `EmployeeManagementService:153` now REQUIRES an email for `support_agent`, because the chat
 *       bridge matches Employee::email -> User::email and without one ContactController returns 503.
 *       It throws a DomainException that the controller maps to 403, so a create call omitting email
 *       fails with 403 BEFORE reaching validation - which is what made (b) look like (a).
 *
 *   (c) A duplicate username is a DomainException, mapped to 403 (EmployeeManagementController:101).
 *       409 is reserved for RuntimeException (line 103), which this path never throws.
 */
class EmployeeManagementControllerTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;

    private Employee $adminEmployee;

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

        Config::set('admin.sycash', [
            'email' => 'sycash@test.com',
            'password' => 'sycashpass',
            'first_name' => 'SyCash',
            'last_name' => 'Admin',
            'phone' => '0910000002',
            'wallet_prefix' => 'SYCSH',
            'permissions' => ['view_wallet'],
        ]);

        // A real admin employee for staff-JWT-based tests
        $this->adminEmployee = Employee::create([
            'username' => 'admin_mgr',
            'email' => 'admin_mgr@staff.test',
            'password' => bcrypt('admin_mgr_pass'),
            'first_name' => 'Admin',
            'last_name' => 'Manager',
            'role' => StaffRole::ADMIN->value,
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    // ─── index ─────────────────────────────────────────────────────────────

    public function test_system_admin_can_list_employees(): void
    {
        $this->withToken($this->adminToken())
            ->getJson('/api/employees')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data']);
    }

    public function test_staff_admin_can_no_t_list_employees(): void
    {
        // R2 sec 121: the route is `staff:system_admin` only, so an ADMIN-role employee is refused.
        // Asserting the denial is the point - see the class docblock.
        $this->withToken($this->staffToken(null, StaffRole::ADMIN))
            ->getJson('/api/employees')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_unauthenticated_cannot_list_employees(): void
    {
        $this->getJson('/api/employees')->assertStatus(401);
    }

    // ─── store ─────────────────────────────────────────────────────────────

    public function test_system_admin_can_create_support_agent(): void
    {
        // R2 sec 121: `email` is required for support_agent (EmployeeManagementService:153).
        $this->withToken($this->adminToken())
            ->postJson('/api/employees', [
                'username' => 'new_agent',
                'email' => 'new_agent@staff.test',
                'password' => 'password123',
                'first_name' => 'New',
                'last_name' => 'Agent',
                'role' => 'support_agent',
            ])->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('employees', ['username' => 'new_agent']);
    }

    public function test_system_admin_can_create_admin(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/employees', [
                'username' => 'new_admin',
                'password' => 'password123',
                'first_name' => 'New',
                'last_name' => 'Admin',
                'role' => 'admin',
            ])->assertStatus(201);
    }

    public function test_store_fails_with_missing_required_fields(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/employees', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_store_fails_with_duplicate_username(): void
    {
        // R2 sec 121: TWO stale expectations here, not one. The email is needed so this reaches
        // the duplicate check instead of stopping at the support_agent email guard, and the code is
        // 403 because the duplicate guard throws DomainException, mapped at
        // EmployeeManagementController:101 (409 is reserved for RuntimeException on line 103).
        $this->withToken($this->adminToken())
            ->postJson('/api/employees', [
                'username' => 'admin_mgr', // already exists
                'email' => 'dup@staff.test',
                'password' => 'password123',
                'first_name' => 'Dup',
                'last_name' => 'User',
                'role' => 'support_agent',
            ])->assertStatus(403)
            ->assertJsonPath('message', "Username 'admin_mgr' is already taken.");
    }

    public function test_store_fails_with_invalid_role(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/employees', [
                'username' => 'tricky',
                'password' => 'password123',
                'first_name' => 'Tricky',
                'last_name' => 'Role',
                'role' => 'god_mode',
            ])->assertStatus(422);
    }

    public function test_admin_employee_cannot_create_system_admin(): void
    {
        // R2 sec 121: an ADMIN-role employee cannot even reach the handler - the route requires
        // system_admin, so the answer is 403 FORBIDDEN, not a validation error.
        $this->withToken($this->staffToken(null, StaffRole::ADMIN))
            ->postJson('/api/employees', [
                'username' => 'new_sysadmin',
                'email' => 'new_sysadmin@staff.test',
                'password' => 'password123',
                'first_name' => 'New',
                'last_name' => 'SysAdmin',
                'role' => 'system_admin',
            ])->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertDatabaseMissing('employees', ['username' => 'new_sysadmin']);
    }

    // ─── show ──────────────────────────────────────────────────────────────

    public function test_system_admin_can_view_employee(): void
    {
        $this->withToken($this->adminToken())
            ->getJson("/api/employees/{$this->adminEmployee->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_show_returns_404_for_nonexistent_employee(): void
    {
        $this->withToken($this->adminToken())
            ->getJson('/api/employees/999999')
            ->assertStatus(404);
    }

    // ─── update ────────────────────────────────────────────────────────────

    public function test_system_admin_can_update_employee(): void
    {
        $agent = $this->makeAgent();

        $this->withToken($this->adminToken())
            ->putJson("/api/employees/{$agent->id}", [
                'first_name' => 'Updated',
                'last_name' => 'Name',
            ])->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('employees', [
            'id' => $agent->id,
            'first_name' => 'Updated',
        ]);
    }

    // ─── toggle-active ─────────────────────────────────────────────────────

    public function test_system_admin_can_toggle_employee_active_status(): void
    {
        $agent = $this->makeAgent();
        $this->assertTrue($agent->is_active);

        $this->withToken($this->adminToken())
            ->patchJson("/api/employees/{$agent->id}/toggle-active")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertFalse((bool) $agent->fresh()->is_active);
    }

    // ─── reset-password ────────────────────────────────────────────────────

    public function test_system_admin_can_reset_employee_password(): void
    {
        $agent = $this->makeAgent();

        $this->withToken($this->adminToken())
            ->patchJson("/api/employees/{$agent->id}/reset-password", [
                'new_password' => 'newSecurePass123',
            ])->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_reset_password_fails_with_short_password(): void
    {
        $agent = $this->makeAgent();

        $this->withToken($this->adminToken())
            ->patchJson("/api/employees/{$agent->id}/reset-password", [
                'new_password' => 'short',
            ])->assertStatus(422);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    // RV-34: the two local token helpers are gone.
    //   - adminJwt() posted config('admin.system_admin') email/password to
    //     /api/admin/login; admin auth is username-based now and
    //     config/admin.php only carries phone + wallet_prefix → null token.
    //     The system_admin call sites now use $this->adminToken() (trait).
    //   - staffToken() hard-coded the 'admin_mgr' Employee built in setUp();
    //     the call sites now use $this->staffToken(null, StaffRole::ADMIN)
    //     (trait), which mints a fresh ADMIN-role Employee with the same role
    //     the old helper relied on. $this->adminEmployee stays in setUp because
    //     test_system_admin_can_view_employee() asserts against it.

    private function makeAgent(): Employee
    {
        static $n = 0;
        $n++;

        return Employee::create([
            'username' => "agent_{$n}",
            'email' => "agent{$n}@staff.test",
            'password' => bcrypt('pass123'),
            'first_name' => 'Test',
            'last_name' => 'Agent',
            'role' => StaffRole::SUPPORT_AGENT->value,
            'is_active' => true,
            'token_version' => 0,
        ]);
    }
}
