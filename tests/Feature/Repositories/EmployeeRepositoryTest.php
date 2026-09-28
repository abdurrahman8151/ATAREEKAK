<?php

namespace Tests\Feature\Repositories;

use App\Enums\StaffRole;
use App\Models\Employee;
use App\Repositories\EmployeeRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = app(EmployeeRepository::class);
    }

    // ─── create ────────────────────────────────────────────────────────────

    public function test_create_persists_employee_to_database(): void
    {
        $employee = $this->repo->create($this->payload('new_emp'));

        $this->assertInstanceOf(Employee::class, $employee);
        $this->assertDatabaseHas('employees', ['username' => 'new_emp']);
    }

    public function test_create_returns_employee_with_correct_role(): void
    {
        $employee = $this->repo->create($this->payload('role_emp', StaffRole::ADMIN));

        $this->assertEquals(StaffRole::ADMIN, $employee->role);
    }

    // ─── findById ──────────────────────────────────────────────────────────

    public function test_find_by_id_returns_correct_employee(): void
    {
        $created = $this->repo->create($this->payload('emp_find'));

        $found = $this->repo->findById($created->id);

        $this->assertNotNull($found);
        $this->assertEquals($created->id, $found->id);
    }

    public function test_find_by_id_returns_null_for_nonexistent(): void
    {
        $this->assertNull($this->repo->findById(999999));
    }

    // ─── findByUsername ────────────────────────────────────────────────────

    public function test_find_by_username_returns_correct_employee(): void
    {
        $this->repo->create($this->payload('unique_user'));

        $found = $this->repo->findByUsername('unique_user');

        $this->assertNotNull($found);
        $this->assertEquals('unique_user', $found->username);
    }

    public function test_find_by_username_returns_null_when_not_found(): void
    {
        $this->assertNull($this->repo->findByUsername('no_such_user'));
    }

    // ─── findByEmail ───────────────────────────────────────────────────────

    public function test_find_by_email_returns_correct_employee(): void
    {
        $this->repo->create($this->payload('em_emp', StaffRole::ADMIN, 'find@email.test'));

        $found = $this->repo->findByEmail('find@email.test');

        $this->assertNotNull($found);
        $this->assertEquals('find@email.test', $found->email);
    }

    public function test_find_by_email_returns_null_when_not_found(): void
    {
        $this->assertNull($this->repo->findByEmail('nobody@email.test'));
    }

    // ─── update ────────────────────────────────────────────────────────────

    public function test_update_changes_specified_fields(): void
    {
        $employee = $this->repo->create($this->payload('upd_emp'));

        $updated = $this->repo->update($employee->id, ['first_name' => 'Changed']);

        $this->assertEquals('Changed', $updated->first_name);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'first_name' => 'Changed']);
    }

    public function test_update_does_not_change_unspecified_fields(): void
    {
        $employee = $this->repo->create($this->payload('upd_emp2'));

        $this->repo->update($employee->id, ['first_name' => 'New']);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'username' => 'upd_emp2', // unchanged
        ]);
    }

    // ─── listAll ───────────────────────────────────────────────────────────

    public function test_list_all_returns_all_employees(): void
    {
        $this->repo->create($this->payload('list1'));
        $this->repo->create($this->payload('list2', StaffRole::SUPPORT_AGENT, 'list2@e.test'));

        $results = $this->repo->listAll();

        $this->assertGreaterThanOrEqual(2, $results->count());
    }

    public function test_list_all_returns_empty_when_no_employees(): void
    {
        $this->assertEquals(0, $this->repo->listAll()->count());
    }

    // ─── listByRole ────────────────────────────────────────────────────────

    public function test_list_by_role_returns_only_matching_role(): void
    {
        $this->repo->create($this->payload('r_admin', StaffRole::ADMIN, 'r_admin@e.test'));
        $this->repo->create($this->payload('r_agent', StaffRole::SUPPORT_AGENT, 'r_agent@e.test'));

        $admins = $this->repo->listByRole(StaffRole::ADMIN);

        $this->assertCount(1, $admins);
        $this->assertEquals(StaffRole::ADMIN, $admins->first()->role);
    }

    public function test_list_by_role_returns_empty_when_none_match(): void
    {
        $this->repo->create($this->payload('only_agent', StaffRole::SUPPORT_AGENT, 'oa@e.test'));

        $sysAdmins = $this->repo->listByRole(StaffRole::SYSTEM_ADMIN);

        $this->assertCount(0, $sysAdmins);
    }

    // ─── listManageableBy ──────────────────────────────────────────────────

    public function test_list_manageable_by_system_admin_includes_admin_and_agent(): void
    {
        $manager = $this->repo->create($this->payload('mgr', StaffRole::SYSTEM_ADMIN, 'mgr@e.test'));
        $this->repo->create($this->payload('sub_admin', StaffRole::ADMIN, 'sub_a@e.test'));
        $this->repo->create($this->payload('sub_agent', StaffRole::SUPPORT_AGENT, 'sub_ag@e.test'));

        $manageable = $this->repo->listManageableBy($manager);

        $roles = $manageable->pluck('role')->map(fn ($r) => $r->value)->toArray();

        $this->assertContains('admin', $roles);
        $this->assertContains('support_agent', $roles);
        $this->assertNotContains('system_admin', $roles);
    }

    public function test_list_manageable_by_admin_only_includes_support_agent(): void
    {
        $admin = $this->repo->create($this->payload('adm2', StaffRole::ADMIN, 'adm2@e.test'));
        $this->repo->create($this->payload('s_agent', StaffRole::SUPPORT_AGENT, 's_ag@e.test'));
        $this->repo->create($this->payload('other_admin', StaffRole::ADMIN, 'oa@e.test'));

        $manageable = $this->repo->listManageableBy($admin);

        $roles = $manageable->pluck('role')->map(fn ($r) => $r->value)->toArray();

        $this->assertContains('support_agent', $roles);
        $this->assertNotContains('admin', $roles);
    }

    public function test_list_manageable_by_support_agent_returns_empty(): void
    {
        $agent = $this->repo->create($this->payload('ag_mgr', StaffRole::SUPPORT_AGENT, 'ag_mgr@e.test'));

        $manageable = $this->repo->listManageableBy($agent);

        $this->assertCount(0, $manageable);
    }

    // ─── Helper ────────────────────────────────────────────────────────────

    private function payload(string $username, StaffRole $role = StaffRole::ADMIN, ?string $email = null): array
    {
        return [
            'username' => $username,
            'email' => $email ?? "{$username}@test.com",
            'password' => bcrypt('password123'),
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'role' => $role->value,
            'is_active' => true,
            'token_version' => 0,
        ];
    }
}
