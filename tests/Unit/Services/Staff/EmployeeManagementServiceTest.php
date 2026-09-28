<?php

namespace Services\Staff;

use App\Enums\StaffRole;
use App\Models\Employee;
use App\Services\Staff\EmployeeManagementService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * EmployeeManagementServiceTest
 *
 * Tests EmployeeManagementService public methods directly.
 * All methods respect StaffRole hierarchy — a manager can only act
 * on roles strictly below their own level.
 */
class EmployeeManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeManagementService $service;

    private Employee $sysAdmin;

    private Employee $adminEmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EmployeeManagementService::class);
        $this->sysAdmin = $this->make(StaffRole::SYSTEM_ADMIN, 'sysadmin', 'sysadmin@s.test');
        $this->adminEmp = $this->make(StaffRole::ADMIN, 'adminuser', 'admin@s.test', $this->sysAdmin->id);
    }

    // ─── list ──────────────────────────────────────────────────────────────

    public function test_list_returns_collection(): void
    {
        $result = $this->service->list($this->sysAdmin);
        $this->assertNotNull($result);
    }

    public function test_list_excludes_caller_themselves(): void
    {
        $agents = collect();
        for ($i = 0; $i < 3; $i++) {
            $agents->push($this->make(StaffRole::SUPPORT_AGENT, "agent{$i}", "agent{$i}@s.test"));
        }

        $result = $this->service->list($this->sysAdmin);
        $ids = collect($result)->pluck('id')->toArray();

        $this->assertNotContains($this->sysAdmin->id, $ids);
    }

    // ─── create ────────────────────────────────────────────────────────────

    public function test_create_makes_new_employee(): void
    {
        $employee = $this->service->create([
            'username' => 'brand_new',
            'password' => 'password123',
            'first_name' => 'Brand',
            'last_name' => 'New',
            'role' => 'support_agent',
        ], $this->sysAdmin);

        $this->assertInstanceOf(Employee::class, $employee);
        $this->assertDatabaseHas('employees', ['username' => 'brand_new']);
    }

    public function test_create_throws_when_role_not_manageable(): void
    {
        // admin cannot create system_admin
        $this->expectException(\DomainException::class);

        $this->service->create([
            'username' => 'sneaky_admin',
            'password' => 'password123',
            'first_name' => 'Sneaky',
            'last_name' => 'User',
            'role' => 'system_admin',
        ], $this->adminEmp);
    }

    public function test_create_throws_on_duplicate_username(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->create([
            'username' => 'sysadmin', // already exists
            'password' => 'password123',
            'first_name' => 'Dup',
            'last_name' => 'User',
            'role' => 'support_agent',
        ], $this->sysAdmin);
    }

    // ─── getById ───────────────────────────────────────────────────────────

    public function test_get_by_id_returns_correct_employee(): void
    {
        $agent = $this->make(StaffRole::SUPPORT_AGENT, 'myagent', 'myagent@s.test');

        $result = $this->service->getById($agent->id, $this->sysAdmin);

        $this->assertEquals($agent->id, $result->id);
    }

    public function test_get_by_id_throws_for_nonexistent(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->getById(999999, $this->sysAdmin);
    }

    public function test_get_by_id_throws_when_role_not_viewable(): void
    {
        // support_agent trying to view admin (higher level)
        $agent = $this->make(StaffRole::SUPPORT_AGENT, 'myagent2', 'myagent2@s.test');

        $this->expectException(\DomainException::class);
        $this->service->getById($this->sysAdmin->id, $agent);
    }

    // ─── update ────────────────────────────────────────────────────────────

    public function test_update_changes_employee_fields(): void
    {
        $agent = $this->make(StaffRole::SUPPORT_AGENT, 'updatable', 'upd@s.test');

        $updated = $this->service->update($agent->id, [
            'first_name' => 'Changed',
        ], $this->sysAdmin);

        $this->assertEquals('Changed', $updated->first_name);
        $this->assertDatabaseHas('employees', ['id' => $agent->id, 'first_name' => 'Changed']);
    }

    // ─── toggleActive ──────────────────────────────────────────────────────

    public function test_toggle_active_deactivates_active_employee(): void
    {
        $agent = $this->make(StaffRole::SUPPORT_AGENT, 'togg1', 'togg1@s.test');
        $this->assertTrue($agent->is_active);

        $result = $this->service->toggleActive($agent->id, $this->sysAdmin);

        $this->assertFalse($result->is_active);
    }

    public function test_toggle_active_reactivates_inactive_employee(): void
    {
        $agent = $this->make(StaffRole::SUPPORT_AGENT, 'togg2', 'togg2@s.test');
        $agent->update(['is_active' => false]);

        $result = $this->service->toggleActive($agent->id, $this->sysAdmin);

        $this->assertTrue($result->is_active);
    }

    // ─── resetPassword ─────────────────────────────────────────────────────

    public function test_reset_password_changes_stored_hash(): void
    {
        $agent = $this->make(StaffRole::SUPPORT_AGENT, 'rp_agent', 'rp@s.test');

        $this->service->resetPassword($agent->id, 'brand_new_pass_123', $this->sysAdmin);

        $this->assertTrue(
            Hash::check('brand_new_pass_123', $agent->fresh()->password)
        );
    }

    public function test_reset_password_invalidates_existing_tokens(): void
    {
        $agent = $this->make(StaffRole::SUPPORT_AGENT, 'rp_agent2', 'rp2@s.test');
        $oldVersion = $agent->token_version;

        $this->service->resetPassword($agent->id, 'new_password_123', $this->sysAdmin);

        // token_version must be bumped so old tokens are rejected
        $this->assertGreaterThan($oldVersion, (int) $agent->fresh()->token_version);
    }

    // ─── formatEmployee ────────────────────────────────────────────────────

    public function test_format_employee_returns_array_with_required_keys(): void
    {
        $agent = $this->make(StaffRole::SUPPORT_AGENT, 'fmt_agent', 'fmt@s.test');

        $result = $this->service->formatEmployee($agent);

        foreach (['id', 'username', 'role', 'is_active', 'first_name', 'last_name'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_format_employee_password_is_not_exposed(): void
    {
        $agent = $this->make(StaffRole::SUPPORT_AGENT, 'fmt_agent2', 'fmt2@s.test');

        $result = $this->service->formatEmployee($agent);

        $this->assertArrayNotHasKey('password', $result);
    }

    // ─── Helper ────────────────────────────────────────────────────────────

    private function make(StaffRole $role, string $username, string $email, ?int $createdBy = null): Employee
    {
        return Employee::create([
            'username' => $username,
            'email' => $email,
            'password' => bcrypt('password123'),
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'role' => $role->value,
            'is_active' => true,
            'token_version' => 0,
            'created_by' => $createdBy,
        ]);
    }
}
