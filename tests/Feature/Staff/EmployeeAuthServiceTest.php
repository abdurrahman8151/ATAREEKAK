<?php

namespace Tests\Feature\Staff;

use App\Enums\StaffRole;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EmployeeAuthServiceTest
 *
 * Tests the staff authentication flow via HTTP (POST /api/staff/login).
 * EmployeeAuthService is exercised through StaffAuthController.
 *
 * NOTE: The system-admin path in StaffJwtMiddleware::handleAdminToken()
 * accepts a regular admin JWT (from /api/admin/login) and grants
 * system_admin staff access – tested separately in EmployeeManagementControllerTest.
 * These tests focus on the Employee-record-based login path.
 */
class EmployeeAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private Employee $adminEmployee;

    private Employee $agentEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminEmployee = Employee::create([
            'username' => 'admin_user',
            'email' => 'admin@staff.test',
            'password' => bcrypt('admin_pass'),
            'first_name' => 'Admin',
            'last_name' => 'Staff',
            'role' => StaffRole::ADMIN->value,
            'is_active' => true,
            'token_version' => 0,
        ]);

        $this->agentEmployee = Employee::create([
            'username' => 'agent_user',
            'email' => 'agent@staff.test',
            'password' => bcrypt('agent_pass'),
            'first_name' => 'Agent',
            'last_name' => 'Staff',
            'role' => StaffRole::SUPPORT_AGENT->value,
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    // ─── login ─────────────────────────────────────────────────────────────

    public function test_employee_can_login_with_username(): void
    {
        $this->postJson('/api/staff/login', [
            'identifier' => 'admin_user',
            'password' => 'admin_pass',
        ])->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'employee',
                'tokens' => ['access_token', 'refresh_token'],
            ]);
    }

    public function test_employee_can_login_with_email(): void
    {
        $this->postJson('/api/staff/login', [
            'identifier' => 'admin@staff.test',
            'password' => 'admin_pass',
        ])->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->postJson('/api/staff/login', [
            'identifier' => 'admin_user',
            'password' => 'wrong_password',
        ])->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_login_fails_with_nonexistent_username(): void
    {
        $this->postJson('/api/staff/login', [
            'identifier' => 'nobody',
            'password' => 'password',
        ])->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_login_fails_for_inactive_employee(): void
    {
        $this->adminEmployee->update(['is_active' => false]);

        $this->postJson('/api/staff/login', [
            'identifier' => 'admin_user',
            'password' => 'admin_pass',
        ])->assertStatus(401);
    }

    public function test_login_requires_identifier_and_password(): void
    {
        $this->postJson('/api/staff/login', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_support_agent_can_also_login(): void
    {
        $this->postJson('/api/staff/login', [
            'identifier' => 'agent_user',
            'password' => 'agent_pass',
        ])->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_login_response_includes_employee_role(): void
    {
        $response = $this->postJson('/api/staff/login', [
            'identifier' => 'admin_user',
            'password' => 'admin_pass',
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('employee'));
    }

    // ─── refresh ───────────────────────────────────────────────────────────

    public function test_can_refresh_staff_access_token(): void
    {
        $loginResponse = $this->postJson('/api/staff/login', [
            'identifier' => 'admin_user',
            'password' => 'admin_pass',
        ]);

        $refreshToken = $loginResponse->json('tokens.refresh_token');

        $this->postJson('/api/staff/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertStatus(200)
            ->assertJsonStructure([
                'tokens' => ['access_token', 'refresh_token'],
            ]);
    }

    public function test_refresh_fails_with_invalid_token(): void
    {
        $this->postJson('/api/staff/refresh', [
            'refresh_token' => 'invalid_token_string',
        ])->assertStatus(401)
            ->assertJsonPath('code', 'REFRESH_TOKEN_INVALID');
    }

    // ─── logout ────────────────────────────────────────────────────────────

    public function test_authenticated_employee_can_logout(): void
    {
        $token = $this->staffToken($this->adminEmployee, 'admin_pass');

        $this->withToken($token)
            ->postJson('/api/staff/logout')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_unauthenticated_logout_returns_401(): void
    {
        $this->postJson('/api/staff/logout')->assertStatus(401);
    }

    // ─── me ────────────────────────────────────────────────────────────────

    public function test_me_returns_authenticated_employee_data(): void
    {
        $token = $this->staffToken($this->adminEmployee, 'admin_pass');

        $this->withToken($token)
            ->getJson('/api/staff/me')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['employee']);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/staff/me')->assertStatus(401);
    }

    // ─── Helper ────────────────────────────────────────────────────────────

    private function staffToken(Employee $employee, string $password): string
    {
        return $this->postJson('/api/staff/login', [
            'identifier' => $employee->username,
            'password' => $password,
        ])->json('tokens.access_token');
    }
}
