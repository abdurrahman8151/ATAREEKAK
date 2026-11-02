<?php

namespace App\Modules\Ride\Domain\Policies;

use App\DTOs\Score\ScoreSnapshot;
use App\Enums\ScoreAction;
use App\Modules\Ride\Domain\ScoreResult;

/**
 * Ride Completion Policy
 *
 * Awards +10 points to every party on a successfully completed ride.
 * Not affected by cancel rate.
 */
final class RideCompletionPolicy implements ScorePolicyInterface
{
    private const POINTS = 10;

    public function supports(ScoreAction $action): bool
    {
        return $action === ScoreAction::RIDE_COMPLETED;
    }

    public function calculate(ScoreAction $action, ScoreSnapshot $snapshot, array $context = []): ScoreResult
    {
        return ScoreResult::of(
            points: self::POINTS,
            action: $action,
            reason: 'Ride completed successfully — all parties rewarded',
        );
    }
}
