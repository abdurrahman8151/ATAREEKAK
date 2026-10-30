<?php

namespace App\Interfaces;

use App\Models\User;
use App\Models\UserScore;

/**
 * Port over the score READ surface (batch 233).
 *
 * IDENTITY and CONSOLE display a user's score; RIDE owns the score ledger. Porting this is what
 * lets `ProfileController` and `StaffUserProfileService` read a score without naming
 * `App\Services\Score\ScoreService`. The write paths are deliberately NOT on the port - those are
 * RIDE-internal, and widening this to mutations would invite money-adjacent cross-context writes.
 */
interface ScoreServiceInterface
{
    public function getScore(User $user): UserScore;
}
