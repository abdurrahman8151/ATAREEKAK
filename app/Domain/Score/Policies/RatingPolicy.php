<?php

namespace App\Domain\Score\Policies;

use App\Domain\Score\ScoreResult;
use App\Enums\ScoreAction;
use App\Models\UserScore;

/**
 * Rating Policy
 *
 *  +3 pts — every rating of 4 or 5 stars received.
 *  +2 pts — additional bonus every time 3 consecutive 5-star ratings land in a row.
 *
 * Streak bookkeeping (resetting/incrementing the consecutive counter) is done
 * by ScoreService::recordRating() before this policy is invoked — the policy
 * itself only knows how many points each of the two events is worth.
 */
final class RatingPolicy implements ScorePolicyInterface
{
    private const POSITIVE_RATING_POINTS = 3;
    private const FIVE_STAR_STREAK_POINTS = 2;

    public function supports(ScoreAction $action): bool
    {
        return in_array($action, [
            ScoreAction::RATING_POSITIVE,
            ScoreAction::RATING_FIVE_STAR_STREAK,
        ], true);
    }

    public function calculate(ScoreAction $action, UserScore $userScore, array $context = []): ScoreResult
    {
        return match ($action) {
            ScoreAction::RATING_POSITIVE => ScoreResult::of(
                points: self::POSITIVE_RATING_POINTS,
                action: $action,
                reason: 'Positive rating received (4 or 5 stars)',
            ),
            ScoreAction::RATING_FIVE_STAR_STREAK => ScoreResult::of(
                points: self::FIVE_STAR_STREAK_POINTS,
                action: $action,
                reason: '3 consecutive 5-star ratings — bonus awarded',
            ),
        };
    }
}
