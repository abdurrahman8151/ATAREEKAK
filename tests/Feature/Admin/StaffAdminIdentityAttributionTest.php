<?php

namespace Tests\Feature\Admin;

use App\Models\Employee;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRequest;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T2-1 regression coverage — staff identity attribution.
 *
 * StaffJwtMiddleware never called $request->setUserResolver(), and the default
 * guard is `web`/session, so $request->user() returned NULL on every
 * staff-token-authenticated request. Admin controllers attribute writes to
 * $request->user()?->id, so:
 *
 *   wallet_requests.processed_by  → always NULL
 *   users.banned_by               → always NULL
 *   wallet_transactions.user_id   → always NULL (admin charge)
 *
 * The resolver now returns the employee's SHADOW USER, because those columns
 * reference `users` (and two of them carry a real FK to `users`) while the
 * authenticated principal is an `Employee`. Writing an Employee id would raise
 * SQLSTATE[23000] 1452 and abort the money-moving transaction.
 *
 * These tests exercise the real authenticated HTTP endpoint, not the service
 * layer, so the middleware path is genuinely covered.
 */
class StaffAdminIdentityAttributionTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create an Employee and return [employee, accessToken].
     */
    private function staff(string $role = 'system_admin'): array
    {
        $employee = Employee::create([
            'username' => 't21_'.uniqid(),
            'email' => 't21_'.uniqid().'@test.com',
            'password' => 'Password123!',
            'first_name' => 'Attr',
            'last_name' => 'Tester',
            'role' => $role,
            'is_active' => true,
            'token_version' => 0,
        ]);

        $token = $this->postJson('/api/staff/login', [
            'identifier' => $employee->username,
            'password' => 'Password123!',
        ])->json('tokens.access_token');

        $this->assertNotEmpty($token, 'staff login must return an access token');

        return [$employee, $token];
    }

    private function walletRequestFor(User $user): WalletRequest
    {
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'phone_number' => '09'.rand(10000000, 99999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 0,
        ]);

        return WalletRequest::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => 'charge',
            'amount' => 50000,
            'status' => 'pending',
        ]);
    }

    // ── The core defect ───────────────────────────────────────────────────────

    /**
     * Approving a wallet request must record who processed it.
     */
    public function test_wallet_request_approval_records_the_acting_admin(): void
    {
        [$employee, $token] = $this->staff('system_admin');

        $passenger = User::factory()->create(['status' => 1]);
        $request = $this->walletRequestFor($passenger);

        $this->withToken($token)
            ->postJson("/api/admin/wallet/requests/{$request->id}/approve", [
                'admin_notes' => 'verified receipt',
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $request->refresh();

        $this->assertSame('approved', $request->status);
        $this->assertNotNull(
            $request->processed_by,
            'processed_by must be recorded, not left NULL'
        );
        $this->assertNotNull($request->processed_at);

        // It must be a real `users` row (FK-safe), not the employees.id.
        $actor = User::find($request->processed_by);
        $this->assertNotNull($actor, 'processed_by must reference an existing users row');
        $this->assertSame($employee->email, $actor->email, 'actor must be the logged-in admin');
    }

    /**
     * Rejecting a wallet request must record who processed it.
     */
    public function test_wallet_request_rejection_records_the_acting_admin(): void
    {
        [$employee, $token] = $this->staff('system_admin');

        $passenger = User::factory()->create(['status' => 1]);
        $request = $this->walletRequestFor($passenger);

        $this->withToken($token)
            ->postJson("/api/admin/wallet/requests/{$request->id}/reject", [
                'admin_notes' => 'unverified',
            ])
            ->assertStatus(200);

        $request->refresh();

        $this->assertSame('rejected', $request->status);
        $this->assertNotNull($request->processed_by);
        $this->assertSame($employee->email, User::find($request->processed_by)?->email);
    }

    /**
     * Banning a user must record the acting admin in users.banned_by.
     *
     * banned_by has no FK, so a wrong id would fail silently: the reader at
     * AdminBanController does User::find($user->banned_by) and would resolve a
     * different person's name.
     */
    public function test_ban_records_the_acting_admin(): void
    {
        [$employee, $token] = $this->staff('system_admin');

        $target = User::factory()->create(['status' => 1]);

        $this->withToken($token)
            ->postJson("/api/admin/users/{$target->id}/ban", [
                'reason' => 'Repeated spam behaviour',
                'type' => 'permanent',
            ])
            ->assertStatus(200);

        $target->refresh();

        $this->assertSame(-1, (int) $target->status);
        $this->assertNotNull($target->banned_by, 'banned_by must be recorded');

        // The recorded id must resolve back to the acting admin, not to someone else.
        $resolved = User::select('id', 'first_name', 'last_name', 'email')->find($target->banned_by);
        $this->assertNotNull($resolved);
        $this->assertSame($employee->email, $resolved->email);
    }

    /**
     * An admin charging a passenger wallet must be recorded on the transaction.
     */
    public function test_admin_wallet_charge_records_the_acting_admin(): void
    {
        [$employee, $token] = $this->staff('system_admin');

        $passenger = User::factory()->create(['status' => 1]);
        $wallet = Wallet::create([
            'user_id' => $passenger->id,
            'phone_number' => '09'.rand(10000000, 99999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 0,
        ]);
        $passenger->update(['wallet_id' => $wallet->id]);

        $this->withToken($token)
            ->postJson("/api/admin/passengers/{$passenger->id}/charge-wallet", [
                'amount' => 25000,
                'admin_notes' => 'goodwill credit',
            ])
            ->assertStatus(200);

        $tx = DB::table('wallet_transactions')
            ->where('wallet_id', $wallet->id)
            ->where('type', 'admin_charge')
            ->first();

        $this->assertNotNull($tx, 'an admin_charge transaction must be written');
        $this->assertNotNull($tx->user_id, 'the acting admin must be recorded on the transaction');
        $this->assertSame($employee->email, User::find($tx->user_id)?->email);

        // And the money actually moved.
        $this->assertEquals(25000.0, (float) $wallet->fresh()->balance);
    }

    /**
     * The bridge must be idempotent: repeated admin actions reuse one shadow
     * User rather than creating a new one per request.
     */
    public function test_shadow_user_is_reused_not_duplicated(): void
    {
        [$employee, $token] = $this->staff('system_admin');

        $passenger = User::factory()->create(['status' => 1]);
        $request = $this->walletRequestFor($passenger);

        $this->withToken($token)
            ->postJson("/api/admin/wallet/requests/{$request->id}/approve")
            ->assertStatus(200);

        $this->withToken($token)
            ->getJson('/api/admin/users')
            ->assertStatus(200);

        $count = User::where('email', $employee->email)->count();

        $this->assertSame(1, $count, 'exactly one shadow User must exist for the employee');
    }

    // ── Denied paths — the resolver must not weaken authentication ─────────────

    public function test_unauthenticated_admin_request_is_rejected(): void
    {
        $passenger = User::factory()->create(['status' => 1]);
        $request = $this->walletRequestFor($passenger);

        $this->postJson("/api/admin/wallet/requests/{$request->id}/approve")
            ->assertStatus(401);

        // Nothing was attributed and nothing moved.
        $this->assertNull($request->fresh()->processed_by);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $passenger = User::factory()->create(['status' => 1]);
        $request = $this->walletRequestFor($passenger);

        $this->withToken('not.a.real.token')
            ->postJson("/api/admin/wallet/requests/{$request->id}/approve")
            ->assertStatus(401);

        $this->assertNull($request->fresh()->processed_by);
    }

    /**
     * A non-privileged staff role must not reach the admin surface, and must
     * not have created a shadow User on the way to being rejected (the resolver
     * is lazy and only runs once the route is actually reached).
     */
    public function test_support_agent_cannot_reach_admin_routes(): void
    {
        [$employee, $token] = $this->staff('support_agent');

        $passenger = User::factory()->create(['status' => 1]);
        $request = $this->walletRequestFor($passenger);

        $this->withToken($token)
            ->postJson("/api/admin/wallet/requests/{$request->id}/approve")
            ->assertStatus(403);

        $request->refresh();
        $this->assertSame('pending', $request->status);
        $this->assertNull($request->processed_by);

        $this->assertSame(
            0,
            User::where('email', $employee->email)->count(),
            'a rejected request must not create a shadow User'
        );
    }

    /**
     * A passenger token must never satisfy the staff guard.
     */
    public function test_user_token_is_rejected_by_staff_guard(): void
    {
        [$employee] = $this->staff('system_admin');

        $passenger = User::factory()->create(['status' => 1]);
        $userToken = app(JwtService::class)
            ->generateTokenPair($passenger)['access_token'];

        $request = $this->walletRequestFor($passenger);

        $this->withToken($userToken)
            ->postJson("/api/admin/wallet/requests/{$request->id}/approve")
            ->assertStatus(401);

        $this->assertNull($request->fresh()->processed_by);
    }

    /**
     * An inactive employee must be refused (unchanged by this fix).
     */
    public function test_inactive_employee_token_is_rejected(): void
    {
        [$employee, $token] = $this->staff('system_admin');

        $employee->update(['is_active' => false]);

        $passenger = User::factory()->create(['status' => 1]);
        $request = $this->walletRequestFor($passenger);

        $this->withToken($token)
            ->postJson("/api/admin/wallet/requests/{$request->id}/approve")
            ->assertStatus(401);

        $this->assertNull($request->fresh()->processed_by);
    }
}
