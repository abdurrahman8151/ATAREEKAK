<?php

namespace App\Observers;

use App\Models\User;
use App\Modules\Identity\Contracts\ProfileRepositoryInterface;
use App\Modules\Identity\Contracts\UserRatingRecorder;
use App\Modules\Ride\Contracts\ScoreServiceInterface;

class UserObserver
{
    public function __construct(
        private ProfileRepositoryInterface $profileRepo,
        private ScoreServiceInterface $scoreService,
        private UserRatingRecorder $ratings,
    ) {}

    public function created(User $user): void
    {
        // Auto-create the profile row for this user
        $this->profileRepo->createFromUser($user);

        // Initialize trust score at 70 (Silver tier) per SRS v5
        $this->scoreService->initializeScore($user);

        // Seed the platform's base rating (rater_id NULL = platform-assigned).
        // Stage 2 C1/C2: `user_ratings` is Identity's table, so the write goes through its contract; this
        // observer names the port and never the row, and no system_admin actor is involved.
        $this->ratings->seedPlatformBaseRating((int) $user->id);
    }
}
