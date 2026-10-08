<?php

namespace App\Services\Staff;

use App\Models\ProfileComment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * ReviewModerationService
 *
 * UC-ADM-03: Support agents browse user-to-user comments and
 * delete any that violate platform policies.
 */
final class ReviewModerationService
{
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
        $query = ProfileComment::with([
            'commenter:id,first_name,last_name',
            'profile:id,user_id',
            'profile.user:id,first_name,last_name',
        ]);

        if ($userId) {
            $query->where(function ($q) use ($userId) {
                // Either the author or the recipient matches
                $q->where('user_id', $userId)
                    ->orWhereHas('profile', fn ($p) => $p->where('user_id', $userId));
            });
        }

        if ($search) {
            $query->where('comment', 'like', "%{$search}%");
        }

        if ($date) {
            $cutoff = match ($date) {
                'last_7_days' => now()->subDays(7),
                'last_30_days' => now()->subDays(30),
                default => null,
            };
            if ($cutoff) {
                $query->where('created_at', '>=', $cutoff);
            }
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Delete a comment by ID (policy violation).
     *
     * @throws ModelNotFoundException
     */
    public function deleteComment(int $commentId): void
    {
        ProfileComment::findOrFail($commentId)->delete();
    }

    /** Shape a single comment for the API response. */
    public function format(ProfileComment $comment): array
    {
        // R2 sec 121: this method was only safe when its caller happened to eager-load. `getComments`
        // does (`:31-35`), but `format()` is public, and any caller passing a bare model - as the
        // tests do - lazy-loaded `commenter` and `profile.user` per row. Under
        // Model::preventLazyLoading() that is a hard error; in production it is an N+1, two extra
        // queries for every comment on the page. `loadMissing` keeps the eager-load win on the list
        // path (nothing is loaded, they are already there) and makes the single-model path correct
        // on its own.
        $comment->loadMissing([
            'commenter:id,first_name,last_name',
            'profile:id,user_id',
            'profile.user:id,first_name,last_name',
        ]);

        $commenter = $comment->commenter;
        $recipient = $comment->profile?->user;

        return [
            'id' => $comment->id,
            'comment' => $comment->comment,
            'commenter' => [
                'id' => $commenter?->id,
                'name' => trim(($commenter?->first_name ?? '').' '.($commenter?->last_name ?? '')),
            ],
            'recipient' => [
                'id' => $recipient?->id,
                'name' => trim(($recipient?->first_name ?? '').' '.($recipient?->last_name ?? '')),
            ],
            'created_at' => $comment->created_at->toIso8601String(),
        ];
    }
}
