<?php

namespace App\DTOs\Score;

/**
 * The snapshot a score policy is evaluated against: the two counters the cancel-rate grid reads.
 *
 * Introduced in batch 227 so `app/Modules/Ride/Domain/Policies` holds no Eloquent reference at all. Both
 * values are read-only copies of what the policies compared before:
 *   - `totalCancellations` is the user's `total_cancellations` counter (integer),
 *   - `cancelRate` is the computed cancel-rate accessor, which always returns a float (0.0 when the user
 *     has neither ridden nor cancelled, so a fresh row can never clear the 50% threshold).
 *
 * STAGE 2 C1: `fromUserScore(UserScore $userScore)` is GONE. It took the score row as a parameter type,
 * and that row is RIDE's model while this DTO lives in the KERNEL - so once the model moved, the factory
 * would have been a kernel -> module implementation reference, which `ModuleBoundaryTest` forbids at a
 * hard zero baseline. `ScoreLedger::apply()` already holds the row and reads these two values itself, so
 * the typed factory was only a second place that had to know the row's class. Same two numbers, from the
 * same attributes, with no new query.
 *
 * @see the score ledger's apply() method for the single construction site (Ride module).
 */
final class ScoreSnapshot
{
    public function __construct(
        public readonly int $totalCancellations,
        public readonly float $cancelRate,
    ) {}
}
