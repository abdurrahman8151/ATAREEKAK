<?php

namespace App\Observers;

use App\Interfaces\ProfileRepositoryInterface;
use App\Models\User;
use App\Models\UserRating;
use App\Services\Score\ScoreService;

class UserObserver
{
    public function __construct(
        private ProfileRepositoryInterface $profileRepo,
        private ScoreService $scoreService,
    ) {}

    public function created(User $user): void
    {
        // Auto-create the profile row for this user
        $this->profileRepo->createFromUser($user);

        // Initialize trust score at 70 (Silver tier) per SRS v5
        $this->scoreService->initializeScore($user);

        // Seed a 3.0 base rating with rater_id = NULL (platform-assigned).
        // No longer depends on a system_admin User row.
        UserRating::firstOrCreate(
            [
                'rater_id' => null,
                'rated_user_id' => $user->id,
            ],
            [
                'rating' => 3.0,
            ]
        );
    }
}
