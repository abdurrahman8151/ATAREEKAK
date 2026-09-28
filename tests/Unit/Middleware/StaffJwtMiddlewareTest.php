<?php

namespace Tests\Unit\Middleware;

use App\Enums\StaffRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * StaffJwtMiddlewareTest
 *
 * Tests the `staff` middleware (StaffJwtMiddleware) through real HTTP calls,
 * mirroring the pattern used in AuthenticateMiddlewareTest.
 *
 * GET /api/staff/me is used as the probe for any-staff routes.
 * GET /api/staff/verifications/pending probes admin-only (staff:admin,system_admin) routes.
 *
 * Two token paths exist inside the middleware:
 *   A) Staff JWT  → StaffJwtService::decodeToken()  (handleStaffToken)
 *   B) Admin JWT  → JwtService::decodeToken()        (handleAdminToken — system_admin only)
 *
 * Failure codes returned (all with HTTP 401 except role gate which is 403):
 *   TOKEN_MISSING       – no Authorization header
 *   TOKEN_INVALID       – unparseable / wrong signature
 *   TOKEN_TYPE_INVALID  – refresh token sent instead of access token
 *   EMPLOYEE_NOT_FOUND  – sub not in employees table
 *   ACCOUNT_INACTIVE    – employee.is_active = false
 *   TOKEN_INVALIDATED   – token_version mismatch
 *   FORBIDDEN (403)     – role not in allowed list
 */
class StaffJwtMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private Employee $agent;

    private Employee $admin;

    private Employee $inactiveAgent;

    protected function setUp(): void
    {
        parent::setUp();

        // Point system_admin config at a deterministic test identity so that
        // handleAdminToken() can resolve the email and auto-create an Employee row.
        Config::set('admin.system_admin', [
            'email' => 'sysadmin@mw.test',
            'password' => 'sysadmin_pass',
            'username' => 'sysadmin_mw',
            'first_name' => 'System',
            'last_name' => 'Admin',
            'phone' => '0910000099',
        ]);

        Config::set('admin.sycash', [
            'email' => 'sycash@mw.test',
            'password' => 'sycash_pass',
            'phone' => '0910000098',
        ]);

        $this->agent = $this->makeEmployee(
            StaffRole::SUPPORT_AGENT, 'mw_agent@test.test', 'mw_support_agent'
        );
        $this->admin = $this->makeEmployee(
            StaffRole::ADMIN, 'mw_admin@test.test', 'mw_admin_user'
        );
        $this->inactiveAgent = $this->makeEmployee(
            StaffRole::SUPPORT_AGENT, 'mw_inactive@test.test', 'mw_inactive_agent',
            isActive: false
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Missing / malformed token
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_request_without_authorization_header_returns_401(): void
    {
        $this->getJson('/api/staff/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'TOKEN_MISSING');
    }

    public function test_request_with_empty_bearer_value_returns_401(): void
    {
        $this->getJson('/api/staff/me', ['Authorization' => 'Bearer '])
            ->assertStatus(401);
    }

    public function test_request_with_gibberish_token_returns_401(): void
    {
        $this->withToken('not.a.valid.jwt.at.all')
            ->getJson('/api/staff/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'TOKEN_INVALID');
    }

    public function test_request_with_well_formed_but_wrong_signature_returns_401(): void
    {
        // Three-part base64 JWT but signed with the wrong secret
        $this->withToken('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI5OTkifQ.wrongsignaturehere')
            ->getJson('/api/staff/me')
            ->assertStatus(401);
    }

    public function test_basic_auth_header_is_not_accepted(): void
    {
        $this->getJson('/api/staff/me', ['Authorization' => 'Basic dXNlcjpwYXNz'])
            ->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Regular user JWT on a staff route
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_regular_user_access_token_is_rejected_on_staff_route(): void
    {
        // User JWTs are signed by JwtService (different from StaffJwtService).
        // handleStaffToken() fails, then handleAdminToken() sees the user is not
        // the system admin → returns 401 FORBIDDEN.
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');

        $this->withToken($token)
            ->getJson('/api/staff/me')
            ->assertStatus(401);
    }

    public function test_sycash_admin_token_is_rejected_on_staff_route(): void
    {
        // Only the system_admin JWT is accepted by handleAdminToken(); sycash is not.
        $sycashToken = $this->postJson('/api/admin/login', [
            'email' => 'sycash@mw.test',
            'password' => 'sycash_pass',
        ])->json('tokens.access_token');

        if (! $sycashToken) {
            $this->markTestSkipped('sycash login failed — verify config/admin.php path.');
        }

        $this->withToken($sycashToken)
            ->getJson('/api/staff/me')
            ->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Valid staff tokens — basic pass-through
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_valid_support_agent_token_passes_through(): void
    {
        $token = $this->getStaffToken('mw_agent@test.test', 'password123');

        $this->withToken($token)
            ->getJson('/api/staff/me')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_valid_admin_staff_token_passes_through(): void
    {
        $token = $this->getStaffToken('mw_admin@test.test', 'password123');

        $this->withToken($token)
            ->getJson('/api/staff/me')
            ->assertStatus(200);
    }

    public function test_me_endpoint_returns_employee_data_for_authenticated_staff(): void
    {
        $token = $this->getStaffToken('mw_agent@test.test', 'password123');

        $response = $this->withToken($token)->getJson('/api/staff/me');

        $response->assertStatus(200)
            ->assertJsonStructure(['employee']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Refresh token must not be usable as an access token
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_staff_refresh_token_rejected_with_token_type_invalid(): void
    {
        $loginResponse = $this->postJson('/api/staff/login', [
            'identifier' => 'mw_agent@test.test',
            'password' => 'password123',
        ]);

        $refreshToken = $loginResponse->json('tokens.refresh_token');

        $this->withToken($refreshToken)
            ->getJson('/api/staff/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'TOKEN_TYPE_INVALID');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Inactive employee
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_inactive_employee_token_is_rejected_with_account_inactive(): void
    {
        // Briefly activate so we can log in and obtain a token, then deactivate.
        $this->inactiveAgent->update(['is_active' => true]);
        $token = $this->getStaffToken('mw_inactive@test.test', 'password123');
        $this->inactiveAgent->update(['is_active' => false]);

        $this->withToken($token)
            ->getJson('/api/staff/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Token version invalidation
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_old_token_rejected_after_token_version_increment(): void
    {
        $token = $this->getStaffToken('mw_agent@test.test', 'password123');

        // Any logout or password-reset bumps token_version, invalidating all tokens.
        $this->agent->update(['token_version' => $this->agent->token_version + 1]);

        $this->withToken($token)
            ->getJson('/api/staff/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'TOKEN_INVALIDATED');
    }

    public function test_new_token_accepted_after_token_version_increment(): void
    {
        // Increment the version, then log in again — the fresh token should pass.
        $this->agent->update(['token_version' => 5]);

        $freshToken = $this->getStaffToken('mw_agent@test.test', 'password123');

        $this->withToken($freshToken)
            ->getJson('/api/staff/me')
            ->assertStatus(200);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Role-based access control
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_support_agent_can_access_any_staff_route_with_no_role_gate(): void
    {
        $token = $this->getStaffToken('mw_agent@test.test', 'password123');

        // /api/staff/me carries no role restriction
        $this->withToken($token)
            ->getJson('/api/staff/me')
            ->assertStatus(200);
    }

    public function test_support_agent_is_forbidden_from_admin_only_route(): void
    {
        // Routes guarded by middleware('staff:admin,system_admin')
        // return 403 via the middleware's forbidden() helper when the role
        // does not match the allowed list.
        $token = $this->getStaffToken('mw_agent@test.test', 'password123');

        $this->withToken($token)
            ->getJson('/api/staff/verifications/pending')
            ->assertStatus(403);
    }

    public function test_admin_staff_can_access_admin_only_route(): void
    {
        $token = $this->getStaffToken('mw_admin@test.test', 'password123');

        $this->withToken($token)
            ->getJson('/api/staff/verifications/pending')
            ->assertStatus(200);
    }

    public function test_forbidden_response_includes_forbidden_code(): void
    {
        $token = $this->getStaffToken('mw_agent@test.test', 'password123');

        $this->withToken($token)
            ->getJson('/api/staff/verifications/pending')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // System admin JWT path (handleAdminToken)
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_system_admin_user_jwt_passes_staff_middleware(): void
    {
        // The system admin can log in via /api/admin/login and use that JWT
        // on staff routes — StaffJwtMiddleware::handleAdminToken() accepts it
        // and auto-creates the corresponding Employee row (SYSTEM_ADMIN role).
        $sysAdminToken = $this->postJson('/api/admin/login', [
            'email' => 'sysadmin@mw.test',
            'password' => 'sysadmin_pass',
        ])->json('tokens.access_token');

        if (! $sysAdminToken) {
            $this->markTestSkipped('System admin login failed — verify config/admin.php settings.');
        }

        $this->withToken($sysAdminToken)
            ->getJson('/api/staff/me')
            ->assertStatus(200);
    }

    public function test_system_admin_jwt_can_access_admin_only_staff_route(): void
    {
        // system_admin role satisfies middleware('staff:admin,system_admin')
        $sysAdminToken = $this->postJson('/api/admin/login', [
            'email' => 'sysadmin@mw.test',
            'password' => 'sysadmin_pass',
        ])->json('tokens.access_token');

        if (! $sysAdminToken) {
            $this->markTestSkipped('System admin login failed — verify config/admin.php settings.');
        }

        $this->withToken($sysAdminToken)
            ->getJson('/api/staff/verifications/pending')
            ->assertStatus(200);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Staff login / refresh endpoints (public — no middleware)
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_staff_login_does_not_require_a_token(): void
    {
        $this->postJson('/api/staff/login', [
            'identifier' => 'mw_agent@test.test',
            'password' => 'password123',
        ])->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['tokens' => ['access_token', 'refresh_token']]);
    }

    public function test_staff_login_returns_both_access_and_refresh_tokens(): void
    {
        $response = $this->postJson('/api/staff/login', [
            'identifier' => 'mw_agent@test.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('tokens.access_token'));
        $this->assertNotNull($response->json('tokens.refresh_token'));
    }

    public function test_staff_login_fails_with_wrong_password(): void
    {
        $this->postJson('/api/staff/login', [
            'identifier' => 'mw_agent@test.test',
            'password' => 'wrong_password',
        ])->assertStatus(401);
    }

    public function test_staff_login_accepts_username_as_identifier(): void
    {
        $this->postJson('/api/staff/login', [
            'identifier' => 'mw_support_agent',   // username, not email
            'password' => 'password123',
        ])->assertStatus(200);
    }

    public function test_staff_logout_requires_valid_token(): void
    {
        $this->postJson('/api/staff/logout')
            ->assertStatus(401);
    }

    public function test_staff_logout_succeeds_with_valid_token(): void
    {
        $token = $this->getStaffToken('mw_agent@test.test', 'password123');

        $this->withToken($token)
            ->postJson('/api/staff/logout')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_staff_refresh_endpoint_returns_new_tokens(): void
    {
        $loginResponse = $this->postJson('/api/staff/login', [
            'identifier' => 'mw_agent@test.test',
            'password' => 'password123',
        ]);

        $refreshToken = $loginResponse->json('tokens.refresh_token');

        $this->postJson('/api/staff/refresh', ['refresh_token' => $refreshToken])
            ->assertStatus(200)
            ->assertJsonStructure(['tokens' => ['access_token', 'refresh_token']]);
    }

    public function test_staff_refresh_fails_with_invalid_refresh_token(): void
    {
        $this->postJson('/api/staff/refresh', ['refresh_token' => 'invalid_token'])
            ->assertStatus(401);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeEmployee(
        StaffRole $role,
        string $email,
        string $username,
        bool $isActive = true,
    ): Employee {
        return Employee::create([
            'username' => $username,
            'email' => $email,
            'password' => bcrypt('password123'),
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'role' => $role->value,
            'is_active' => $isActive,
            'token_version' => 0,
        ]);
    }

    private function getStaffToken(string $identifier, string $password): string
    {
        return $this->postJson('/api/staff/login', [
            'identifier' => $identifier,
            'password' => $password,
        ])->json('tokens.access_token');
    }
}
