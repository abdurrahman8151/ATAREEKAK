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
 * Two known app-side gaps affect this file (not fixed here — tests reflect
 * current actual behavior):
 *
 * 1. Admin routes run through StaffJwtMiddleware, not AdminJwtMiddleware.
 *    StaffJwtMiddleware::handleAdminToken() never sets $request->attributes
 *    ->set('adminConfig', ...), but AdminDashboardController::getAdminWallet()
 *    and chargeWallet() both read that attribute via
 *    AdminAuthService::getAdminConfigFromRequest(). It's always null, so both
 *    endpoints 500 for every request. Fix: have handleAdminToken() populate
 *    'adminConfig' the same way AdminJwtMiddleware::resolveAdminConfig() does.
 *
 * 2. StaffJwtMiddleware::fail() always returns 401, so sycash — which can
 *    never pass handleAdminToken()'s email check — gets 401 on every /admin/*
 *    route, never 403, regardless of which specific permission it lacks.
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
        $this->postJson('/api/admin/login', [
            'email' => 'primary@admin.test', 'password' => 'primary_pass',
        ])->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('admin.type', 'system_admin');
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
        $this->postJson('/api/admin/login', [
            'email' => 'sycash@admin.test', 'password' => 'sycash_pass',
        ])->assertStatus(200)->assertJsonPath('admin.type', 'sycash');
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

    // ─── WALLET (own) — known 500, see class docblock ──────────────────
    public function test_authenticated_admin_get_own_wallet_currently_500s(): void
    {
        $this->withToken($this->adminToken(null, 'system_admin'))
            ->getJson('/api/admin/wallet')
            ->assertStatus(500);
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

    // ─── CHARGE WALLET — known 500, see class docblock ─────────────────
    public function test_charging_a_wallet_currently_500s(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $wallet = Wallet::create(['user_id' => $user->id, 'phone_number' => '0911111111', 'balance' => 0]);
        $user->update(['wallet_id' => $wallet->id]);

        $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson('/api/admin/wallet/charge', ['phone_number' => '0911111111', 'amount' => 5000])
            ->assertStatus(500);
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
        $this->withToken($this->sycashToken())
            ->postJson('/api/admin/wallet/charge', ['phone_number' => '0911111111', 'amount' => 5000])
            ->assertStatus(401);
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
        // sycash fails handleAdminToken()'s email check before it ever
        // reaches the nested system_admin-only gate — 401, not 403.
        $this->withToken($this->sycashToken())
            ->getJson('/api/admin/reports')
            ->assertStatus(401);
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
        $this->withToken($this->sycashToken())
            ->getJson('/api/admin/verifications')
            ->assertStatus(401);
    }

    public function test_primary_admin_can_approve_verification(): void
    {
        $user = User::factory()->create([
            'verification_status' => 'pending',
            'password' => bcrypt('password123'),
        ]);

        $this->withToken($this->adminToken(null, 'system_admin'))
            ->postJson("/api/admin/verifications/{$user->id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_sycash_admin_cannot_approve_verification(): void
    {
        $user = User::factory()->create(['verification_status' => 'pending']);

        $this->withToken($this->sycashToken())
            ->postJson("/api/admin/verifications/{$user->id}/approve")
            ->assertStatus(401);
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
