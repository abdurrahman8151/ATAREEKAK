<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * AdminBanControllerTest
 *
 * Routes under test (all behind auth.admin middleware):
 *   POST /api/admin/users/{userId}/ban    → ban()
 *   POST /api/admin/users/{userId}/unban  → unban()
 *   GET  /api/admin/users/{userId}/status → userStatus()
 */
class AdminBanControllerTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;
    use SeedsSystemWallets;

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
    }

    // =========================================================================
    // ban()
    // =========================================================================

    public function test_admin_can_permanently_ban_active_user(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/ban", [
                'reason' => 'Repeated violation of community guidelines.',
                'type' => 'permanent',
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => -1]);
    }

    public function test_admin_can_temporarily_ban_user_with_expiry(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/ban", [
                'reason' => 'Temporary ban for policy violation today.',
                'type' => 'temporary',
                'expires_at' => now()->addDays(7)->toDateTimeString(),
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => -1, 'ban_type' => 'temporary']);
    }

    public function test_ban_stores_reason_and_type_on_user(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/ban", [
                'reason' => 'Verified policy breach documented case.',
                'type' => 'permanent',
            ]);

        $fresh = $user->fresh();
        $this->assertEquals('Verified policy breach documented case.', $fresh->ban_reason);
        $this->assertEquals('permanent', $fresh->ban_type);
    }

    public function test_ban_requires_authentication(): void
    {
        $user = User::factory()->create();

        $this->postJson("/api/admin/users/{$user->id}/ban", [
            'reason' => 'Test reason long enough.',
            'type' => 'permanent',
        ])->assertStatus(401);
    }

    public function test_ban_fails_with_missing_reason(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/ban", ['type' => 'permanent'])
            ->assertStatus(422);
    }

    public function test_ban_fails_when_reason_is_too_short(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/ban", [
                'reason' => 'Short',
                'type' => 'permanent',
            ])
            ->assertStatus(422);
    }

    public function test_ban_fails_with_missing_type(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/ban", [
                'reason' => 'Long enough reason for this test case.',
            ])
            ->assertStatus(422);
    }

    public function test_ban_fails_with_invalid_type_value(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/ban", [
                'reason' => 'Long enough reason for this test case.',
                'type' => 'forever',
            ])
            ->assertStatus(422);
    }

    public function test_temporary_ban_fails_without_expires_at(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/ban", [
                'reason' => 'Long enough reason for this test case.',
                'type' => 'temporary',
            ])
            ->assertStatus(422);
    }

    public function test_temporary_ban_fails_with_past_expires_at(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/ban", [
                'reason' => 'Long enough reason for this test case.',
                'type' => 'temporary',
                'expires_at' => now()->subDay()->toDateTimeString(),
            ])
            ->assertStatus(422);
    }

    public function test_cannot_ban_already_banned_user(): void
    {
        $user = User::factory()->create(['status' => -1]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/ban", [
                'reason' => 'Attempting to ban already banned user.',
                'type' => 'permanent',
            ])
            ->assertStatus(422);
    }

    public function test_ban_returns_404_for_nonexistent_user(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/admin/users/999999/ban', [
                'reason' => 'Nonexistent user ban attempt here.',
                'type' => 'permanent',
            ])
            ->assertStatus(404);
    }

    public function test_ban_response_includes_user_data_and_ban_block(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/ban", [
                'reason' => 'Complete policy violation with evidence.',
                'type' => 'permanent',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'message', 'data' => ['user_id', 'account_status', 'ban']]);
    }

    public function test_ban_result_shows_banned_account_status(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/ban", [
                'reason' => 'Serious community guideline violation.',
                'type' => 'permanent',
            ]);

        $this->assertEquals('banned', $response->json('data.account_status'));
    }

    // =========================================================================
    // unban()
    // =========================================================================

    public function test_admin_can_unban_a_banned_user(): void
    {
        $user = User::factory()->create([
            'status' => -1,
            'ban_reason' => 'Test',
            'ban_type' => 'permanent',
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/unban")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 0]);
    }

    public function test_unban_clears_all_ban_fields(): void
    {
        $user = User::factory()->create([
            'status' => -1,
            'ban_reason' => 'Policy violation',
            'ban_type' => 'temporary',
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/unban");

        $fresh = $user->fresh();
        $this->assertNull($fresh->ban_reason);
        $this->assertNull($fresh->ban_type);
        $this->assertNull($fresh->banned_at);
    }

    public function test_unban_requires_authentication(): void
    {
        $user = User::factory()->create(['status' => -1]);
        $this->postJson("/api/admin/users/{$user->id}/unban")->assertStatus(401);
    }

    public function test_cannot_unban_active_user(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/unban")
            ->assertStatus(422);
    }

    public function test_cannot_unban_logged_out_user(): void
    {
        $user = User::factory()->create(['status' => 0]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/unban")
            ->assertStatus(422);
    }

    public function test_unban_returns_404_for_nonexistent_user(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/admin/users/999999/unban')
            ->assertStatus(404);
    }

    public function test_unban_response_shows_logged_out_status(): void
    {
        $user = User::factory()->create(['status' => -1, 'ban_type' => 'permanent']);

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/unban");

        $response->assertStatus(200);
        $this->assertEquals('logged_out', $response->json('data.account_status'));
    }

    public function test_unban_accepts_optional_admin_notes(): void
    {
        $user = User::factory()->create(['status' => -1, 'ban_type' => 'temporary']);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/users/{$user->id}/unban", [
                'admin_notes' => 'User appealed and ban was lifted after review.',
            ])
            ->assertStatus(200);
    }

    // =========================================================================
    // userStatus()
    // =========================================================================

    public function test_can_get_status_for_active_user(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/users/{$user->id}/status")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.account_status', 'active')
            ->assertJsonPath('data.status_code', 1);
    }

    public function test_can_get_status_for_banned_user_with_ban_block(): void
    {
        $user = User::factory()->create([
            'status' => -1,
            'ban_reason' => 'Severe violation',
            'ban_type' => 'permanent',
        ]);

        $response = $this->withToken($this->adminToken())
            ->getJson("/api/admin/users/{$user->id}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.account_status', 'banned');

        $this->assertNotNull($response->json('data.ban'));
        $this->assertEquals('Severe violation', $response->json('data.ban.reason'));
    }

    public function test_user_status_response_includes_all_required_fields(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/users/{$user->id}/status")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['user_id', 'name', 'email', 'account_status', 'status_code', 'ban'],
            ]);
    }

    public function test_user_status_requires_authentication(): void
    {
        $user = User::factory()->create();
        $this->getJson("/api/admin/users/{$user->id}/status")->assertStatus(401);
    }

    public function test_user_status_returns_404_for_nonexistent_user(): void
    {
        $this->withToken($this->adminToken())
            ->getJson('/api/admin/users/999999/status')
            ->assertStatus(404);
    }

    public function test_user_id_in_response_matches_queried_user(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $response = $this->withToken($this->adminToken())
            ->getJson("/api/admin/users/{$user->id}/status");

        $this->assertEquals($user->id, $response->json('data.user_id'));
    }

    public function test_ban_block_is_null_for_non_banned_user(): void
    {
        $user = User::factory()->create(['status' => 1]);

        $response = $this->withToken($this->adminToken())
            ->getJson("/api/admin/users/{$user->id}/status");

        $this->assertNull($response->json('data.ban'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================
    //
    // RV-34: the token helper that used to post config('admin.system_admin')
    // email/password to /api/admin/login is GONE. Admin auth now authenticates
    // an Employee BY USERNAME, and config/admin.php only carries phone +
    // wallet_prefix — so the old helper always returned a null token.
    // $this->adminToken() from Tests\Support\Concerns\ActsAsStaff mints a real
}
