<?php

namespace App\Domain\Score\Policies;

use App\Domain\Score\ScoreResult;
use App\DTOs\Score\ScoreSnapshot;
use App\Enums\ScoreAction;

/**
 * Driver Cancel Seat Policy
 *
 * Fixed −5 pts regardless of elapsed time or cancel rate.
 */
final class DriverCancelSeatPolicy implements ScorePolicyInterface
{
    private const POINTS = -5;

    public function supports(ScoreAction $action): bool
    {
        return $action === ScoreAction::DRIVER_CANCEL_SEAT;
    }

    public function calculate(ScoreAction $action, ScoreSnapshot $snapshot, array $context = []): ScoreResult
    {
        return ScoreResult::of(
            points: self::POINTS,
            action: $action,
            reason: 'Driver removed a passenger seat — fixed penalty applied',
        );
    }
}
