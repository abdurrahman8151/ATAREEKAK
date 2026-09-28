<?php

namespace Staff;

use App\Models\ProfileComment;
use App\Models\User;
use App\Services\Staff\ReviewModerationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ReviewModerationServiceTest — Integration tests for ReviewModerationService.
 *
 * COVERS:
 *   getComments()   — paginated ProfileComment list with userId / search / date filters
 *   format()        — shapes a ProfileComment into the API response array
 *   deleteComment() — hard-deletes by ID; throws ModelNotFoundException if missing
 */
class ReviewModerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReviewModerationService $service;

    private User $commenter;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ReviewModerationService::class);
        $this->commenter = User::factory()->create();
        $this->recipient = User::factory()->create();
    }

    // ─── getComments ──────────────────────────────────────────────────────────

    public function test_get_comments_returns_paginator(): void
    {
        $this->assertInstanceOf(
            LengthAwarePaginator::class,
            $this->service->getComments(null, null, null, 15, 1)
        );
    }

    public function test_get_comments_returns_zero_total_when_no_comments_exist(): void
    {
        $this->assertEquals(0, $this->service->getComments(null, null, null, 15, 1)->total());
    }

    public function test_get_comments_returns_all_comments(): void
    {
        $this->makeComment('Great driver!');
        $this->makeComment('Very punctual.');

        $this->assertEquals(2, $this->service->getComments(null, null, null, 15, 1)->total());
    }

    public function test_get_comments_filters_by_user_id(): void
    {
        $other = User::factory()->create();

        $this->makeComment('On recipient', commenter: $this->commenter, recipient: $this->recipient);
        $this->makeComment('On other', commenter: $this->commenter, recipient: $other);

        $result = $this->service->getComments(
            userId: $this->recipient->id, search: null, date: null, perPage: 15, page: 1
        );

        $this->assertEquals(1, $result->total());
    }

    public function test_get_comments_searches_by_keyword(): void
    {
        $this->makeComment('Excellent driving skills');
        $this->makeComment('Completely different text');

        $result = $this->service->getComments(null, 'Excellent', null, 15, 1);

        $this->assertEquals(1, $result->total());
    }

    public function test_get_comments_does_not_match_unrelated_keyword(): void
    {
        $this->makeComment('Great service');

        $result = $this->service->getComments(null, 'terrible', null, 15, 1);

        $this->assertEquals(0, $result->total());
    }

    public function test_get_comments_respects_per_page(): void
    {
        $this->makeComment('C1');
        $this->makeComment('C2');
        $this->makeComment('C3');

        $result = $this->service->getComments(null, null, null, 2, 1);

        $this->assertCount(2, $result->items());
        $this->assertEquals(3, $result->total());
    }

    public function test_get_comments_respects_page_number(): void
    {
        $this->makeComment('C1');
        $this->makeComment('C2');
        $this->makeComment('C3');

        $result = $this->service->getComments(null, null, null, 2, 2);

        $this->assertCount(1, $result->items());
        $this->assertEquals(2, $result->currentPage());
    }

    public function test_get_comments_filters_last_7_days(): void
    {
        $recent = $this->makeComment('Recent');
        $old = $this->makeComment('Old');
        DB::table('profile_comments')
            ->where('id', $old->id)
            ->update(['created_at' => now()->subDays(30)]);

        $result = $this->service->getComments(null, null, 'last_7_days', 15, 1);

        $this->assertEquals(1, $result->total());
        $this->assertEquals($recent->id, $result->items()[0]->id);
    }

    // ─── format ───────────────────────────────────────────────────────────────

    public function test_format_returns_array_with_required_keys(): void
    {
        $comment = $this->makeComment('A test comment');
        $formatted = $this->service->format($comment->load('commenter'));

        foreach (['id', 'comment', 'commenter', 'created_at'] as $key) {
            $this->assertArrayHasKey($key, $formatted, "Missing key: {$key}");
        }
    }

    public function test_format_includes_correct_comment_text(): void
    {
        $comment = $this->makeComment('Specific text here');
        $formatted = $this->service->format($comment->load('commenter'));

        $this->assertEquals('Specific text here', $formatted['comment']);
    }

    public function test_format_includes_commenter_id_and_name(): void
    {
        $comment = $this->makeComment('Test');
        $formatted = $this->service->format($comment->load('commenter'));

        $this->assertArrayHasKey('id', $formatted['commenter']);
        $this->assertArrayHasKey('name', $formatted['commenter']);
        $this->assertEquals($this->commenter->id, $formatted['commenter']['id']);
    }

    public function test_format_id_matches_comment_id(): void
    {
        $comment = $this->makeComment('Test');
        $formatted = $this->service->format($comment->load('commenter'));

        $this->assertEquals($comment->id, $formatted['id']);
    }

    public function test_format_created_at_is_present_and_not_null(): void
    {
        $formatted = $this->service->format($this->makeComment('Test')->load('commenter'));

        $this->assertNotNull($formatted['created_at']);
    }

    // ─── deleteComment ────────────────────────────────────────────────────────

    public function test_delete_comment_removes_it_from_database(): void
    {
        $comment = $this->makeComment('Delete me');

        $this->service->deleteComment($comment->id);

        $this->assertDatabaseMissing('profile_comments', ['id' => $comment->id]);
    }

    public function test_delete_comment_throws_for_nonexistent_id(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->deleteComment(999999);
    }

    public function test_delete_comment_does_not_affect_other_comments(): void
    {
        $toDelete = $this->makeComment('Delete me');
        $toKeep = $this->makeComment('Keep me');

        $this->service->deleteComment($toDelete->id);

        $this->assertDatabaseHas('profile_comments', ['id' => $toKeep->id]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeComment(
        string $text,
        ?User $commenter = null,
        ?User $recipient = null
    ): ProfileComment {
        $commenter = $commenter ?? $this->commenter;
        $recipient = $recipient ?? $this->recipient;

        $profile = $recipient->profile
            ?? $recipient->profile()->firstOrCreate(['full_name' => $recipient->first_name]);

        return ProfileComment::create([
            'profile_id' => $profile->id,
            'user_id' => $commenter->id,
            'comment' => $text,
        ]);
    }
}
