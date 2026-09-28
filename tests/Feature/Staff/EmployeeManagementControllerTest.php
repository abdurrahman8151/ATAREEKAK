<?php

namespace Tests\Feature\Staff;

use App\Enums\StaffRole;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * EmployeeManagementControllerTest
 *
 * All /api/employees routes require staff:admin,system_admin middleware.
 *
 * TWO TOKEN STRATEGIES used here:
 *   1. Admin JWT (system_admin path): obtained from /api/admin/login.
 *      StaffJwtMiddleware::handleAdminToken() grants SYSTEM_ADMIN access.
 *   2. Staff JWT (employee path): obtained from /api/staff/login with
 *      an Employee record that has ADMIN role.
 *
 * Known: system_admin can manage both admin and support_agent employees.
 * Admin employees can only manage support_agent employees.
 */
class EmployeeManagementControllerTest extends TestCase
{
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
        $this->withToken($this->adminJwt())
            ->getJson('/api/employees')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data']);
    }

    public function test_staff_admin_can_list_employees(): void
    {
        $this->withToken($this->staffToken())
            ->getJson('/api/employees')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_unauthenticated_cannot_list_employees(): void
    {
        $this->getJson('/api/employees')->assertStatus(401);
    }

    // ─── store ─────────────────────────────────────────────────────────────

    public function test_system_admin_can_create_support_agent(): void
    {
        $this->withToken($this->adminJwt())
            ->postJson('/api/employees', [
                'username' => 'new_agent',
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
        $this->withToken($this->adminJwt())
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
        $this->withToken($this->adminJwt())
            ->postJson('/api/employees', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_store_fails_with_duplicate_username(): void
    {
        $this->withToken($this->adminJwt())
            ->postJson('/api/employees', [
                'username' => 'admin_mgr', // already exists
                'password' => 'password123',
                'first_name' => 'Dup',
                'last_name' => 'User',
                'role' => 'support_agent',
            ])->assertStatus(409);
    }

    public function test_store_fails_with_invalid_role(): void
    {
        $this->withToken($this->adminJwt())
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
        // admin role level < system_admin — forbidden
        $this->withToken($this->staffToken())
            ->postJson('/api/employees', [
                'username' => 'new_sysadmin',
                'password' => 'password123',
                'first_name' => 'New',
                'last_name' => 'SysAdmin',
                'role' => 'system_admin',
            ])->assertStatus(422); // DomainException → 403 or 422
    }

    // ─── show ──────────────────────────────────────────────────────────────

    public function test_system_admin_can_view_employee(): void
    {
        $this->withToken($this->adminJwt())
            ->getJson("/api/employees/{$this->adminEmployee->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_show_returns_404_for_nonexistent_employee(): void
    {
        $this->withToken($this->adminJwt())
            ->getJson('/api/employees/999999')
            ->assertStatus(404);
    }

    // ─── update ────────────────────────────────────────────────────────────

    public function test_system_admin_can_update_employee(): void
    {
        $agent = $this->makeAgent();

        $this->withToken($this->adminJwt())
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

        $this->withToken($this->adminJwt())
            ->patchJson("/api/employees/{$agent->id}/toggle-active")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertFalse((bool) $agent->fresh()->is_active);
    }

    // ─── reset-password ────────────────────────────────────────────────────

    public function test_system_admin_can_reset_employee_password(): void
    {
        $agent = $this->makeAgent();

        $this->withToken($this->adminJwt())
            ->patchJson("/api/employees/{$agent->id}/reset-password", [
                'new_password' => 'newSecurePass123',
            ])->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_reset_password_fails_with_short_password(): void
    {
        $agent = $this->makeAgent();

        $this->withToken($this->adminJwt())
            ->patchJson("/api/employees/{$agent->id}/reset-password", [
                'new_password' => 'short',
            ])->assertStatus(422);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function adminJwt(): string
    {
        return $this->postJson('/api/admin/login', [
            'email' => 'sysadmin@test.com',
            'password' => 'syspass',
        ])->json('tokens.access_token');
    }

    private function staffToken(): string
    {
        return $this->postJson('/api/staff/login', [
            'identifier' => 'admin_mgr',
            'password' => 'admin_mgr_pass',
        ])->json('tokens.access_token');
    }

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
