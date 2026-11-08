<?php

namespace App\Modules\Identity\Services;

use App\Models\UserRating;
use App\Modules\Identity\Contracts\UserRatingReader;
use App\Modules\Identity\DTOs\UserRatingStats;

/**
 * Stage 2 C1/C2: the one place that aggregates `user_ratings` for a caller outside Identity.
 *
 * The SQL is the query three Console services already ran, verbatim, including the `ROUND(..., 2)`. Two of
 * them used `ROUND` and one did not; they agree in the response because every consumer rounds or casts before
 * emitting, so unifying on `ROUND` changes no payload - and the snapshot corpus confirms it.
 */
final class UserRatingReaderService implements UserRatingReader
{
    public function statsFor(int $userId): UserRatingStats
    {
        $row = UserRating::where('rated_user_id', $userId)
            ->selectRaw('COUNT(*) as total, ROUND(AVG(rating), 2) as average')
            ->first();

        if ($row === null) {
            return UserRatingStats::empty();
        }

        return new UserRatingStats(total: $row->total, average: $row->average);
    }
}
