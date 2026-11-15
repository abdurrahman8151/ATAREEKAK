<?php

namespace App\Modules\Console\Services;

use App\Modules\Identity\Contracts\ProfileCommentDirectory;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * ReviewModerationService
 *
 * UC-ADM-03: Support agents browse user-to-user comments and
 * delete any that violate platform policies.
 *
 * Stage 2 C2: `profile_comments` is Identity's table, so the filtered page and the delete live behind
 * `ProfileCommentDirectory`. This service keeps the HTTP-facing shape: the pinning of the response keys
 * and the name join are still Console's.
 */
final class ReviewModerationService
{
    public function __construct(
        private readonly ProfileCommentDirectory $comments,
    ) {}

    /**
     * Paginated list of all comments with optional filters.
     *
     * @param  int|null  $userId  Filter by commenter OR recipient user ID
     * @param  string|null  $search  Full-text search inside comment body
     * @param  string|null  $date  'last_7_days' | 'last_30_days'
     */
    public function getComments(
        ?int $userId = null,
        ?string $search = null,
        ?string $date = null,
        int $perPage = 15,
        int $page = 1,
    ): LengthAwarePaginator {
        return $this->comments->page($userId, $search, $date, $perPage, $page);
    }

    /**
     * Delete a comment by ID (policy violation).
     *
     * @throws ModelNotFoundException
     */
    public function deleteComment(int $commentId): void
    {
        $this->comments->deleteOrFail($commentId);
    }

    /**
     * Shape a single comment for the API response.
     *
     * @param  array{id: int, comment: string|null, created_at: Carbon, commenter: array{id: int|null, first_name: string|null, last_name: string|null}, recipient: array{id: int|null, first_name: string|null, last_name: string|null}}  $comment
     * @return array<string, mixed>
     */
    public function format(array $comment): array
    {
        $commenter = $comment['commenter'];
        $recipient = $comment['recipient'];

        return [
            'id' => $comment['id'],
            'comment' => $comment['comment'],
            'commenter' => [
                'id' => $commenter['id'],
                'name' => trim(($commenter['first_name'] ?? '').' '.($commenter['last_name'] ?? '')),
            ],
            'recipient' => [
                'id' => $recipient['id'],
                'name' => trim(($recipient['first_name'] ?? '').' '.($recipient['last_name'] ?? '')),
            ],
            'created_at' => $comment['created_at']->toIso8601String(),
        ];
    }
}
