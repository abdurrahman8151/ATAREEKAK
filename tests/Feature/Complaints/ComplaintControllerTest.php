<?php

namespace Tests\Feature\Complaints;

use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests ComplaintController (store, index, show) and indirectly
 * ComplaintService (submit, getUserComplaints, getForUser, format).
 */
class ComplaintControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->user = User::factory()->create(['password' => bcrypt('password123')]);
        $this->token = $this->getToken($this->user);
    }

    // ─── Auth ─────────────────────────────────────────────────────────────────

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/complaints', [])->assertStatus(401);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/complaints')->assertStatus(401);
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/complaints/1')->assertStatus(401);
    }

    // ─── store() ──────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_submit_complaint(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/complaints', [
                'title' => 'Driver was very rude',
                'description' => 'The driver insulted me during the entire trip and refused to follow the agreed route.',
                'type' => ComplaintType::DRIVER_BEHAVIOR->value,
            ])
            ->assertStatus(201)
            ->assertJsonPath('status', 'success');
    }

    public function test_complaint_is_persisted_to_database(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/complaints', [
                'title' => 'Payment issue',
                'description' => 'I was charged twice for the same ride and require an immediate refund.',
                'type' => ComplaintType::FINANCIAL_ISSUE->value,
            ]);

        $this->assertDatabaseHas('complaints', [
            'user_id' => $this->user->id,
            'title' => 'Payment issue',
        ]);
    }

    public function test_new_complaint_has_pending_status(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/complaints', [
                'title' => 'Test complaint',
                'description' => 'Detailed description of the issue that occurred during my trip.',
                'type' => ComplaintType::OTHER->value,
            ]);

        $this->assertDatabaseHas('complaints', [
            'user_id' => $this->user->id,
            'status' => ComplaintStatus::PENDING->value,
        ]);
    }

    public function test_response_contains_complaint_structure(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/complaints', [
                'title' => 'Trip safety issue',
                'description' => 'The driver was speeding excessively throughout the trip without any warning.',
                'type' => ComplaintType::TRIP_SAFETY->value,
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['status', 'message', 'complaint']);
    }

    public function test_store_fails_with_missing_title(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/complaints', [
                'description' => 'Description without a title',
                'type' => ComplaintType::OTHER->value,
            ])
            ->assertStatus(422);
    }

    public function test_store_fails_with_missing_description(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/complaints', [
                'title' => 'Title with no description',
                'type' => ComplaintType::OTHER->value,
            ])
            ->assertStatus(422);
    }

    public function test_store_fails_with_missing_type(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/complaints', [
                'title' => 'Valid title here',
                'description' => 'Valid description here that is long enough.',
            ])
            ->assertStatus(422);
    }

    public function test_store_fails_with_invalid_type(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/complaints', [
                'title' => 'Valid title',
                'description' => 'Valid description.',
                'type' => 'not_a_real_type',
            ])
            ->assertStatus(422);
    }

    public function test_store_accepts_all_valid_public_complaint_types(): void
    {
        // `no_show` is deliberately NOT included (owner-confirmed 2026-10-02, R2 sec 40.3): it is an
        // internal type, auto-created by Noshowservice when both parties file conflicting no-show
        // reports and handled manually by staff. The public endpoint validates against an explicit
        // list that excludes it (ComplaintController::store), so this test asserts the public
        // surface rather than blindly walking every ComplaintType case.
        $publicTypes = array_filter(
            ComplaintType::cases(),
            static fn (ComplaintType $type): bool => ! $type->isAutoGenerated()
        );

        foreach ($publicTypes as $type) {
            $response = $this->withToken($this->token)
                ->postJson('/api/complaints', [
                    'title' => "Complaint type: {$type->name}",
                    'description' => 'Detailed description of the issue provided for testing purposes.',
                    'type' => $type->value,
                ]);

            $this->assertEquals(201, $response->status(), "Failed for type: {$type->value}");
        }
    }

    /** The internal no-show type must stay unreachable from the public form (owner ruling). */
    public function test_store_rejects_the_internal_no_show_type(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/complaints', [
                'title' => 'Attempt to file a no-show complaint',
                'description' => 'The no-show type is internal and auto-created by the system.',
                'type' => ComplaintType::NO_SHOW->value,
            ]);

        $response->assertStatus(422);
    }

    public function test_store_with_attachments_succeeds(): void
    {
        $this->withToken($this->token)
            ->post('/api/complaints', [
                'title' => 'Complaint with attachment',
                'description' => 'Here is evidence attached as a file for this complaint.',
                'type' => ComplaintType::TRIP_SAFETY->value,
                'attachments' => [UploadedFile::fake()->image('evidence.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);
    }

    public function test_store_fails_when_attachment_exceeds_max_count(): void
    {
        $files = array_fill(0, 4, UploadedFile::fake()->image('extra.jpg'));

        $this->withToken($this->token)
            ->post('/api/complaints', [
                'title' => 'Too many attachments',
                'description' => 'Description here.',
                'type' => ComplaintType::OTHER->value,
                'attachments' => $files,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    // ─── index() ──────────────────────────────────────────────────────────────

    public function test_user_can_list_own_complaints(): void
    {
        Complaint::create($this->complaint(['user_id' => $this->user->id]));

        $this->withToken($this->token)
            ->getJson('/api/complaints')
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'data']);
    }

    public function test_index_returns_empty_array_when_no_complaints(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/complaints');
        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    public function test_index_excludes_other_users_complaints(): void
    {
        $other = User::factory()->create();

        Complaint::create($this->complaint(['user_id' => $this->user->id, 'title' => 'Mine']));
        Complaint::create($this->complaint(['user_id' => $other->id,      'title' => 'Theirs']));

        $response = $this->withToken($this->token)->getJson('/api/complaints');
        $response->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_returns_multiple_own_complaints(): void
    {
        Complaint::create($this->complaint(['user_id' => $this->user->id, 'title' => 'C1']));
        Complaint::create($this->complaint(['user_id' => $this->user->id, 'title' => 'C2']));

        $response = $this->withToken($this->token)->getJson('/api/complaints');
        $response->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
    }

    // ─── show() ───────────────────────────────────────────────────────────────

    public function test_user_can_view_own_complaint(): void
    {
        $complaint = Complaint::create($this->complaint(['user_id' => $this->user->id]));

        $this->withToken($this->token)
            ->getJson("/api/complaints/{$complaint->id}")
            ->assertStatus(200)
            ->assertJsonStructure(['status', 'data']);
    }

    public function test_user_cannot_view_another_users_complaint(): void
    {
        $other = User::factory()->create();
        $complaint = Complaint::create($this->complaint(['user_id' => $other->id]));

        $this->withToken($this->token)
            ->getJson("/api/complaints/{$complaint->id}")
            ->assertStatus(404);
    }

    public function test_show_returns_404_for_nonexistent_complaint(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/complaints/999999')
            ->assertStatus(404);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function complaint(array $overrides = []): array
    {
        return array_merge([
            'user_id' => $this->user->id,
            'title' => 'Default complaint title',
            'description' => 'Default complaint description for testing.',
            'type' => ComplaintType::OTHER->value,
            'status' => ComplaintStatus::PENDING->value,
        ], $overrides);
    }

    private function getToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }
}
