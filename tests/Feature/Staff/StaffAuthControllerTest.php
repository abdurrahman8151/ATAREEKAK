<?php

namespace Tests\Feature\Staff;

use App\Enums\StaffRole;
use App\Models\Employee;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * StaffAuthControllerTest – Feature tests for StaffAuthController.
 *
 * TWO AUTH PATHS UNDER TEST:
 *   1. Employee credentials  → POST /api/staff/login  → staff JWT
 *   2. Admin JWT (primary)   → accepted by StaffJwtMiddleware as system_admin
 *
 * COVERS:
 *   POST /api/staff/login    – success / wrong password / inactive / validation
 *   POST /api/staff/refresh  – valid & invalid refresh tokens
 *   POST /api/staff/logout   – staff token and admin token
 *   GET  /api/staff/me       – returns authenticated employee info
 *
 * WHY seedAdminWallets() IN setUp():
 *   Config::set('admin.system_admin', …) points the admin config at
 *   'primary@admin.test' / phone '0910000001'. On a successful employee
 *   login the controller (or an event/service it calls) looks up those
 *   admin rows. Without the corresponding User + Wallet in the test DB
 *   that lookup throws → 500. Other staff test classes that successfully
 *   login as SUPPORT_AGENT either omit Config::set (StaffComplaintControllerTest)
 *   or seed admin wallets before logging in (StaffOperationsControllerTest).
 */
class StaffAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('admin.system_admin', [
            'email' => 'primary@admin.test',
            'password' => 'primary_pass',
            'username' => 'primary_admin',
            'first_name' => 'Primary',
            'last_name' => 'Admin',
            'phone' => '0910000001',
            'wallet_prefix' => 'PRIM',
            'permissions' => ['*'],
        ]);

        Config::set('admin.sycash', [
            'email' => 'sycash@admin.test',
            'password' => 'sycash_pass',
            'first_name' => 'SyCash',
            'last_name' => 'Admin',
            'phone' => '0910000002',
            'wallet_prefix' => 'SYCSH',
            'permissions' => ['view_wallet'],
        ]);

        // FIX: Config::set above points admin config at specific emails/phones.
        // The staff login endpoint (or a service/event it triggers on success)
        // looks up those admin rows. Without them in the DB a successful
        // employee login throws an exception → 500.
        $this->seedAdminWallets();
    }

    // ─── POST /api/staff/login ────────────────────────────────────────────────

    public function test_employee_can_login_with_username(): void
    {
        $this->makeEmployee('agent@test.com', 'agent_user', 'secret123');

        $this->postJson('/api/staff/login', [
            'identifier' => 'agent_user',
            'password' => 'secret123',
        ])->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'tokens' => ['access_token', 'refresh_token'],
                'employee',
            ]);
    }

    public function test_employee_can_login_with_email(): void
    {
        $this->makeEmployee('agent@test.com', 'agent_user', 'secret123');

        $this->postJson('/api/staff/login', [
            'identifier' => 'agent@test.com',
            'password' => 'secret123',
        ])->assertStatus(200);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->makeEmployee('agent@test.com', 'agent_user', 'secret123');

        $this->postJson('/api/staff/login', [
            'identifier' => 'agent_user',
            'password' => 'wrong_password',
        ])->assertStatus(401)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_login_fails_with_nonexistent_identifier(): void
    {
        $this->postJson('/api/staff/login', [
            'identifier' => 'nobody_here',
            'password' => 'any_password',
        ])->assertStatus(401);
    }

    public function test_login_requires_identifier_field(): void
    {
        $this->postJson('/api/staff/login', ['password' => 'secret123'])
            ->assertStatus(422);
    }

    public function test_login_requires_password_field(): void
    {
        $this->postJson('/api/staff/login', ['identifier' => 'agent_user'])
            ->assertStatus(422);
    }

    public function test_login_fails_for_empty_body(): void
    {
        $this->postJson('/api/staff/login', [])
            ->assertStatus(422);
    }

    public function test_login_fails_for_inactive_employee(): void
    {
        $this->makeEmployee('agent@test.com', 'agent_user', 'secret123', is_active: false);

        $this->postJson('/api/staff/login', [
            'identifier' => 'agent_user',
            'password' => 'secret123',
        ])->assertStatus(401);
    }

    // ─── POST /api/staff/refresh ──────────────────────────────────────────────

    public function test_can_refresh_token(): void
    {
        $this->makeEmployee('agent@test.com', 'agent_user', 'secret123');

        $refresh = $this->postJson('/api/staff/login', [
            'identifier' => 'agent_user',
            'password' => 'secret123',
        ])->json('tokens.refresh_token');

        $this->postJson('/api/staff/refresh', ['refresh_token' => $refresh])
            ->assertStatus(200)
            ->assertJsonStructure(['tokens' => ['access_token', 'refresh_token']]);
    }

    public function test_refresh_fails_with_invalid_token(): void
    {
        $this->postJson('/api/staff/refresh', [
            'refresh_token' => 'not.a.real.token',
        ])->assertStatus(401);
    }

    public function test_refresh_requires_refresh_token_field(): void
    {
        $this->postJson('/api/staff/refresh', [])->assertStatus(422);
    }

    // ─── POST /api/staff/logout ───────────────────────────────────────────────

    public function test_authenticated_staff_can_logout(): void
    {
        $this->withToken($this->staffToken())
            ->postJson('/api/staff/logout')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_unauthenticated_logout_returns_401(): void
    {
        $this->postJson('/api/staff/logout')->assertStatus(401);
    }

    public function test_admin_token_also_works_for_logout(): void
    {
        // StaffJwtMiddleware accepts admin JWT and maps it to system_admin role
        $this->withToken($this->adminToken())
            ->postJson('/api/staff/logout')
            ->assertStatus(200);
    }

    // ─── GET /api/staff/me ────────────────────────────────────────────────────

    public function test_authenticated_staff_can_get_own_info(): void
    {
        $this->withToken($this->staffToken())
            ->getJson('/api/staff/me')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['employee']);
    }

    public function test_unauthenticated_request_to_me_returns_401(): void
    {
        $this->getJson('/api/staff/me')->assertStatus(401);
    }

    public function test_admin_token_works_for_me_endpoint(): void
    {
        $this->withToken($this->adminToken())
            ->getJson('/api/staff/me')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function staffToken(): string
    {
        $this->makeEmployee('agent@test.com', 'agent_user', 'secret123');

        $token = $this->postJson('/api/staff/login', [
            'identifier' => 'agent_user',
            'password' => 'secret123',
        ])->json('tokens.access_token');

        // Fail with a clear message rather than a cryptic TypeError if login
        // still returns non-200 (e.g. seedAdminWallets() didn't fully satisfy
        // whatever the login success path needs).
        $this->assertNotNull(
            $token,
            'staffToken(): login returned null — check that seedAdminWallets() '.
            'creates all rows the login success handler requires.'
        );

        return $token;
    }

    private function adminToken(): string
    {
        return $this->postJson('/api/admin/login', [
            'email' => 'primary@admin.test',
            'password' => 'primary_pass',
        ])->json('tokens.access_token');
    }

    private function makeEmployee(
        string $email,
        string $username,
        string $password,
        StaffRole $role = StaffRole::SUPPORT_AGENT,
        bool $is_active = true,
    ): Employee {
        return Employee::create([
            'username' => $username,
            'email' => $email,
            'password' => bcrypt($password),
            'first_name' => 'Test',
            'last_name' => 'Agent',
            'role' => $role->value,
            'is_active' => $is_active,
            'token_version' => 0,
        ]);
    }

    /**
     * Create the User + Wallet rows that the admin config references.
     *
     * Config::set('admin.system_admin', …) sets concrete email/phone values.
     * When a successful employee login runs, the controller (or a service/
     * event listener it triggers) looks up User::where('email', …) and/or
     * Wallet::where('phone_number', …) for those admin identities. If those
     * rows don't exist the lookup throws → 500. Seeding them here prevents
     * that failure without changing any application code.
     *
     * Pattern mirrors AdminDashboardControllerTest::seedAdminWallets() and
     * StaffOperationsControllerTest::seedAdminWallets().
     */
    private function seedAdminWallets(): void
    {
        foreach (['system_admin', 'sycash'] as $type) {
            $cfg = config("admin.{$type}");

            $adminUser = User::firstOrCreate(
                ['email' => $cfg['email']],
                [
                    'first_name' => $cfg['first_name'],
                    'last_name' => $cfg['last_name'],
                    'password' => bcrypt($cfg['password']),
                    'gender' => 'M',
                    'address' => 'دمشق',
                    'status' => 1,
                    'email_verified_at' => now(),
                ]
            );

            if (! Wallet::where('phone_number', $cfg['phone'])->exists()) {
                $wallet = Wallet::create([
                    'user_id' => $adminUser->id,
                    'phone_number' => $cfg['phone'],
                    'balance' => 10_000_000,
                ]);
                $adminUser->update(['wallet_id' => $wallet->id]);
            }
        }
    }
}
