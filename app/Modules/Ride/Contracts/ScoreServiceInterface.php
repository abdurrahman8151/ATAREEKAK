<?php

namespace App\Modules\Ride\Contracts;

use App\Models\User;
use App\Modules\Ride\Models\UserScore;

/**
 * Port over the score READ surface (batch 233), plus the one lifecycle write the kernel has to make.
 *
 * IDENTITY and CONSOLE display a user's score; RIDE owns the score ledger. Porting this is what
 * lets `ProfileController` and `StaffUserProfileService` read a score without naming
 * `App\Modules\Ride\Services\ScoreService`. The general write paths (score deltas, action
 * application) are deliberately NOT on the port - those are RIDE-internal, and widening this to
 * mutations would invite money-adjacent cross-context writes.
 *
 * `initializeScore` is the single exception, and it is here for a structural reason: the kernel
 * `App\Observers\UserObserver` runs on account creation and is the one kernel site that must give a
 * brand-new user their starting score row. Stage 1 phase 2 moved `ScoreService` into the Ride module,
 * the boundary rule forbids the kernel naming a module implementation, and the observer is not an
 * entrypoint, so this method has to be on the port. It is not a general write: it takes no amount, it
 * is safe to call twice, and it delegates to the single creation path the ledger already uses.
 */
interface ScoreServiceInterface
{
    public function getScore(User $user): UserScore;

    public function initializeScore(User $user): UserScore;
}
