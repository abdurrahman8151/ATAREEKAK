<?php

namespace Tests\Feature\Staff;

use App\Models\Employee;
use App\Models\Profile;
use App\Models\ProfileComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private Employee $agent;

    private ?string $token = null;    // ← was: private string $token

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Employee::create([
            'username' => 'review_agent',
            'email' => 'review@staff.test',
            'password' => bcrypt('password123'),
            'first_name' => 'Review',
            'last_name' => 'Agent',
            'role' => 'support_agent',
            'is_active' => true,
            'token_version' => 0,
        ]);

        $loginResponse = $this->postJson('/api/staff/login', [
            'identifier' => 'review_agent',
            'password' => 'password123',
        ]);

        $this->token = $loginResponse->json('tokens.access_token');

        // Gives a clear error if login is broken, rather than a cryptic TypeError
        $this->assertNotNull(
            $this->token,
            'Staff login failed in setUp: '.$loginResponse->getContent()
        );
    }

    // ─── GET /api/staff/reviews ───────────────────────────────────────────────

    public function test_can_list_reviews_when_none_exist(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/staff/reviews')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['status', 'data', 'meta']);
    }

    public function test_index_returns_created_comments(): void
    {
        $this->createComment();
        $this->createComment();

        $response = $this->withToken($this->token)
            ->getJson('/api/staff/reviews');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('meta.total'));
    }

    public function test_index_meta_contains_pagination_fields(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/staff/reviews')
            ->assertStatus(200)
            ->assertJsonStructure([
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_index_respects_per_page_parameter(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createComment();
        }

        $response = $this->withToken($this->token)
            ->getJson('/api/staff/reviews?per_page=2');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('meta.per_page'));
        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_rejects_per_page_of_zero(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/staff/reviews?per_page=0')
            ->assertStatus(422);
    }

    public function test_index_rejects_per_page_above_fifty(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/staff/reviews?per_page=51')
            ->assertStatus(422);
    }

    public function test_index_rejects_invalid_date_filter(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/staff/reviews?date=last_year')
            ->assertStatus(422);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/staff/reviews')->assertStatus(401);
    }

    // ─── DELETE /api/staff/reviews/{id} ──────────────────────────────────────

    public function test_can_delete_an_existing_comment(): void
    {
        $comment = $this->createComment();

        $this->withToken($this->token)
            ->deleteJson("/api/staff/reviews/{$comment->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('profile_comments', ['id' => $comment->id]);
    }

    public function test_delete_response_contains_success_message(): void
    {
        $comment = $this->createComment();

        $this->withToken($this->token)
            ->deleteJson("/api/staff/reviews/{$comment->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Comment deleted successfully.');
    }

    public function test_delete_returns_404_for_nonexistent_comment(): void
    {
        $this->withToken($this->token)
            ->deleteJson('/api/staff/reviews/999999')
            ->assertStatus(404);
    }

    public function test_delete_requires_authentication(): void
    {
        $comment = $this->createComment();

        $this->deleteJson("/api/staff/reviews/{$comment->id}")->assertStatus(401);
    }

    public function test_deleting_same_comment_twice_returns_404_on_second_attempt(): void
    {
        $comment = $this->createComment();

        $this->withToken($this->token)
            ->deleteJson("/api/staff/reviews/{$comment->id}")
            ->assertStatus(200);

        $this->withToken($this->token)
            ->deleteJson("/api/staff/reviews/{$comment->id}")
            ->assertStatus(404);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function createComment(): ProfileComment
    {
        $owner = User::factory()->create();
        $commenter = User::factory()->create();

        $profile = Profile::where('user_id', $owner->id)->firstOrFail();

        return ProfileComment::create([
            'profile_id' => $profile->id,
            'user_id' => $commenter->id,
            'comment' => 'Test review comment '.uniqid(),
        ]);
    }
}
