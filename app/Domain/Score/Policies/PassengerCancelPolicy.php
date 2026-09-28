<?php

namespace App\Domain\Score\Policies;

use App\Domain\Score\ScoreResult;
use App\Enums\ScoreAction;
use App\Models\UserScore;

/**
 * Passenger Cancel Policy
 *
 * Scoring grid (passenger, cancel seat(s)):
 *
 *  Elapsed     | normal          | high cancel rate
 *  0–30 %      |   0 pts         |    −10 pts
 *  30–50 %     |  −5 pts         |    −10 pts
 *  50–100 %    | −10 pts         |    −10 pts
 *
 * High cancel rate override activates when:
 *   - total_cancellations >= MIN_CANCELLATIONS (3), AND
 *   - cancel_rate > HIGH_RATE_THRESHOLD (50 %)
 *
 * The cancellation count gate prevents penalising new passengers on their
 * first cancellations before a meaningful rate can be established.
 * Base tier penalties (−5, −10) always apply even without the override.
 */
final class PassengerCancelPolicy implements ScorePolicyInterface
{
    private const HIGH_RATE_THRESHOLD = 50.0; // > 50 % triggers the penalty tier

    private const MIN_CANCELLATIONS = 3;    // override only kicks in after this many cancellations

    private const BASE_POINTS = [
        'passenger_cancel_early' => 0,
        'passenger_cancel_mid' => -5,
        'passenger_cancel_late' => -10,
    ];

    private const HIGH_RATE_POINTS = -10;

    public function supports(ScoreAction $action): bool
    {
        return in_array($action, [
            ScoreAction::PASSENGER_CANCEL_EARLY,
            ScoreAction::PASSENGER_CANCEL_MID,
            ScoreAction::PASSENGER_CANCEL_LATE,
        ]);
    }

    public function calculate(ScoreAction $action, UserScore $userScore, array $context = []): ScoreResult
    {
        $highRate = $userScore->total_cancellations >= self::MIN_CANCELLATIONS
            && $userScore->cancel_rate > self::HIGH_RATE_THRESHOLD;

        if ($highRate) {
            return ScoreResult::of(
                points: self::HIGH_RATE_POINTS,
                action: $action,
                reason: "Passenger cancelled ({$action->label()}) – high cancel rate penalty applied",
                highCancelRateApplied: true,
            );
        }

        $points = self::BASE_POINTS[$action->value];

        return ScoreResult::of(
            points: $points,
            action: $action,
            reason: "Passenger cancelled ({$action->label()})".($points === 0 ? ' – no penalty' : ''),
        );
    }
}
