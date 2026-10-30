<?php

namespace App\DTOs\Score;

/**
 * The one score response shape (batch 233).
 *
 * `ScoreController::formatScore()` was a `public static` on a RIDE controller, and IDENTITY's
 * `ProfileController` called it - the last IDENTITY -> RIDE edge after the score and ride-stats
 * ports. The body moved here, verbatim, into the shared kernel: `ScoreController::formatScore()`
 * is kept as a one-line delegate (its other caller, `RideLifecycleHandler`, and the route-integrity
 * ratchet both still name it), and `ProfileController` calls this class directly.
 *
 * The parameter stays untyped, exactly as it was, so no caller can start throwing a TypeError.
 */
final class ScoreFormatter
{
    /** @return array<string, mixed> */
    public static function format($userScore): array
    {
        return [
            'score' => $userScore->score,
            'tier' => $userScore->tier,
            'cancel_rate' => round($userScore->cancel_rate, 2),
            'total_rides' => $userScore->total_rides,
            'total_cancellations' => $userScore->total_cancellations,
            'can_create_rides' => $userScore->score >= 50,
            'can_book_rides' => $userScore->score >= 40,
        ];
    }
}
