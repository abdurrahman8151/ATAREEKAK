<?php

namespace App\Modules\Ride\Domain;

/**
 * The score scale, in the shared kernel (batch 233).
 *
 * These three numbers were public constants on `ScoreService` (RIDE) while `ScoreLedger` (also
 * RIDE) read them - a class cycle inside one context. Moving the numbers to the kernel lets the
 * ledger clamp and initialise without naming `ScoreService`, and leaves the three
 * `ScoreService::START_SCORE` / `MIN_SCORE` / `MAX_SCORE` constants in place as aliases of these,
 * so every existing reference and test keeps working unchanged.
 *
 * Values are the owner's policy as recorded in `ScoreService`: start 70, clamp [0, 100].
 */
final class ScoreBounds
{
    public const START_SCORE = 70;

    public const MIN_SCORE = 0;

    public const MAX_SCORE = 100;
}
