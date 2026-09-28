<?php

namespace Tests\Unit\Services\Staff;

use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Enums\StaffRole;
use App\Models\Complaint;
use App\Models\Employee;
use App\Models\User;
use App\Services\Staff\StaffComplaintService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * StaffComplaintServiceTest
 *
 * Unit tests for StaffComplaintService, resolved from the container.
 * Tests cover every public method:
 *   - listAll()         (used by StaffComplaintController)
 *   - listEscalated()   (used by StaffAdminController)
 *   - openComplaint()   (used by StaffComplaintController::show)
 *   - respond()         (used by StaffComplaintController::respond)
 *   - escalate()        (used by StaffComplaintController::escalate)
 *   - resolveEscalated()(used by StaffAdminController::resolveEscalated)
 *   - format()          (used by both controllers)
 */
class StaffComplaintServiceTest extends TestCase
{
    use RefreshDatabase;

    private StaffComplaintService $service;

    private Employee $agent;

    private Employee $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(StaffComplaintService::class);

        $this->user = User::factory()->create();

        $this->agent = Employee::create([
            'username' => 'svc_test_agent',
            'email' => 'svc_agent@test.test',
            'password' => bcrypt('password123'),
            'first_name' => 'Service',
            'last_name' => 'Agent',
            'role' => StaffRole::SUPPORT_AGENT->value,
            'is_active' => true,
            'token_version' => 0,
        ]);

        $this->admin = Employee::create([
            'username' => 'svc_test_admin',
            'email' => 'svc_admin@test.test',
            'password' => bcrypt('password123'),
            'first_name' => 'Service',
            'last_name' => 'Admin',
            'role' => StaffRole::ADMIN->value,
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // listAll()
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_list_all_returns_a_length_aware_paginator(): void
    {
        $this->assertInstanceOf(LengthAwarePaginator::class, $this->service->listAll());
    }

    public function test_list_all_returns_empty_paginator_when_no_complaints_exist(): void
    {
        $this->assertEquals(0, $this->service->listAll()->total());
    }

    public function test_list_all_returns_every_complaint_by_default(): void
    {
        $this->makeComplaint(ComplaintStatus::PENDING);
        $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $this->makeComplaint(ComplaintStatus::RESOLVED);

        $this->assertEquals(3, $this->service->listAll()->total());
    }

    public function test_list_all_filters_by_status(): void
    {
        $this->makeComplaint(ComplaintStatus::PENDING);
        $this->makeComplaint(ComplaintStatus::PENDING);
        $this->makeComplaint(ComplaintStatus::RESOLVED);

        $this->assertEquals(2, $this->service->listAll(status: 'pending')->total());
        $this->assertEquals(1, $this->service->listAll(status: 'resolved')->total());
    }

    public function test_list_all_filters_by_type(): void
    {
        $this->makeComplaint(type: ComplaintType::TRIP_SAFETY);
        $this->makeComplaint(type: ComplaintType::OTHER);
        $this->makeComplaint(type: ComplaintType::OTHER);

        $this->assertEquals(1, $this->service->listAll(type: 'trip_safety')->total());
        $this->assertEquals(2, $this->service->listAll(type: 'other')->total());
    }

    public function test_list_all_filters_by_user_id(): void
    {
        $otherUser = User::factory()->create();
        $this->makeComplaint(user: $this->user);
        $this->makeComplaint(user: $this->user);
        $this->makeComplaint(user: $otherUser);

        $this->assertEquals(2, $this->service->listAll(userId: $this->user->id)->total());
        $this->assertEquals(1, $this->service->listAll(userId: $otherUser->id)->total());
    }

    public function test_list_all_respects_per_page(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeComplaint();
        }

        $paginator = $this->service->listAll(perPage: 2);
        $this->assertEquals(2, $paginator->perPage());
        $this->assertEquals(5, $paginator->total());
        $this->assertEquals(3, $paginator->lastPage());
    }

    public function test_list_all_respects_page_number(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->makeComplaint();
        }

        $this->assertEquals(2, $this->service->listAll(perPage: 2, page: 2)->currentPage());
    }

