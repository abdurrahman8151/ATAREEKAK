<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Contracts\UserRatingReader;
use App\Modules\Identity\DTOs\UserRatingStats;
use App\Modules\Identity\Models\UserRating;

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

    /**
     * Verbatim the query the dashboard card ran, including its `whereHas` on the verified-driver flag: the
     * relation walks two Identity tables, so it belongs inside the module rather than in the caller.
     */
    public function averageAcrossVerifiedDrivers(): mixed
    {
        return UserRating::whereHas(
            'ratedUser',
            fn ($q) => $q->where('is_verified_driver', true)
        )->avg('rating');
    }

    /**
     * Stage 2 C2: ONE grouped query for a page's worth of users. The value is the raw AVG, exactly what
     * `withAvg('receivedRatings as avg_rating', 'rating')` produced (the callers round), and a user with no
     * ratings is absent rather than zero - so a caller's `?? 0` (resources) and `?? null` (the driver list's
     * "no rating yet") both keep the meaning they had.
     */
    public function averageForUsers(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === []) {
            return [];
        }

        return UserRating::query()
            ->whereIn('rated_user_id', $ids)
            ->groupBy('rated_user_id')
            ->selectRaw('rated_user_id, AVG(rating) as average')
            ->pluck('average', 'rated_user_id')
            ->map(fn ($average) => (float) $average)
            ->all();
    }

    /**
     * Stage 2 C2: the rows themselves, keyed by rated user id, serialized exactly as the model's
     * `toArray()` did when the relation was eager-loaded - so a raw-model response keeps the same nested
     * `received_ratings` key and values (including the decimal-as-string rating).
     */
    public function rowsForUsers(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === []) {
            return [];
        }

        return UserRating::query()
            ->whereIn('rated_user_id', $ids)
            ->get()
            ->groupBy('rated_user_id')
            ->map(fn ($rows) => $rows->map(fn (UserRating $row) => $row->toArray())->values()->all())
            ->all();
    }
}
