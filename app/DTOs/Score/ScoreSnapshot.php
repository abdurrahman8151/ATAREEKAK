<?php

namespace App\DTOs\Score;

use App\Models\UserScore;

/**
 * The snapshot a score policy is evaluated against: the two counters the cancel-rate grid reads,
 * lifted off the UserScore row.
 *
 * Introduced in batch 227 so `app/Modules/Ride/Domain/Policies` holds no Eloquent reference at all. Both
 * values are read-only copies of what the policies compared before:
 *   - `totalCancellations` is `UserScore::$total_cancellations` (cast to integer on the model),
 *   - `cancelRate` is `UserScore::getCancelRateAttribute()`, which always returns a float (0.0 when
 *     the user has neither ridden nor cancelled, so a fresh row can never clear the 50% threshold).
 */
final class ScoreSnapshot
{
    public function __construct(
        public readonly int $totalCancellations,
        public readonly float $cancelRate,
    ) {}

    public static function fromUserScore(UserScore $userScore): self
    {
        return new self(
            totalCancellations: (int) $userScore->total_cancellations,
            cancelRate: (float) $userScore->cancel_rate,
        );
    }
}
