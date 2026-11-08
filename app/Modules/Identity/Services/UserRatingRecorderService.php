<?php

namespace App\Modules\Identity\Services;

use App\Models\UserRating;
use App\Modules\Identity\Contracts\UserRatingRecorder;

/**
 * Stage 2 C1/C2: the write side of `user_ratings`.
 *
 * `firstOrCreate` is kept, not `create`: a user whose row already exists (a replayed event, a seeder that ran
 * twice) must not gain a second platform rating, and the uniqueness here is the pair (rater_id, rated_user_id)
 * with rater_id NULL.
 */
final class UserRatingRecorderService implements UserRatingRecorder
{
    private const PLATFORM_BASE_RATING = 3.0;

    public function seedPlatformBaseRating(int $userId): void
    {
        UserRating::firstOrCreate(
            [
                'rater_id' => null,
                'rated_user_id' => $userId,
            ],
            [
                'rating' => self::PLATFORM_BASE_RATING,
            ]
        );
    }
}
