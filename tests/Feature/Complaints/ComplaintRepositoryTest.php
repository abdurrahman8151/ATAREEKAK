<?php

namespace Tests\Feature\Complaints;

use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Interfaces\ComplaintRepositoryInterface;
use App\Models\Complaint;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ComplaintRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ComplaintRepositoryInterface $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = app(ComplaintRepositoryInterface::class);
    }

    // ─── create() ─────────────────────────────────────────────────────────────

    public function test_create_returns_complaint_instance(): void
    {
        $user = User::factory()->create();
        $result = $this->repo->create($this->data($user->id));

        $this->assertInstanceOf(Complaint::class, $result);
    }

    public function test_create_persists_to_database(): void
    {
        $user = User::factory()->create();
        $complaint = $this->repo->create($this->data($user->id, 'Unique title for test'));

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'user_id' => $user->id,
            'title' => 'Unique title for test',
        ]);
    }

    public function test_create_sets_provided_type(): void
    {
        $user = User::factory()->create();
        $complaint = $this->repo->create($this->data($user->id, 'Title', ComplaintType::FINANCIAL_ISSUE));

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'type' => ComplaintType::FINANCIAL_ISSUE->value,
        ]);
    }

    public function test_create_sets_provided_status(): void
    {
        $user = User::factory()->create();
        $complaint = $this->repo->create($this->data($user->id));

        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => ComplaintStatus::PENDING->value,
        ]);
    }

    // ─── findById() ───────────────────────────────────────────────────────────

    public function test_find_by_id_returns_complaint_when_it_exists(): void
    {
        $user = User::factory()->create();
        $complaint = Complaint::create($this->data($user->id));

        $found = $this->repo->findById($complaint->id);

        $this->assertNotNull($found);
        $this->assertEquals($complaint->id, $found->id);
    }

    public function test_find_by_id_returns_null_for_nonexistent_id(): void
    {
        $result = $this->repo->findById(999999);
        $this->assertNull($result);
    }

    public function test_find_by_id_eager_loads_assigned_agent(): void
    {
        $user = User::factory()->create();
        $complaint = Complaint::create($this->data($user->id));

        $found = $this->repo->findById($complaint->id);

        // relationLoaded() confirms eager loading without an extra query
        $this->assertTrue($found->relationLoaded('assignedAgent'));
    }

    public function test_find_by_id_eager_loads_attachments(): void
    {
        $user = User::factory()->create();
        $complaint = Complaint::create($this->data($user->id));

        $found = $this->repo->findById($complaint->id);

        $this->assertTrue($found->relationLoaded('attachments'));
    }

    // ─── getUserComplaints() ──────────────────────────────────────────────────

    public function test_get_user_complaints_returns_collection(): void
    {
        $user = User::factory()->create();
        $result = $this->repo->getUserComplaints($user->id);

        $this->assertInstanceOf(Collection::class, $result);
    }

    public function test_get_user_complaints_returns_all_complaints_for_user(): void
    {
        $user = User::factory()->create();
        Complaint::create($this->data($user->id, 'C1'));
        Complaint::create($this->data($user->id, 'C2'));

        $results = $this->repo->getUserComplaints($user->id);

        $this->assertCount(2, $results);
    }

    public function test_get_user_complaints_excludes_other_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Complaint::create($this->data($user1->id, 'User1 complaint'));
        Complaint::create($this->data($user2->id, 'User2 complaint'));

        $results = $this->repo->getUserComplaints($user1->id);

        $this->assertCount(1, $results);
        $this->assertEquals('User1 complaint', $results->first()->title);
    }

    public function test_get_user_complaints_returns_empty_collection_when_none(): void
    {
        $user = User::factory()->create();
        $result = $this->repo->getUserComplaints($user->id);

        $this->assertEmpty($result);
    }

    public function test_get_user_complaints_ordered_newest_first(): void
    {
        $user = User::factory()->create();
        $older = Complaint::create($this->data($user->id, 'Older'));
        DB::table('complaints')
            ->where('id', $older->id)
            ->update(['created_at' => now()->subDays(2)]);

        $newer = Complaint::create($this->data($user->id, 'Newer'));

        $results = $this->repo->getUserComplaints($user->id);

        // Newest should appear first
        $this->assertEquals($newer->id, $results->first()->id);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function data(
        int $userId,
        string $title = 'Default Title',
        ComplaintType $type = ComplaintType::OTHER,
    ): array {
        return [
            'user_id' => $userId,
            'title' => $title,
            'description' => 'Default description for this complaint in tests.',
            'type' => $type->value,
            'status' => ComplaintStatus::PENDING->value,
        ];
    }
}
