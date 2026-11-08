<?php

namespace App\Modules\Identity\Contracts;

/**
 * Stage 2 C1/C2: the write side of `user_ratings`, for the one caller outside Identity that creates a row.
 *
 * `UserObserver` seeds a base rating when a user is created. That is a write into Identity's table from the
 * kernel, so it goes through a contract with one verb rather than through the model - the observer names the
 * port and never the row.
 */
interface UserRatingRecorder
{
    /**
     * Seed the platform's base rating for a newly created user.
     *
     * `rater_id` is NULL, which is how the schema spells "platform-assigned": there is no admin user behind
     * this row and creating one as an actor was the old design.
     */
    public function seedPlatformBaseRating(int $userId): void;
}
