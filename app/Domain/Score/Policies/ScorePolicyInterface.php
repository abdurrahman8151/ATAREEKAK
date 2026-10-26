<?php

namespace App\Domain\Score\Policies;

use App\Domain\Score\ScoreResult;
use App\DTOs\Score\ScoreSnapshot;
use App\Enums\ScoreAction;

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
     * @param  ScoreSnapshot  $snapshot  Current score stats (used for cancel-rate check)
     * @param  array  $context  Additional data the policy may need
     *                          (e.g. ['elapsed_pct' => 35.0])
     */
    public function calculate(ScoreAction $action, ScoreSnapshot $snapshot, array $context = []): ScoreResult;
}
