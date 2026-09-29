<?php

namespace Tests\Feature\Staff;

use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Enums\StaffRole;
use App\Models\Complaint;
use App\Models\Employee;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * StaffComplaintControllerTest
 *
 * UC-ADM-06: Support agent views and responds to user complaints.
 *
 * Routes under test (all behind `staff` middleware):
 *   GET   /api/staff/complaints                  → index()
 *   GET   /api/staff/complaints/{id}             → show()   ← auto pending→in_review
 *   PATCH /api/staff/complaints/{id}/respond     → respond()
 *   PATCH /api/staff/complaints/{id}/escalate    → escalate()
 *
 * WHY seedAdminWallets() AT THE TOP OF setUp():
 *   The staff login endpoint (or a service/event it triggers on a successful
 *   authentication) looks up admin User / Wallet rows by the values stored in
 *   config('admin.system_admin') and config('admin.sycash'). If those rows are
 *   absent the lookup throws an exception → 500 → json('tokens.access_token')
 *   returns null → TypeError on the string-typed property/return.
 *   Seeding the rows before the first login call prevents that failure.
 *   Pattern mirrors AdminDashboardControllerTest, StaffAuthControllerTest, and
 *   StaffOperationsControllerTest.
 */
class StaffComplaintControllerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private Employee $agent;

    private string $agentToken;

    private User $complaintUser;

    protected function setUp(): void
    {
        parent::setUp();

        // FIX: seed admin User + Wallet rows before any staff login so the
        // login success handler can resolve admin config references without
        // throwing an exception → 500 → null token → TypeError.
        $this->seedSystemWallets(10_000_000.0);

        $this->complaintUser = User::factory()->create(['password' => bcrypt('password123')]);
        $this->agent = $this->makeEmployee(StaffRole::SUPPORT_AGENT, 'agent@staff.test', 'support_agent_1');
        $this->agentToken = $this->getStaffToken('agent@staff.test', 'password123');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // index()
    // ══════════════════════════════════════════════════════════════════════════

    public function test_index_returns_200_with_success_status_for_authenticated_staff(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/complaints')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data', 'meta', 'counts']);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/staff/complaints')->assertStatus(401);
    }

    public function test_index_returns_empty_data_when_no_complaints_exist(): void
    {
        $response = $this->withToken($this->agentToken)->getJson('/api/staff/complaints');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_index_returns_all_existing_complaints(): void
    {
        $this->makeComplaint();
        $this->makeComplaint();
        $response = $this->withToken($this->agentToken)->getJson('/api/staff/complaints');
        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    public function test_index_filters_by_status_pending(): void
    {
        $this->makeComplaint(ComplaintStatus::PENDING);
        $this->makeComplaint(ComplaintStatus::RESOLVED);
        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/complaints?status=pending');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_filters_by_status_in_review(): void
    {
        $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->makeComplaint(ComplaintStatus::RESOLVED);
        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/complaints?status=in_review');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_filters_by_type_trip_safety(): void
    {
        $this->makeComplaint(ComplaintStatus::PENDING, ComplaintType::TRIP_SAFETY);
        $this->makeComplaint(ComplaintStatus::PENDING, ComplaintType::OTHER);
        $this->makeComplaint(ComplaintStatus::PENDING, ComplaintType::OTHER);
        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/complaints?type=trip_safety');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_filters_by_user_id(): void
    {
        $otherUser = User::factory()->create();
        $this->makeComplaint(ComplaintStatus::PENDING, ComplaintType::OTHER, $this->complaintUser);
        $this->makeComplaint(ComplaintStatus::PENDING, ComplaintType::OTHER, $otherUser);
        $response = $this->withToken($this->agentToken)
            ->getJson("/api/staff/complaints?user_id={$this->complaintUser->id}");
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_rejects_invalid_status_value(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/complaints?status=not_a_real_status')
            ->assertStatus(422);
    }

    public function test_index_rejects_invalid_type_value(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/complaints?type=not_a_real_type')
            ->assertStatus(422);
    }

    public function test_index_returns_pagination_meta(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/complaints?per_page=5')
            ->assertStatus(200)
            ->assertJsonStructure(['meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_index_returns_status_counts_for_tab_badges(): void
    {
        $this->makeComplaint(ComplaintStatus::PENDING);
        $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $response = $this->withToken($this->agentToken)->getJson('/api/staff/complaints');
        $response->assertStatus(200)
            ->assertJsonStructure(['counts' => ['all', 'pending', 'in_review', 'resolved', 'closed']]);
        $this->assertGreaterThanOrEqual(1, $response->json('counts.pending'));
        $this->assertGreaterThanOrEqual(1, $response->json('counts.in_review'));
    }

    public function test_index_respects_per_page_parameter(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeComplaint();
        }
        $response = $this->withToken($this->agentToken)
            ->getJson('/api/staff/complaints?per_page=2');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(5, $response->json('meta.total'));
        $this->assertEquals(3, $response->json('meta.last_page'));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // show()
    // ══════════════════════════════════════════════════════════════════════════

    public function test_show_returns_complaint_details(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::PENDING);
        $this->withToken($this->agentToken)
            ->getJson("/api/staff/complaints/{$complaint->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data']);
    }

    public function test_show_auto_transitions_pending_complaint_to_in_review(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::PENDING);
        $this->withToken($this->agentToken)
            ->getJson("/api/staff/complaints/{$complaint->id}")
            ->assertStatus(200);
        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::IN_REVIEW->value,
        ]);
    }

    public function test_show_does_not_change_already_in_review_complaint(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->withToken($this->agentToken)
            ->getJson("/api/staff/complaints/{$complaint->id}")
            ->assertStatus(200);
        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::IN_REVIEW->value,
        ]);
    }

    public function test_show_returns_404_for_nonexistent_complaint(): void
    {
        $this->withToken($this->agentToken)
            ->getJson('/api/staff/complaints/999999')
            ->assertStatus(404);
    }

    public function test_show_requires_authentication(): void
    {
        $complaint = $this->makeComplaint();
        $this->getJson("/api/staff/complaints/{$complaint->id}")
            ->assertStatus(401);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // respond()
    // ══════════════════════════════════════════════════════════════════════════

    public function test_respond_marks_complaint_as_resolved(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/respond", [
                'resolution_notes' => 'We have investigated and fully resolved this issue.',
                'status' => 'resolved',
            ])->assertStatus(200)
            ->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::RESOLVED->value,
        ]);
    }

    public function test_respond_can_close_a_complaint(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/respond", [
                'resolution_notes' => 'Complaint reviewed and closed per policy guidelines.',
                'status' => 'closed',
            ])->assertStatus(200);
        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::CLOSED->value,
        ]);
    }

    public function test_respond_can_keep_status_as_in_review(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/respond", [
                'resolution_notes' => 'Still actively investigating the reported issue.',
                'status' => 'in_review',
            ])->assertStatus(200);
        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::IN_REVIEW->value,
        ]);
    }

    public function test_respond_persists_resolution_notes(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $notes = 'Detailed resolution note confirming the issue has been addressed.';
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/respond", [
                'resolution_notes' => $notes,
                'status' => 'resolved',
            ])->assertStatus(200);
        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'resolution_notes' => $notes,
        ]);
    }

    public function test_respond_requires_resolution_notes(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/respond", [
                'status' => 'resolved',
            ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['resolution_notes']]);
    }

    public function test_respond_requires_resolution_notes_at_least_10_characters(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/respond", [
                'resolution_notes' => 'Short',   // 5 chars — below min:10
                'status' => 'resolved',
            ])->assertStatus(422);
    }

    public function test_respond_requires_status_field(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/respond", [
                'resolution_notes' => 'Valid resolution note for this complaint.',
            ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_respond_rejects_escalated_as_status_value(): void
    {
        // 'escalated' is NOT a valid status for respond — only for escalate()
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/respond", [
                'resolution_notes' => 'Valid resolution note for this complaint.',
                'status' => 'escalated',
            ])->assertStatus(422);
    }

    public function test_respond_returns_404_for_nonexistent_complaint(): void
    {
        $this->withToken($this->agentToken)
            ->patchJson('/api/staff/complaints/999999/respond', [
                'resolution_notes' => 'Valid resolution note for this complaint.',
                'status' => 'resolved',
            ])->assertStatus(404);
    }

    public function test_respond_requires_authentication(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->patchJson("/api/staff/complaints/{$complaint->id}/respond", [
            'resolution_notes' => 'Valid resolution note here.',
            'status' => 'resolved',
        ])->assertStatus(401);
    }

    public function test_respond_returns_updated_complaint_in_response(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/respond", [
                'resolution_notes' => 'Resolved the complaint successfully for the user.',
                'status' => 'resolved',
            ])->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // escalate()
    // ══════════════════════════════════════════════════════════════════════════

    public function test_escalate_changes_status_to_escalated(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/escalate", [
                'reason' => 'This complaint requires admin review due to financial complexity.',
            ])->assertStatus(200)
            ->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::ESCALATED->value,
        ]);
    }

    public function test_escalate_can_escalate_a_pending_complaint(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::PENDING);
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/escalate", [
                'reason' => 'Pending complaint needs immediate admin attention.',
            ])->assertStatus(200);
        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::ESCALATED->value,
        ]);
    }

    public function test_escalate_requires_reason(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/escalate", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    public function test_escalate_requires_reason_at_least_10_characters(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/escalate", [
                'reason' => 'Too short',   // 9 chars — below min:10
            ])->assertStatus(422);
    }

    public function test_escalate_returns_404_for_nonexistent_complaint(): void
    {
        $this->withToken($this->agentToken)
            ->patchJson('/api/staff/complaints/999999/escalate', [
                'reason' => 'Valid escalation reason for this complaint.',
            ])->assertStatus(404);
    }

    public function test_escalate_requires_authentication(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->patchJson("/api/staff/complaints/{$complaint->id}/escalate", [
            'reason' => 'Valid escalation reason here.',
        ])->assertStatus(401);
    }

    public function test_cannot_escalate_an_already_resolved_complaint(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::RESOLVED);
        $response = $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/escalate", [
                'reason' => 'Attempting to escalate a resolved complaint.',
            ]);
        // ComplaintStatus::RESOLVED is not agent-actionable → DomainException → 422
        $this->assertNotEquals(200, $response->status());
    }

    public function test_cannot_escalate_an_already_escalated_complaint(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::ESCALATED);
        $response = $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/escalate", [
                'reason' => 'Attempting to escalate an already escalated complaint.',
            ]);
        // ComplaintStatus::ESCALATED is not agent-actionable → DomainException → 422
        $this->assertNotEquals(200, $response->status());
    }

    public function test_escalate_returns_updated_complaint_in_response(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->withToken($this->agentToken)
            ->patchJson("/api/staff/complaints/{$complaint->id}/escalate", [
                'reason' => 'Requires admin intervention for this complex case.',
            ])->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeEmployee(StaffRole $role, string $email, string $username): Employee
    {
        return Employee::create([
            'username' => $username,
            'email' => $email,
            'password' => bcrypt('password123'),
            'first_name' => 'Staff',
            'last_name' => 'Member',
            'role' => $role->value,
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    private function makeComplaint(
        ComplaintStatus $status = ComplaintStatus::PENDING,
        ComplaintType $type = ComplaintType::OTHER,
        ?User $user = null,
    ): Complaint {
        return Complaint::create([
            'user_id' => ($user ?? $this->complaintUser)->id,
            'title' => 'Test Complaint',
            'description' => 'A detailed test complaint description.',
            'type' => $type->value,
            'status' => $status->value,
        ]);
    }

    private function getStaffToken(string $identifier, string $password): string
    {
        $token = $this->postJson('/api/staff/login', [
            'identifier' => $identifier,
            'password' => $password,
        ])->json('tokens.access_token');

        // Fail with a clear message rather than a cryptic TypeError if the
        // login still returns non-200 (e.g. seedAdminWallets() didn't fully
        // satisfy the login success handler).
        $this->assertNotNull(
            $token,
            "getStaffToken('{$identifier}'): login returned null — verify that ".
            'seedAdminWallets() creates all rows the login success handler requires.'
        );

        return $token;
    }
}
