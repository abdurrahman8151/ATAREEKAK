<?php

namespace App\Modules\Identity\Services;

use App\Models\ProfileComment;
use App\Modules\Identity\Contracts\ProfileCommentDirectory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Stage 2 C2: `profile_comments` lives in Identity, so the staff moderation screen's page and delete run
 * here. The filters, eager loads and ordering are a line-for-line copy of the Console service's query -
 * same statements, same rows, same pagination - and the items are flattened to the fields the response
 * prints so no model crosses the boundary.
 */
final class ProfileCommentDirectoryService implements ProfileCommentDirectory
{
    public function page(
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
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (ProfileComment $comment) => [
                'id' => $comment->id,
                'comment' => $comment->comment,
                'created_at' => $comment->created_at,
                'commenter' => [
                    'id' => $comment->commenter?->id,
                    'first_name' => $comment->commenter?->first_name,
                    'last_name' => $comment->commenter?->last_name,
                ],
                'recipient' => [
                    'id' => $comment->profile?->user?->id,
                    'first_name' => $comment->profile?->user?->first_name,
                    'last_name' => $comment->profile?->user?->last_name,
                ],
            ]);
    }

    public function deleteOrFail(int $commentId): void
    {
        ProfileComment::findOrFail($commentId)->delete();
    }
}
