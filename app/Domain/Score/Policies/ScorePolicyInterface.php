<?php

namespace App\Domain\Score\Policies;

use App\Domain\Score\ScoreResult;
use App\Enums\ScoreAction;
use App\Models\UserScore;

/**
 * Score Policy Interface
 *
 * OCP: open for new actions, closed for modification.
 * Each policy is responsible for ONE action family only (SRP).
 */
interface ScorePolicyInterface
{
    /**
     * Whether this policy handles the given action.
     */
    public function supports(ScoreAction $action): bool;

    /**
     * Calculate the ScoreResult for this action.
     *
     * @param  UserScore  $userScore  Current score stats (used for cancel-rate check)
     * @param  array  $context  Additional data the policy may need
     *                          (e.g. ['elapsed_pct' => 35.0])
     */
    public function calculate(ScoreAction $action, UserScore $userScore, array $context = []): ScoreResult;
}
