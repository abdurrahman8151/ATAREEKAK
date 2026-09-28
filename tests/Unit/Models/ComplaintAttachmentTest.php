<?php

namespace Tests\Unit\Models;

use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Models\Complaint;
use App\Models\ComplaintAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplaintAttachmentTest extends TestCase
{
    use RefreshDatabase;

    // ─── Fillable ─────────────────────────────────────────────────────────────

    public function test_fillable_contains_complaint_id(): void
    {
        $this->assertContains('complaint_id', (new ComplaintAttachment)->getFillable());
    }

    public function test_fillable_contains_path(): void
    {
        $this->assertContains('path', (new ComplaintAttachment)->getFillable());
    }

    public function test_fillable_contains_original_name(): void
    {
        $this->assertContains('original_name', (new ComplaintAttachment)->getFillable());
    }

    public function test_fillable_contains_mime_type(): void
    {
        $this->assertContains('mime_type', (new ComplaintAttachment)->getFillable());
    }

    public function test_fillable_contains_size(): void
    {
        $this->assertContains('size', (new ComplaintAttachment)->getFillable());
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function test_has_complaint_relationship(): void
    {
        $this->assertTrue(method_exists(ComplaintAttachment::class, 'complaint'));
    }

    // ─── url accessor ─────────────────────────────────────────────────────────

    public function test_url_attribute_contains_file_path(): void
    {
        $attachment = ComplaintAttachment::create(array_merge(
            $this->baseAttachment(),
            ['path' => 'complaints/attachments/photo.jpg']
        ));

        $this->assertStringContainsString('photo.jpg', $attachment->url);
    }

    public function test_url_attribute_is_a_full_http_url(): void
    {
        $attachment = ComplaintAttachment::create($this->baseAttachment());

        $this->assertStringStartsWith('http', $attachment->url);
    }

    public function test_url_attribute_includes_storage_prefix(): void
    {
        $attachment = ComplaintAttachment::create(array_merge(
            $this->baseAttachment(),
            ['path' => 'complaints/doc.pdf']
        ));

        $this->assertStringContainsString('storage', $attachment->url);
    }

    // ─── Persistence ──────────────────────────────────────────────────────────

    public function test_attachment_can_be_created_in_database(): void
    {
        $attachment = ComplaintAttachment::create($this->baseAttachment());

        $this->assertDatabaseHas('complaint_attachments', [
            'id' => $attachment->id,
        ]);
    }

    public function test_complaint_relationship_returns_correct_complaint(): void
    {
        $attachment = ComplaintAttachment::create($this->baseAttachment());

        $this->assertNotNull($attachment->complaint);
        $this->assertInstanceOf(Complaint::class, $attachment->complaint);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function baseAttachment(): array
    {
        $user = User::factory()->create();
        $complaint = Complaint::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'description' => 'Description',
            'type' => ComplaintType::OTHER->value,
            'status' => ComplaintStatus::PENDING->value,
        ]);

        return [
            'complaint_id' => $complaint->id,
            'path' => 'complaints/attachments/test.jpg',
            'original_name' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
        ];
    }
}
