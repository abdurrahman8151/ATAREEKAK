<?php

namespace Tests\Feature\Admin;

use App\Models\Employee;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T2-2 regression — the admin panel's financial surface is system_admin-only.
 *
 * Before the fix, routes/api.php admitted the `admin` role to the whole panel
 * via `staff:admin,system_admin`, but config/admin.php defines a wallet phone
 * only for `system_admin` and `sycash`. AdminAuthService::getAdminConfigFromRequest()
 * therefore produced `phone = null` for `admin`, AdminWalletService::getOrCreateWallet()
 * found no matching wallet and threw RuntimeException → HTTP 500 on GET /api/admin/wallet.
 *
 * The financial endpoints now carry the same `staff:system_admin` gate that
 * /wallet/charge, /reports, /export/pdf and /verifications already used, so the
 * panel's financial surface is consistent and `admin` is denied at the door
 * instead of reaching a controller that is not configured for it.
 *
 * `sycash` is intentionally NOT granted here: it is a system wallet
 * (config('admin.sycash.phone')), consumed directly by WalletTransactionService,
 * not an admin persona.
 */
class AdminFinancialSurfaceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────

    private function employee(string $role): Employee
    {
        return Employee::create([
            'username' => "fin_{$role}_".uniqid(),
            'email' => "fin_{$role}_".uniqid().'@test.com',
            'password' => 'Password123!',
            'first_name' => 'Fin',
            'last_name' => ucfirst($role),
            'role' => $role,
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    /** `admin` can only obtain a token through the staff door. */
    private function staffToken(Employee $e): string
    {
        return (string) $this->postJson('/api/staff/login', [
            'identifier' => $e->username,
            'password' => 'Password123!',
        ])->json('tokens.access_token');
    }

    private function adminToken(Employee $e): string
    {
        return (string) $this->postJson('/api/admin/login', [
            'username' => $e->username,
            'password' => 'Password123!',
        ])->json('tokens.access_token');
    }

    private function seedSystemWallet(): void
    {
        Wallet::create([
            'user_id' => null,
            'phone_number' => config('admin.system_admin.phone'),
            'wallet_number' => 'ADM'.random_int(1000000000, 9999999999),
            'balance' => 0,
        ]);
    }

    private function userWithWallet(): User
    {
        $user = User::factory()->create(['status' => 1]);

        Wallet::create([
            'user_id' => $user->id,
            'phone_number' => '0912'.random_int(100000, 999999),
            'wallet_number' => 'USR'.random_int(1000000000, 9999999999),
            'balance' => 0,
        ]);

        return $user;
    }

    /** Read endpoints that act on the caller's own system wallet. */
    private function financialGetPaths(): array
    {
        return [
            '/api/admin/wallet',
            '/api/admin/wallets',
            '/api/admin/wallet/requests',
            '/api/admin/reports',
        ];
    }

    // ── Denied path ────────────────────────────────────────────────────────

    public function test_admin_is_forbidden_from_every_financial_endpoint(): void
    {
        $token = $this->staffToken($this->employee('admin'));

        foreach ($this->financialGetPaths() as $path) {
            $this->withToken($token)->getJson($path)->assertStatus(403);
        }

        // Money-moving writes.
        $this->withToken($token)
            ->postJson('/api/admin/wallet/requests/1/approve')
            ->assertStatus(403);

        $this->withToken($token)
            ->postJson('/api/admin/wallet/requests/1/reject')
            ->assertStatus(403);

        $this->withToken($token)
            ->postJson('/api/admin/wallet/charge', ['phone_number' => '0911111111', 'amount' => 5000])
            ->assertStatus(403);

        $this->withToken($token)
            ->postJson('/api/admin/passengers/1/charge-wallet', ['amount' => 5000])
            ->assertStatus(403);
    }

    public function test_the_wallet_500_is_gone_for_admin(): void
    {
        $token = $this->staffToken($this->employee('admin'));

        // Regression: this used to pass the route gate, reach the controller and
        // throw RuntimeException("System wallet for phone [] not found") → 500,
        // because config('admin.admin.phone') is null. It is denied at the door now.
        $this->withToken($token)
            ->getJson('/api/admin/wallet')
            ->assertStatus(403);
    }

    public function test_sycash_is_not_granted_the_financial_surface(): void
    {
        // sycash is a system wallet row, not a panel persona.
        $token = $this->adminToken($this->employee('sycash'));

        $this->withToken($token)
            ->getJson('/api/admin/wallet/requests')
            ->assertStatus(403);
    }

    // ── Allowed paths (no over-restriction) ────────────────────────────────

    public function test_system_admin_can_still_reach_the_financial_surface(): void
    {
        $this->seedSystemWallet();
        $token = $this->adminToken($this->employee('system_admin'));

        $this->withToken($token)->getJson('/api/admin/wallet')->assertStatus(200);
        $this->withToken($token)->getJson('/api/admin/wallets')->assertStatus(200);
        $this->withToken($token)->getJson('/api/admin/wallet/requests')->assertStatus(200);
    }

    public function test_system_admin_can_still_charge_a_passenger_wallet(): void
    {
        $this->seedSystemWallet();
        $token = $this->adminToken($this->employee('system_admin'));
        $user = $this->userWithWallet();

        $this->withToken($token)
            ->postJson("/api/admin/passengers/{$user->id}/charge-wallet", [
                'amount' => 5000,
                'admin_notes' => 'T2-2 regression check',
            ])
            ->assertStatus(200);

        // The money actually moved.
        $this->assertSame(
            5000.0,
            (float) Wallet::where('user_id', $user->id)->first()->balance
        );
    }

    public function test_admin_can_still_reach_non_financial_admin_routes(): void
    {
        $token = $this->staffToken($this->employee('admin'));
        $user = User::factory()->create(['status' => 1]);

        // Read-only operational surface the `admin` role legitimately keeps.
        $this->withToken($token)->getJson('/api/admin/users')->assertStatus(200);

        $this->withToken($token)
            ->getJson("/api/admin/users/{$user->id}/status")
            ->assertStatus(200);

        $this->withToken($token)
            ->getJson("/api/admin/passengers/{$user->id}/full-profile")
            ->assertStatus(200);
    }

    public function test_unauthenticated_request_is_still_rejected(): void
    {
        $this->getJson('/api/admin/wallet')->assertStatus(401);
        $this->getJson('/api/admin/wallet/requests')->assertStatus(401);
    }
}
