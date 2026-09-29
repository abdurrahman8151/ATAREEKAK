<?php

namespace Staff;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * StaffAdminControllerTest — Feature tests for StaffAdminController.
 *
 * Uses the system_admin JWT (from /api/admin/login) on all staff routes because
 * StaffJwtMiddleware::handleAdminToken() maps the primary admin token to a
 * system_admin Employee, giving it full access to admin+system_admin routes.
 *
 * KNOWN BEHAVIOUR: sycash admin cannot access any /api/staff/* route because
 * StaffJwtMiddleware::handleAdminToken() only accepts the system_admin email —
 * the sycash email fails the check and returns 401.
 *
 * COVERS:
 *   GET   /api/staff/verifications/pending              → pendingVerifications()
 *   POST  /api/staff/verifications/{id}/approve         → approveVerification()
 *   POST  /api/staff/verifications/{id}/reject          → rejectVerification()
 *   GET   /api/staff/escalated-complaints               → escalatedComplaints()
 *   PATCH /api/staff/escalated-complaints/{id}/resolve  → resolveEscalated()
 */
class StaffAdminControllerTest extends TestCase
{
    use ActsAsStaff;
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
    }

    // ─── GET /api/staff/verifications/pending ─────────────────────────────────

    public function test_can_list_pending_verifications(): void
    {
        User::factory()->create(['verification_status' => 'pending']);

        $this->withToken($this->adminToken())
            ->getJson('/api/staff/verifications/pending')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['total', 'data']);
    }

    public function test_pending_verifications_requires_auth(): void
    {
        $this->getJson('/api/staff/verifications/pending')
            ->assertStatus(401);
    }

    public function test_pending_verifications_returns_empty_when_none_exist(): void
    {
        $response = $this->withToken($this->adminToken())
            ->getJson('/api/staff/verifications/pending');

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('total'));
    }

    public function test_pending_verifications_excludes_approved_users(): void
    {
        User::factory()->create(['verification_status' => 'approved']);

        $response = $this->withToken($this->adminToken())
            ->getJson('/api/staff/verifications/pending');

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('total'));
    }

    // ─── POST /api/staff/verifications/{userId}/approve ───────────────────────

    public function test_can_approve_passenger_verification(): void
    {
        $user = User::factory()->create(['verification_status' => 'pending']);

        $this->withToken($this->adminToken())
            ->postJson("/api/staff/verifications/{$user->id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'verification_status' => 'approved',
            'is_verified_passenger' => true,
        ]);
    }

    public function test_approve_verification_requires_auth(): void
    {
        $user = User::factory()->create(['verification_status' => 'pending']);

        $this->postJson("/api/staff/verifications/{$user->id}/approve")
            ->assertStatus(401);
    }

    public function test_approve_verification_returns_422_for_nonexistent_user(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/staff/verifications/999999/approve')
            ->assertStatus(422);
    }

    public function test_approve_verification_fails_when_status_is_not_pending(): void
    {
        $user = User::factory()->create(['verification_status' => 'approved']);

        $this->withToken($this->adminToken())
            ->postJson("/api/staff/verifications/{$user->id}/approve")
            ->assertStatus(422);
    }

    // ─── POST /api/staff/verifications/{userId}/reject ────────────────────────

    public function test_can_reject_pending_verification(): void
    {
        $user = User::factory()->create([
            'verification_status' => 'pending',
            'is_verified_driver' => true,
            'is_verified_passenger' => true,
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/staff/verifications/{$user->id}/reject", [
                'reason' => 'Documents were unreadable.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'verification_status' => 'rejected',
            'is_verified_driver' => false,
            'is_verified_passenger' => false,
        ]);
    }

    public function test_reject_verification_requires_auth(): void
    {
        $user = User::factory()->create(['verification_status' => 'pending']);

        $this->postJson("/api/staff/verifications/{$user->id}/reject")
            ->assertStatus(401);
    }

    public function test_reject_verification_returns_404_for_nonexistent_user(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/staff/verifications/999999/reject')
            ->assertStatus(404);
    }

    public function test_reject_verification_fails_when_status_is_not_pending(): void
    {
        $user = User::factory()->create(['verification_status' => 'approved']);

        $this->withToken($this->adminToken())
            ->postJson("/api/staff/verifications/{$user->id}/reject")
            ->assertStatus(422);
    }

    public function test_reject_reason_is_optional(): void
    {
        $user = User::factory()->create(['verification_status' => 'pending']);

        $this->withToken($this->adminToken())
            ->postJson("/api/staff/verifications/{$user->id}/reject", [])
            ->assertStatus(200);
    }

    // ─── GET /api/staff/escalated-complaints ──────────────────────────────────

    public function test_can_list_escalated_complaints(): void
    {
        $this->withToken($this->adminToken())
            ->getJson('/api/staff/escalated-complaints')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data', 'meta', 'counts']);
    }

    public function test_escalated_complaints_requires_auth(): void
    {
        $this->getJson('/api/staff/escalated-complaints')
            ->assertStatus(401);
    }

    public function test_escalated_complaints_rejects_invalid_status_filter(): void
    {
        $this->withToken($this->adminToken())
            ->getJson('/api/staff/escalated-complaints?status=not_valid')
            ->assertStatus(422);
    }

    public function test_escalated_complaints_rejects_invalid_type_filter(): void
    {
        $this->withToken($this->adminToken())
            ->getJson('/api/staff/escalated-complaints?type=not_a_valid_type')
            ->assertStatus(422);
    }

    public function test_escalated_complaints_returns_empty_data_when_none_exist(): void
    {
        $response = $this->withToken($this->adminToken())
            ->getJson('/api/staff/escalated-complaints');

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    public function test_escalated_complaints_accepts_valid_status_filter(): void
    {
        $this->withToken($this->adminToken())
            ->getJson('/api/staff/escalated-complaints?status=resolved')
            ->assertStatus(200);
    }

    // ─── PATCH /api/staff/escalated-complaints/{id}/resolve ───────────────────

    public function test_resolve_escalated_requires_auth(): void
    {
        $this->patchJson('/api/staff/escalated-complaints/1/resolve', [
            'resolution_notes' => 'Resolved after full investigation.',
            'status' => 'resolved',
        ])->assertStatus(401);
    }

    public function test_resolve_escalated_returns_422_with_missing_fields(): void
    {
        $this->withToken($this->adminToken())
            ->patchJson('/api/staff/escalated-complaints/1/resolve', [])
            ->assertStatus(422);
    }

    public function test_resolve_escalated_returns_422_when_notes_too_short(): void
    {
        $this->withToken($this->adminToken())
            ->patchJson('/api/staff/escalated-complaints/1/resolve', [
                'resolution_notes' => 'Short',
                'status' => 'resolved',
            ])->assertStatus(422);
    }

    public function test_resolve_escalated_rejects_invalid_status(): void
    {
        $this->withToken($this->adminToken())
            ->patchJson('/api/staff/escalated-complaints/1/resolve', [
                'resolution_notes' => 'A sufficiently detailed resolution note.',
                'status' => 'pending',
            ])->assertStatus(422);
    }

    public function test_resolve_escalated_returns_404_for_nonexistent_complaint(): void
    {
        $this->withToken($this->adminToken())
            ->patchJson('/api/staff/escalated-complaints/999999/resolve', [
                'resolution_notes' => 'Resolved after thorough investigation.',
                'status' => 'resolved',
            ])->assertStatus(404);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    // RV-34: the local adminToken() helper is GONE. It posted
    // config('admin.system_admin.email'|'password') to /api/admin/login, but admin
    // auth authenticates an Employee by USERNAME (credentials live in the
    // employees table), so it always returned null and tripped the `: string`
    // return type — 19 errors in this file. The shared ActsAsStaff trait provides
    // adminToken() with the same name, so all 19 existing `$this->adminToken()`
    // call sites now resolve to it with no other change.
}
