<?php

namespace App\Modules\Ride\Domain\Policies;

use App\DTOs\Score\ScoreSnapshot;
use App\Enums\ScoreAction;
use App\Modules\Ride\Domain\ScoreResult;

/**
 * Passenger No-Show Policy
 *
 * Fixed −15 pts regardless of elapsed time or cancel rate.
 */
final class PassengerNoShowPolicy implements ScorePolicyInterface
{
    private const POINTS = -15;

    public function supports(ScoreAction $action): bool
    {
        return $action === ScoreAction::PASSENGER_NO_SHOW;
    }

    public function calculate(ScoreAction $action, ScoreSnapshot $snapshot, array $context = []): ScoreResult
    {
        return ScoreResult::of(
            points: self::POINTS,
            action: $action,
            reason: 'Passenger absent at pickup — no-show penalty applied',
        );
    }
}