    public function test_list_all_combines_status_and_type_filters(): void
    {
        $this->makeComplaint(ComplaintStatus::PENDING, ComplaintType::TRIP_SAFETY);
        $this->makeComplaint(ComplaintStatus::PENDING, ComplaintType::OTHER);
        $this->makeComplaint(ComplaintStatus::RESOLVED, ComplaintType::TRIP_SAFETY);

        $this->assertEquals(
            1,
            $this->service->listAll(status: 'pending', type: 'trip_safety')->total()
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // listEscalated()
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_list_escalated_returns_a_paginator(): void
    {
        $this->assertInstanceOf(LengthAwarePaginator::class, $this->service->listEscalated());
    }

    public function test_list_escalated_returns_only_escalated_complaints_by_default(): void
    {
        $this->makeComplaint(ComplaintStatus::ESCALATED);
        $this->makeComplaint(ComplaintStatus::ESCALATED);
        $this->makeComplaint(ComplaintStatus::PENDING);
        $this->makeComplaint(ComplaintStatus::RESOLVED);

        $this->assertEquals(2, $this->service->listEscalated()->total());
    }

    public function test_list_escalated_can_filter_by_resolved_status(): void
    {
        $this->makeComplaint(ComplaintStatus::ESCALATED);
        $this->makeComplaint(ComplaintStatus::RESOLVED);

        $this->assertEquals(1, $this->service->listEscalated(status: 'resolved')->total());
    }

    public function test_list_escalated_can_filter_by_closed_status(): void
    {
        $this->makeComplaint(ComplaintStatus::ESCALATED);
        $this->makeComplaint(ComplaintStatus::CLOSED);
        $this->makeComplaint(ComplaintStatus::CLOSED);

        $this->assertEquals(2, $this->service->listEscalated(status: 'closed')->total());
    }

    public function test_list_escalated_respects_per_page(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->makeComplaint(ComplaintStatus::ESCALATED);
        }

        $paginator = $this->service->listEscalated(perPage: 3);
        $this->assertEquals(3, $paginator->perPage());
        $this->assertEquals(6, $paginator->total());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // openComplaint()
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_open_complaint_returns_a_complaint_model(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::PENDING);

        $result = $this->service->openComplaint($complaint->id, $this->agent);

        $this->assertInstanceOf(Complaint::class, $result);
        $this->assertEquals($complaint->id, $result->id);
    }

    public function test_open_complaint_transitions_pending_to_in_review(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::PENDING);

        $this->service->openComplaint($complaint->id, $this->agent);

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::IN_REVIEW->value,
        ]);
    }

    public function test_open_complaint_assigns_complaint_to_agent(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::PENDING);

        $this->service->openComplaint($complaint->id, $this->agent);

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'assigned_to' => $this->agent->id,
        ]);
    }

    public function test_open_complaint_leaves_already_in_review_complaint_unchanged(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);

        $this->service->openComplaint($complaint->id, $this->agent);

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::IN_REVIEW->value,
        ]);
    }

    public function test_open_complaint_does_not_change_resolved_complaint(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::RESOLVED);

        $this->service->openComplaint($complaint->id, $this->agent);

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::RESOLVED->value,
        ]);
    }

    public function test_open_complaint_throws_model_not_found_for_missing_id(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->openComplaint(999999, $this->agent);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // respond()
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_respond_returns_updated_complaint_instance(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);

        $result = $this->service->respond(
            complaintId: $complaint->id,
            resolutionNotes: 'We investigated and resolved this complaint.',
            newStatus: ComplaintStatus::RESOLVED,
            agent: $this->agent,
        );

        $this->assertInstanceOf(Complaint::class, $result);
    }

    public function test_respond_updates_status_to_resolved(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);

        $this->service->respond(
            complaintId: $complaint->id,
            resolutionNotes: 'Resolved the complaint successfully.',
            newStatus: ComplaintStatus::RESOLVED,
            agent: $this->agent,
        );

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::RESOLVED->value,
        ]);
    }

    public function test_respond_persists_resolution_notes_to_database(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);
        $notes = 'Complaint resolved after verifying passenger claim with driver.';

        $this->service->respond(
            complaintId: $complaint->id,
            resolutionNotes: $notes,
            newStatus: ComplaintStatus::RESOLVED,
            agent: $this->agent,
        );

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'resolution_notes' => $notes,
        ]);
    }

    public function test_respond_can_close_a_complaint(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);

        $this->service->respond(
            complaintId: $complaint->id,
            resolutionNotes: 'Closed complaint after review — no action required.',
            newStatus: ComplaintStatus::CLOSED,
            agent: $this->agent,
        );

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::CLOSED->value,
        ]);
    }

    public function test_respond_throws_model_not_found_for_missing_id(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->respond(
            complaintId: 999999,
            resolutionNotes: 'Trying to respond to a missing complaint.',
            newStatus: ComplaintStatus::RESOLVED,
            agent: $this->agent,
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // escalate()
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_escalate_returns_updated_complaint_instance(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);

        $result = $this->service->escalate(
            complaintId: $complaint->id,
            reason: 'Requires admin review — case involves a financial dispute.',
            agent: $this->agent,
        );

        $this->assertInstanceOf(Complaint::class, $result);
    }

    public function test_escalate_changes_status_to_escalated_from_in_review(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);

        $this->service->escalate(
            complaintId: $complaint->id,
            reason: 'Needs admin attention for this complex case.',
            agent: $this->agent,
        );

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::ESCALATED->value,
        ]);
    }

    public function test_escalate_changes_status_to_escalated_from_pending(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::PENDING);

        $this->service->escalate(
            complaintId: $complaint->id,
            reason: 'Pending complaint needs immediate admin review.',
            agent: $this->agent,
        );

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::ESCALATED->value,
        ]);
    }

    public function test_escalate_throws_domain_exception_for_resolved_complaint(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::RESOLVED);

        $this->expectException(\DomainException::class);

        $this->service->escalate(
            complaintId: $complaint->id,
            reason: 'Trying to escalate a resolved complaint.',
            agent: $this->agent,
        );
    }

    public function test_escalate_throws_domain_exception_for_closed_complaint(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::CLOSED);

        $this->expectException(\DomainException::class);

        $this->service->escalate(
            complaintId: $complaint->id,
            reason: 'Trying to escalate a closed complaint.',
            agent: $this->agent,
        );
    }

    public function test_escalate_throws_domain_exception_for_already_escalated_complaint(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::ESCALATED);

        $this->expectException(\DomainException::class);

        $this->service->escalate(
            complaintId: $complaint->id,
            reason: 'Trying to escalate an already escalated complaint.',
            agent: $this->agent,
        );
    }

    public function test_escalate_throws_model_not_found_for_missing_id(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->escalate(
            complaintId: 999999,
            reason: 'Valid reason.',
            agent: $this->agent,
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // resolveEscalated()
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_resolve_escalated_returns_updated_complaint(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::ESCALATED);

        $result = $this->service->resolveEscalated(
            complaintId: $complaint->id,
            resolutionNotes: 'Admin has reviewed and resolved this escalated complaint.',
            newStatus: ComplaintStatus::RESOLVED,
            admin: $this->admin,
        );

        $this->assertInstanceOf(Complaint::class, $result);
    }

    public function test_resolve_escalated_changes_status_to_resolved(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::ESCALATED);

        $this->service->resolveEscalated(
            complaintId: $complaint->id,
            resolutionNotes: 'Admin resolved the escalated complaint after investigation.',
            newStatus: ComplaintStatus::RESOLVED,
            admin: $this->admin,
        );

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::RESOLVED->value,
        ]);
    }

    public function test_resolve_escalated_can_close_the_complaint(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::ESCALATED);

        $this->service->resolveEscalated(
            complaintId: $complaint->id,
            resolutionNotes: 'Admin closed the complaint after full investigation.',
            newStatus: ComplaintStatus::CLOSED,
            admin: $this->admin,
        );

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::CLOSED->value,
        ]);
    }

    public function test_resolve_escalated_persists_resolution_notes(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::ESCALATED);
        $notes = 'Admin resolution: refund issued to passenger after driver dispute.';

        $this->service->resolveEscalated(
            complaintId: $complaint->id,
            resolutionNotes: $notes,
            newStatus: ComplaintStatus::RESOLVED,
            admin: $this->admin,
        );

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'resolution_notes' => $notes,
        ]);
    }

    public function test_resolve_escalated_throws_domain_exception_for_non_escalated_complaint(): void
    {
        // Only ESCALATED complaints can be resolved via this method
        $complaint = $this->makeComplaint(ComplaintStatus::PENDING);

        $this->expectException(\DomainException::class);

        $this->service->resolveEscalated(
            complaintId: $complaint->id,
            resolutionNotes: 'Attempting to admin-resolve a non-escalated complaint.',
            newStatus: ComplaintStatus::RESOLVED,
            admin: $this->admin,
        );
    }

    public function test_resolve_escalated_throws_domain_exception_for_in_review_complaint(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW);

        $this->expectException(\DomainException::class);

        $this->service->resolveEscalated(
            complaintId: $complaint->id,
            resolutionNotes: 'Attempting to admin-resolve an in-review complaint.',
            newStatus: ComplaintStatus::RESOLVED,
            admin: $this->admin,
        );
    }

    public function test_resolve_escalated_throws_model_not_found_for_missing_id(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->resolveEscalated(
            complaintId: 999999,
            resolutionNotes: 'Valid resolution note.',
            newStatus: ComplaintStatus::RESOLVED,
            admin: $this->admin,
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // format()
    // ═══════════════════════════════════════════════════════════════════════════

    public function test_format_returns_an_array(): void
    {
        $complaint = $this->makeComplaint()->load(['user', 'assignedAgent', 'attachments']);

        $this->assertIsArray($this->service->format($complaint));
    }

    public function test_format_includes_complaint_id(): void
    {
        $complaint = $this->makeComplaint()->load(['user', 'assignedAgent', 'attachments']);
        $formatted = $this->service->format($complaint);

        $this->assertArrayHasKey('id', $formatted);
        $this->assertEquals($complaint->id, $formatted['id']);
    }

    public function test_format_includes_status(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::IN_REVIEW)
            ->load(['user', 'assignedAgent', 'attachments']);

        $formatted = $this->service->format($complaint);

        $this->assertArrayHasKey('status', $formatted);
    }

    public function test_format_includes_type(): void
    {
        $complaint = $this->makeComplaint(type: ComplaintType::TRIP_SAFETY)
            ->load(['user', 'assignedAgent', 'attachments']);

        $formatted = $this->service->format($complaint);

        $this->assertArrayHasKey('type', $formatted);
    }

    public function test_format_includes_created_at(): void
    {
        $complaint = $this->makeComplaint()->load(['user', 'assignedAgent', 'attachments']);

        $formatted = $this->service->format($complaint);

        $this->assertArrayHasKey('created_at', $formatted);
    }

    public function test_format_status_reflects_the_complaint_current_status(): void
    {
        $complaint = $this->makeComplaint(ComplaintStatus::RESOLVED)
            ->load(['user', 'assignedAgent', 'attachments']);

        $formatted = $this->service->format($complaint);

        $statusValue = $complaint->status instanceof ComplaintStatus
            ? $complaint->status->value
            : (string) $complaint->status;

        $this->assertEquals($statusValue, $formatted['status']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeComplaint(
        ComplaintStatus $status = ComplaintStatus::PENDING,
        ComplaintType $type = ComplaintType::OTHER,
        ?User $user = null,
    ): Complaint {
        return Complaint::create([
            'user_id' => ($user ?? $this->user)->id,
            'title' => 'Test Complaint',
            'description' => 'A sufficiently detailed test complaint description.',
            'type' => $type->value,
            'status' => $status->value,
        ]);
    }
}
