<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * RV-43. This file asserted THREE app behaviours that have since been FIXED, plus one
 * stale fixture. In every case the app is correct and this file was frozen at the old,
 * broken snapshot. The owner ruled on 2026-10-12 that the app stands and these tests
 * are updated to match it.
 *
 * 1. ADMIN AUTH READS THE DATABASE (fixture was stale).
 *    These tests posted a `config('admin.system_admin.email')` + password to
 *    `/api/admin/login`. Admin auth now authenticates an Employee by username or email
 *    (`AdminAuthService:49-63`, class docblock :16-21) and the DB is the source of truth;
 *    the config-driven auto-provisioning these tests relied on was removed in the
 *    RV-29/RV-34 migration. The posted address matched no row, so `authenticate()`
 *    returned null and the route answered 401. Proof that the app is fine:
 *    tests/Feature/Review/RV43AdminLoginEmployeeTest.php.
 *
 * 2. THE "currently 500s" ENDPOINTS NOW RETURN 200.
 *    `StaffJwtMiddleware::handleAdminToken()` used to leave the `adminConfig` request
 *    attribute null, which `getAdminWallet()` and `chargeWallet()` read through
 *    `AdminAuthService::getAdminConfigFromRequest()`, so both 500'd. That is fixed; both
 *    now return 200. The tests below asserted the 500.
 *
 * 3. PERMISSION FAILURES NOW RETURN 403, NOT 401.
 *    `StaffJwtMiddleware::fail()` used to answer 401 for everything, so a sycash admin
 *    that legitimately lacked a permission got 401 - indistinguishable from
 *    "not authenticated". That conflation is fixed: an authenticated principal that lacks
 *    the permission now gets 403, and 401 means no/invalid credentials. The sycash denial
 *    tests below asserted 401.
 *
 * Every one of those three is a case where the code got BETTER and this file recorded the
 * old worse behaviour as if it were the contract.
 */
class AdminDashboardControllerTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;
    use SeedsSystemWallets;

    private Wallet $primaryAdminWallet;

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

        $this->seedSystemWallets(10_000_000.0);
        $this->primaryAdminWallet = Wallet::where(
            'phone_number',
            config('admin.system_admin.phone')
        )->first();
    }

    // ─── LOGIN ──────────────────────────────────────────────────────────
    public function test_admin_can_login_with_correct_credentials(): void
    {
        // RV-43: log in as a REAL Employee. The old body posted the config
        // email/password, which admin auth stopped accepting when credentials moved to the
        // `employees` table (AdminAuthService:49-63), so it 401'd on a valid account.
        $employee = $this->employee('system_admin');

        $this->postJson('/api/admin/login', [
            'username' => $employee->username,
            'password' => $this->rv34Password,
        ])->assertStatus(200)
            ->assertJsonPath('status', 'success')
            // `role`, not `type`: `AdminAuthService::formatAdmin()` (:137-148) returns
            // `role` and `role_label`, and never had a `type` key.
            ->assertJsonPath('admin.role', 'system_admin');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->postJson('/api/admin/login', [
            'email' => 'primary@admin.test', 'password' => 'wrong_password',
        ])->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_login_fails_with_non_admin_email(): void
    {
        $this->postJson('/api/admin/login', [
            'email' => 'nobody@example.com', 'password' => 'anything',
        ])->assertStatus(401);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/admin/login', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_malformed_email_falls_through_to_invalid_credentials(): void
    {
        // FIX: the login validator only checks 'string', not 'email' format —
        // a malformed string passes validation and fails at credential
        // matching instead, so this is 401, not 422.
        $this->postJson('/api/admin/login', [
            'email' => 'not-an-email', 'password' => 'pass',
        ])->assertStatus(401);
    }

    public function test_sycash_admin_can_login(): void
    {
        // RV-43: same real-Employee login as above; `role`, not `type`.
        $employee = $this->employee('sycash');

        $this->postJson('/api/admin/login', [
            'username' => $employee->username,
            'password' => $this->rv34Password,
        ])->assertStatus(200)->assertJsonPath('admin.role', 'sycash');
    }

    // ─── LOGOUT ─────────────────────────────────────────────────────────
    public function test_authenticated_admin_can_logout(): void
    {
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson('/api/admin/logout')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_unauthenticated_logout_returns_401(): void
    {
        $this->postJson('/api/admin/logout')->assertStatus(401);
    }

    // ─── WALLET (own) — the old 500 is FIXED, see class docblock ─────────
    public function test_authenticated_admin_gets_own_wallet(): void
    {
        // RV-43: this asserted 500. `handleAdminToken()` used to leave `adminConfig` null
        // and both wallet endpoints 500'd; that is fixed, so it now returns 200. The old
        // test name said "currently_500s" and is renamed, because the name would otherwise
        // document a bug that no longer exists.
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->getJson('/api/admin/wallet')
            ->assertStatus(200);
    }

    public function test_unauthenticated_request_to_wallet_returns_401(): void
    {
        $this->getJson('/api/admin/wallet')->assertStatus(401);
    }

    // ─── ALL WALLETS (combined endpoint) ────────────────────────────────
    public function test_authenticated_admin_can_list_all_wallets(): void
    {
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->getJson('/api/admin/wallets')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['admin_wallets', 'all_wallets']);
    }

    public function test_unauthenticated_request_to_wallets_returns_401(): void
    {
        $this->getJson('/api/admin/wallets')->assertStatus(401);
    }

    // ─── CHARGE WALLET — the old 500 is FIXED, see class docblock ────────
    public function test_charging_a_wallet_succeeds(): void
    {
        // RV-43: this asserted 500 for the same reason as the wallet read above. It now
        // returns 200, so this test is no longer "a known crash" - it is the happy path.
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $wallet = Wallet::create(['user_id' => $user->id, 'phone_number' => '0911111111', 'balance' => 0]);
        $user->update(['wallet_id' => $wallet->id]);

        $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson('/api/admin/wallet/charge', ['phone_number' => '0911111111', 'amount' => 5000])
            ->assertStatus(200);
    }

    public function test_charge_wallet_fails_validation_with_missing_fields(): void
    {
        // Validation runs before the adminConfig code path, so this still 422s correctly.
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson('/api/admin/wallet/charge', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_charge_wallet_fails_with_amount_zero(): void
    {
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson('/api/admin/wallet/charge', ['phone_number' => '0911111111', 'amount' => 0])
            ->assertStatus(422);
    }

    public function test_sycash_admin_cannot_charge_wallet(): void
    {
        // RV-43: 403, not 401. sycash is AUTHENTICATED here - it simply lacks the permission.
        // The old 401 conflated "wrong credentials" with "not allowed", which is what made
        // every permission denial indistinguishable from a failed login.
        $this->withToken($this->sycashToken())
            ->postJson('/api/admin/wallet/charge', ['phone_number' => '0911111111', 'amount' => 5000])
            ->assertStatus(403);
    }

    public function test_unauthenticated_admin_cannot_charge_wallet(): void
    {
        $this->postJson('/api/admin/wallet/charge', ['phone_number' => '0911111111', 'amount' => 5000])
            ->assertStatus(401);
    }

    // ─── WALLET TRANSACTIONS — unaffected by the adminConfig gap ───────
    public function test_authenticated_admin_can_view_wallet_transactions(): void
    {
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->getJson("/api/admin/wallet/{$this->primaryAdminWallet->id}/transactions")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['wallet', 'transactions']);
    }

    public function test_wallet_transactions_returns_404_for_nonexistent_wallet(): void
    {
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->getJson('/api/admin/wallet/999999/transactions')
            ->assertStatus(404);
    }

    public function test_unauthenticated_request_to_transactions_returns_401(): void
    {
        $this->getJson("/api/admin/wallet/{$this->primaryAdminWallet->id}/transactions")
            ->assertStatus(401);
    }

    // ─── DASHBOARD ──────────────────────────────────────────────────────
    public function test_authenticated_admin_can_view_dashboard(): void
    {
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data']);
    }

    public function test_unauthenticated_request_to_dashboard_returns_401(): void
    {
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }

    // ─── REPORT — route is /admin/reports (plural), system_admin only ──
    public function test_primary_admin_can_generate_report(): void
    {
        // Response-shape assumption (['report_data']) — I don't have
        // AdminDashboardController::showReport()'s source to confirm it.
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->getJson('/api/admin/reports')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['report_data']);
    }

    public function test_report_accepts_date_range(): void
    {
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->getJson('/api/admin/reports?start_date=2024-01-01&end_date=2024-12-31')
            ->assertStatus(200);
    }

    public function test_report_rejects_invalid_date_format(): void
    {
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->getJson('/api/admin/reports?start_date=not-a-date')
            ->assertStatus(422);
    }

    public function test_sycash_admin_cannot_access_report(): void
    {
        // RV-43: 403, not 401 - authenticated but not permitted. See the class docblock.
        $this->withToken($this->sycashToken())
            ->getJson('/api/admin/reports')
            ->assertStatus(403);
    }

    // ─── VERIFICATIONS — route is /admin/verifications (no /pending) ───
    public function test_primary_admin_can_list_pending_verifications(): void
    {
        User::factory()->create(['verification_status' => 'pending']);

        $this->withToken($this->adminToken(null, 'system_admin'))
            ->getJson('/api/admin/verifications')
            ->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_sycash_admin_cannot_list_pending_verifications(): void
    {
        // RV-43: 403, not 401 - authenticated but not permitted.
        $this->withToken($this->sycashToken())
            ->getJson('/api/admin/verifications')
            ->assertStatus(403);
    }

    public function test_primary_admin_can_approve_verification(): void
    {
        // RV-43: `national_id` is REQUIRED to approve (`AdminDashboardController:466`,
        // `'national_id' => 'required|string|max:50'`), so this 422'd. Same precondition
        // RV-04b found at `StaffAdminController:115`.
        $user = User::factory()->create([
            'verification_status' => 'pending',
            'password' => bcrypt('password123'),
        ]);

        $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson("/api/admin/verifications/{$user->id}/approve", ['national_id' => 'N-998877665'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    /** The requirement itself: approval without a national ID is refused and the row stays pending. */
    public function test_approve_verification_requires_a_national_id(): void
    {
        $user = User::factory()->create(['verification_status' => 'pending']);

        $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson("/api/admin/verifications/{$user->id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('national_id');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'verification_status' => 'pending',
        ]);
    }

    public function test_sycash_admin_cannot_approve_verification(): void
    {
        // RV-43: 403, not 401 - authenticated but not permitted.
        $user = User::factory()->create(['verification_status' => 'pending']);

        $this->withToken($this->sycashToken())
            ->postJson("/api/admin/verifications/{$user->id}/approve")
            ->assertStatus(403);
    }

    public function test_primary_admin_can_reject_verification(): void
    {
        $user = User::factory()->create([
            'verification_status' => 'pending',
            'is_verified_driver' => true,
            'is_verified_passenger' => true,
        ]);

        $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson("/api/admin/verifications/{$user->id}/reject")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'verification_status' => 'rejected',
            'is_verified_driver' => false,
            'is_verified_passenger' => false,
        ]);
    }

    public function test_approve_verification_returns_422_for_nonexistent_user(): void
    {
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson('/api/admin/verifications/999999/approve')
            ->assertStatus(422);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function sycashToken(): string
    {
        // RV-34: same Cause-B defect as primaryToken() — the old body logged in
        // with the config email/password, which admin auth no longer accepts (it
        // authenticates an Employee by username), so this returned null and tripped
        // the `: string` return type.
        return $this->adminToken(null, 'sycash');
    }
}
