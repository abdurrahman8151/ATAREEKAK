<?php

namespace Tests\Unit\Models;

use App\Enums\StaffRole;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    // ─── Fillable ──────────────────────────────────────────────────────────

    public function test_fillable_contains_username(): void
    {
        $this->assertContains('username', (new Employee)->getFillable());
    }

    public function test_fillable_contains_email(): void
    {
        $this->assertContains('email', (new Employee)->getFillable());
    }

    public function test_fillable_contains_role(): void
    {
        $this->assertContains('role', (new Employee)->getFillable());
    }

    public function test_fillable_contains_is_active(): void
    {
        $this->assertContains('is_active', (new Employee)->getFillable());
    }

    public function test_fillable_contains_token_version(): void
    {
        $this->assertContains('token_version', (new Employee)->getFillable());
    }

    // ─── Casts ─────────────────────────────────────────────────────────────

    public function test_role_is_cast_to_staff_role_enum(): void
    {
        $casts = (new Employee)->getCasts();
        $this->assertArrayHasKey('role', $casts);
        $this->assertEquals(StaffRole::class, $casts['role']);
    }

    public function test_is_active_is_cast_to_boolean(): void
    {
        $this->assertEquals('boolean', (new Employee)->getCasts()['is_active']);
    }

    public function test_token_version_is_cast_to_integer(): void
    {
        $this->assertEquals('integer', (new Employee)->getCasts()['token_version']);
    }

    public function test_last_login_at_is_cast_to_datetime(): void
    {
        $this->assertEquals('datetime', (new Employee)->getCasts()['last_login_at']);
    }

    // ─── Relationships ─────────────────────────────────────────────────────

    public function test_has_creator_relationship(): void
    {
        $this->assertTrue(method_exists(Employee::class, 'creator'));
    }

    public function test_has_managed_employees_relationship(): void
    {
        $this->assertTrue(method_exists(Employee::class, 'managedEmployees'));
    }

    public function test_has_refresh_tokens_relationship(): void
    {
        $this->assertTrue(method_exists(Employee::class, 'refreshTokens'));
    }

    public function test_creator_relationship_points_to_employee(): void
    {
        $manager = $this->makeEmployee(StaffRole::SYSTEM_ADMIN);
        $agent = $this->makeEmployee(StaffRole::SUPPORT_AGENT, $manager->id);

        $this->assertEquals($manager->id, $agent->creator->id);
    }

    public function test_managed_employees_returns_correct_employees(): void
    {
        $manager = $this->makeEmployee(StaffRole::ADMIN);
        $agent = $this->makeEmployee(StaffRole::SUPPORT_AGENT, $manager->id);

        $this->assertTrue($manager->managedEmployees->contains($agent));
    }

    // ─── Role helpers ──────────────────────────────────────────────────────

    public function test_is_system_admin_returns_true_for_system_admin(): void
    {
        $emp = $this->makeEmployee(StaffRole::SYSTEM_ADMIN);
        $this->assertTrue($emp->isSystemAdmin());
    }

    public function test_is_system_admin_returns_false_for_admin(): void
    {
        $emp = $this->makeEmployee(StaffRole::ADMIN);
        $this->assertFalse($emp->isSystemAdmin());
    }

    public function test_is_admin_returns_true_for_admin(): void
    {
        $emp = $this->makeEmployee(StaffRole::ADMIN);
        $this->assertTrue($emp->isAdmin());
    }

    public function test_is_admin_returns_false_for_support_agent(): void
    {
        $emp = $this->makeEmployee(StaffRole::SUPPORT_AGENT);
        $this->assertFalse($emp->isAdmin());
    }

    public function test_is_support_agent_returns_true(): void
    {
        $emp = $this->makeEmployee(StaffRole::SUPPORT_AGENT);
        $this->assertTrue($emp->isSupportAgent());
    }

    public function test_is_support_agent_returns_false_for_system_admin(): void
    {
        $emp = $this->makeEmployee(StaffRole::SYSTEM_ADMIN);
        $this->assertFalse($emp->isSupportAgent());
    }

    // ─── canManage ─────────────────────────────────────────────────────────

    public function test_system_admin_can_manage_admin(): void
    {
        $sysAdmin = $this->makeEmployee(StaffRole::SYSTEM_ADMIN);
        $admin = $this->makeEmployee(StaffRole::ADMIN);

        $this->assertTrue($sysAdmin->canManage($admin));
    }

    public function test_system_admin_can_manage_support_agent(): void
    {
        $sysAdmin = $this->makeEmployee(StaffRole::SYSTEM_ADMIN);
        $agent = $this->makeEmployee(StaffRole::SUPPORT_AGENT);

        $this->assertTrue($sysAdmin->canManage($agent));
    }

    public function test_admin_can_manage_support_agent(): void
    {
        $admin = $this->makeEmployee(StaffRole::ADMIN);
        $agent = $this->makeEmployee(StaffRole::SUPPORT_AGENT);

        $this->assertTrue($admin->canManage($agent));
    }

    public function test_admin_cannot_manage_system_admin(): void
    {
        $admin = $this->makeEmployee(StaffRole::ADMIN);
        $sysAdmin = $this->makeEmployee(StaffRole::SYSTEM_ADMIN);

        $this->assertFalse($admin->canManage($sysAdmin));
    }

    public function test_support_agent_cannot_manage_anyone(): void
    {
        $agent = $this->makeEmployee(StaffRole::SUPPORT_AGENT);
        $other = $this->makeEmployee(StaffRole::SUPPORT_AGENT);

        $this->assertFalse($agent->canManage($other));
    }

    public function test_employee_cannot_manage_themselves(): void
    {
        $sysAdmin = $this->makeEmployee(StaffRole::SYSTEM_ADMIN);

        $this->assertFalse($sysAdmin->canManage($sysAdmin));
    }

    // ─── fullName ──────────────────────────────────────────────────────────

    public function test_full_name_concatenates_first_and_last(): void
    {
        $emp = $this->makeEmployee(StaffRole::ADMIN, null, 'Zaid', 'Alzoubi');

        $this->assertEquals('Zaid Alzoubi', $emp->fullName());
    }

    public function test_full_name_trims_whitespace(): void
    {
        $emp = $this->makeEmployee(StaffRole::ADMIN, null, 'Ahmad', 'Ali');

        $this->assertStringNotContainsString('  ', $emp->fullName());
    }

    // ─── Persistence ───────────────────────────────────────────────────────

    public function test_employee_can_be_saved_and_retrieved(): void
    {
        $emp = $this->makeEmployee(StaffRole::SUPPORT_AGENT);

        $this->assertDatabaseHas('employees', [
            'id' => $emp->id,
            'username' => $emp->username,
        ]);
    }

    public function test_password_is_hidden_from_serialization(): void
    {
        $emp = $this->makeEmployee(StaffRole::ADMIN);

        $this->assertArrayNotHasKey('password', $emp->toArray());
    }

    // ─── Helper ────────────────────────────────────────────────────────────

    private function makeEmployee(
        StaffRole $role,
        ?int $createdBy = null,
        string $firstName = 'Test',
        string $lastName = 'Employee'
    ): Employee {
        static $counter = 0;
        $counter++;

        return Employee::create([
            'username' => "user_{$counter}",
            'email' => "emp{$counter}@test.com",
            'password' => bcrypt('password123'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => $role->value,
            'is_active' => true,
            'token_version' => 0,
            'created_by' => $createdBy,
        ]);
    }
}
