<?php

namespace Tests\Unit\Models;

use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplaintTest extends TestCase
{
    use RefreshDatabase;

    // ─── Fillable ─────────────────────────────────────────────────────────────

    public function test_fillable_contains_user_id(): void
    {
        $this->assertContains('user_id', (new Complaint)->getFillable());
    }

    public function test_fillable_contains_assigned_to(): void
    {
        $this->assertContains('assigned_to', (new Complaint)->getFillable());
    }

    public function test_fillable_contains_title(): void
    {
        $this->assertContains('title', (new Complaint)->getFillable());
    }

    public function test_fillable_contains_description(): void
    {
        $this->assertContains('description', (new Complaint)->getFillable());
    }

    public function test_fillable_contains_type(): void
    {
        $this->assertContains('type', (new Complaint)->getFillable());
    }

    public function test_fillable_contains_status(): void
    {
        $this->assertContains('status', (new Complaint)->getFillable());
    }

    public function test_fillable_contains_resolution_notes(): void
    {
        $this->assertContains('resolution_notes', (new Complaint)->getFillable());
    }

    public function test_fillable_contains_resolved_at(): void
    {
        $this->assertContains('resolved_at', (new Complaint)->getFillable());
    }

    // ─── Casts ────────────────────────────────────────────────────────────────

    public function test_status_is_cast_to_complaint_status_enum(): void
    {
        $casts = (new Complaint)->getCasts();
        $this->assertArrayHasKey('status', $casts);
        $this->assertEquals(ComplaintStatus::class, $casts['status']);
    }

    public function test_type_is_cast_to_complaint_type_enum(): void
    {
        $casts = (new Complaint)->getCasts();
        $this->assertArrayHasKey('type', $casts);
        $this->assertEquals(ComplaintType::class, $casts['type']);
    }

    public function test_resolved_at_is_cast_to_datetime(): void
    {
        $casts = (new Complaint)->getCasts();
        $this->assertArrayHasKey('resolved_at', $casts);
        $this->assertEquals('datetime', $casts['resolved_at']);
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function test_has_user_relationship(): void
    {
        $this->assertTrue(method_exists(Complaint::class, 'user'));
    }

    public function test_has_assigned_agent_relationship(): void
    {
        $this->assertTrue(method_exists(Complaint::class, 'assignedAgent'));
    }

    public function test_has_attachments_relationship(): void
    {
        $this->assertTrue(method_exists(Complaint::class, 'attachments'));
    }

    // ─── Persistence ──────────────────────────────────────────────────────────

    public function test_complaint_can_be_created_in_database(): void
    {
        $user = User::factory()->create();

        $complaint = Complaint::create([
            'user_id' => $user->id,
            'title' => 'Test Complaint',
            'description' => 'This is a test complaint description.',
            'type' => ComplaintType::OTHER->value,
            'status' => ComplaintStatus::PENDING->value,
        ]);

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'user_id' => $user->id,
            'title' => 'Test Complaint',
        ]);
    }

    public function test_status_is_cast_to_enum_on_retrieval(): void
    {
        $user = User::factory()->create();
        $complaint = Complaint::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'description' => 'Description',
            'type' => ComplaintType::OTHER->value,
            'status' => ComplaintStatus::PENDING->value,
        ]);

        $fresh = Complaint::find($complaint->id);
        $this->assertInstanceOf(ComplaintStatus::class, $fresh->status);
        $this->assertEquals(ComplaintStatus::PENDING, $fresh->status);
    }

    public function test_type_is_cast_to_enum_on_retrieval(): void
    {
        $user = User::factory()->create();
        $complaint = Complaint::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'description' => 'Description',
            'type' => ComplaintType::FINANCIAL_ISSUE->value,
            'status' => ComplaintStatus::PENDING->value,
        ]);

        $fresh = Complaint::find($complaint->id);
        $this->assertInstanceOf(ComplaintType::class, $fresh->type);
        $this->assertEquals(ComplaintType::FINANCIAL_ISSUE, $fresh->type);
    }

    public function test_user_relationship_returns_correct_user(): void
    {
        $user = User::factory()->create();
        $complaint = Complaint::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'description' => 'Description',
            'type' => ComplaintType::OTHER->value,
            'status' => ComplaintStatus::PENDING->value,
        ]);

        $this->assertEquals($user->id, $complaint->user->id);
    }

    public function test_resolved_at_defaults_to_null(): void
    {
        $user = User::factory()->create();
        $complaint = Complaint::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'description' => 'Description',
            'type' => ComplaintType::OTHER->value,
            'status' => ComplaintStatus::PENDING->value,
        ]);

        $this->assertNull($complaint->resolved_at);
    }
}
