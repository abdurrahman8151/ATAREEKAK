<?php

namespace App\Domain\Score\Policies;

use App\Domain\Score\ScoreResult;
use App\Enums\ScoreAction;
use App\Models\UserScore;

/**
 * Driver Commitment Streak Policy
 *
 * Rewards a driver for going without a cancellation for a sustained period:
 *
 *   7 days  → +2 pts
 *   14 days → +4 pts
 *   30 days → +6 pts
 *
 * ScoreService::evaluateDriverCommitmentStreak() determines which milestone(s)
 * were newly reached and is responsible for never re-awarding the same
 * milestone twice within one streak — this policy only prices each milestone.
 */
final class DriverCommitmentStreakPolicy implements ScorePolicyInterface
{
    private const POINTS = [
        'driver_commitment_streak_7'  => 2,
        'driver_commitment_streak_14' => 4,
        'driver_commitment_streak_30' => 6,
    ];

    public function supports(ScoreAction $action): bool
    {
        return in_array($action, [
            ScoreAction::DRIVER_COMMITMENT_STREAK_7,
            ScoreAction::DRIVER_COMMITMENT_STREAK_14,
            ScoreAction::DRIVER_COMMITMENT_STREAK_30,
        ], true);
    }

    public function calculate(ScoreAction $action, UserScore $userScore, array $context = []): ScoreResult
    {
        return ScoreResult::of(
            points: self::POINTS[$action->value],
            action: $action,
            reason: "Driver maintained a cancellation-free streak ({$action->label()})",
        );
    }
}
