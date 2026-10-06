<?php

namespace App\Services\Score;

use App\Domain\Score\ScorePolicyFactory;
use App\Enums\ScoreAction;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\ScoreTransaction;
use App\Models\User;
use App\Models\UserScore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONE path by which a user's score and counters change.
 *
 * RV-11 (`R2 sec 73`). `ScoreService` had four separate places that wrote a score, and they did not
 * agree:
 *
 *   1. `applyAction()`           - clamped [MIN, MAX], incremented rides for RIDE_COMPLETED, wrote
 *                                  the ScoreTransaction. The good one.
 *   2. `recordPassengerNoShow()` - `applyDelta()` then `incrementNoShows()`, and it wrote its own
 *                                  ScoreTransaction with `high_cancel_rate_applied` hard-coded false.
 *   3. `recordDriverNoShow()`    - the same shape again, duplicating (2).
 *   4. `UserScore::applyDelta()` / `incrementNoShows()` / `incrementCancellations()` - the model
 *                                  methods the above call, each saving independently.
 *
 * The duplication was not cosmetic. Paths (2) and (3) each saved the row TWICE - once for the score
 * delta, once for the counter - so a failure in between left the score moved and the counter not.
 * They also wrote their own `ScoreTransaction` rows with `high_cancel_rate_applied` hard-coded to
 * `false`.
 *
 * **ON THAT LAST POINT, A CLAIM I HAD TO WITHDRAW.** The obvious reading is that `false` was a lie,
 * denying a gate that had actually fired. It was not. Only `DriverCancelRidePolicy` and
 * `PassengerCancelPolicy` ever set that flag; `PassengerNoShowPolicy` and `DriverNoShowPolicy` return
 * the `ScoreResult` default, so `false` was the TRUTHFUL value for a no-show. A test written on the
 * wrong premise failed until the TEST was corrected, not the code.
 *
 * The hard-coding was still worth removing, for a narrower and real reason: it made the audit row a
 * CONSTANT rather than a mirror of the policy. If a no-show policy ever grows gate logic, the old
 * code would keep writing `false` while the score moved. Every row now carries the policy's own
 * value, so the two cannot diverge.
 *
 * `apply()` is the single write path: run the policy, clamp ONCE, apply the counters the action
 * implies, write the audit row. `ScoreService` keeps its public API - it is what ride completion,
 * cancellation, no-show and rating call - but its internals route through here, so a behaviour change
 * to scoring happens in one file.
 *
 * @see ScoreService for the public entry points.
 */
final class ScoreLedger
{
    /**
     * Actions that mean "this user completed a ride", and so increment `total_rides`.
     *
     * `RIDE_COMPLETED` is the only one today. It is listed rather than compared with `===` so that
     * adding a second such action is a visible edit here - the RIDE_COMPLETED-only special case was
     * itself the RV-11 double-count defect (`R2 sec 67`), and it must not be implicit.
     */
    private const RIDES_INCREMENTING = [
        ScoreAction::RIDE_COMPLETED,
    ];

    /**
     * Actions that mean "this user failed to show", and so increment `total_no_shows`.
     *
     * Both kinds count. An E-PAY passenger no-show still increments the counter even though their
     * SCORE change is overridden to zero, because the counter records conduct, not money.
     */
    private const NO_SHOWS_INCREMENTING = [
        ScoreAction::PASSENGER_NO_SHOW,
        ScoreAction::DRIVER_NO_SHOW,
    ];

    public function __construct(
        private readonly ScorePolicyFactory $policyFactory,
    ) {}

    /**
     * Apply a score action: run the policy, clamp once, update counters, record the audit row.
     *
     * @param  User  $user  Whose score changes.
     * @param  ScoreAction  $action  What happened.
     * @param  object|null  $reference  The model this refers to, recorded on the audit row.
     * @param  array<string, mixed>  $context  Passed to the policy (e.g. `elapsed_pct`).
     * @param  int|null  $pointsOverride  Use instead of the policy's points. The E-PAY passenger
     *                                    no-show needs it: their wallet IS the penalty, so the
     *                                    score change is zero while the no-show still counts.
     * @param  string|null  $reasonOverride  Use instead of the policy's reason.
     * @param  array<string, mixed>  $metadata  Extra columns on the audit row. Left empty where it
     *                                          was empty before, so no stored row changes shape.
     * @return array{previous_score: int, new_score: int, points: int, high_cancel_rate_applied: bool}
     */
    public function apply(
        User $user,
        ScoreAction $action,
        ?object $reference = null,
        array $context = [],
        ?int $pointsOverride = null,
        ?string $reasonOverride = null,
        array $metadata = [],
    ): array {
        return DB::transaction(function () use ($user, $action, $reference, $context, $pointsOverride, $reasonOverride, $metadata) {
            // ONE creation path (R2 sec 73). `ScoreService` had four `firstOrCreate` calls with
            // subtly different attribute lists: three started at 70 and one at 100, and one of them
            // omitted `total_no_shows`.
            $userScore = $this->scoreRow($user);

            $policy = $this->policyFactory->make($action);
            $result = $policy->calculate($action, $userScore, $context);

            $points = $pointsOverride ?? $result->points;
            $reason = $reasonOverride ?? $result->reason;

            $previousScore = (int) $userScore->score;

            // ONE clamp, both ends. `applyDelta` clamped [0, 100] on the no-show paths while
            // `applyAction` carried its own clamp; a single clamp is the point of this class.
            $newScore = max(
                ScoreService::MIN_SCORE,
                min(ScoreService::MAX_SCORE, $previousScore + $points)
            );

            $userScore->score = $newScore;

            // Counters assigned, then ONE save. `applyDelta()` followed by `incrementNoShows()`
            // saved twice, so a failure in between left the score moved and the counter not.
            if (in_array($action, self::RIDES_INCREMENTING, true)) {
                $userScore->total_rides = (int) $userScore->total_rides + 1;
            }

            if (in_array($action, self::NO_SHOWS_INCREMENTING, true)) {
                $userScore->total_no_shows = (int) $userScore->total_no_shows + 1;
            }

            $userScore->save();

            // Reference resolution kept from `applyAction`: the passed model wins, otherwise fall
            // back to the context keys for callers that still use the old style.
            [$referenceType, $referenceId] = $this->resolveReference($reference, $context);

            ScoreTransaction::create([
                'user_id' => $user->id,
                'action' => $action->value,
                'points' => $points,
                'previous_score' => $previousScore,
                'new_score' => $newScore,
                'reason' => $reason,
                // The policy's OWN value, on every path - never a literal. The two no-show paths used
                // to hard-code it here. That was correct at the time (the no-show policies do not
                // apply the gate; only the two cancel policies do), but it made the row a constant
                // instead of a mirror of the policy.
                'high_cancel_rate_applied' => $result->highCancelRateApplied,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => $metadata !== [] ? $metadata : null,
            ]);

            Log::info('Score action applied', [
                'user_id' => $user->id,
                'action' => $action->value,
                'points' => $points,
                'previous_score' => $previousScore,
                'new_score' => $newScore,
                'high_cancel_rate_applied' => $result->highCancelRateApplied,
            ]);

            return [
                'previous_score' => $previousScore,
                'new_score' => $newScore,
                'points' => $points,
                'high_cancel_rate_applied' => $result->highCancelRateApplied,
            ];
        });
    }

    /**
     * Increment `total_cancellations` without a score change.
     *
     * Separate from `apply()` because a cancellation is not itself a score action: the score delta
     * is decided by the policy, while the counter is a fact about the record. It lives here so
     * every counter write still passes through one class.
     */
    public function recordCancellation(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->scoreRow($user)->increment('total_cancellations');
        });
    }

    /**
     * The single score-creation path: the row, or a fresh one at the pinned start score.
     *
     * Public because ScoreService::initializeScore() and ScoreService::getScore() both need to
     * return a row, and routing them through here is the point: they used to carry their own
     *
irstOrCreate calls with three different attribute lists - one starting at 100, one silently
     * omitting 	otal_no_shows. RV11ScoreLedgerTest guards that ScoreService has none of its own.
     */
    public function scoreRow(User $user): UserScore
    {
        return UserScore::firstOrCreate(
            ['user_id' => $user->id],
            [
                'score' => ScoreService::START_SCORE,
                'total_rides' => 0,
                'total_cancellations' => 0,
                'total_no_shows' => 0,
            ]
        );
    }

    /**
     * @return array{0: class-string|null, 1: int|null}
     */
    private function resolveReference(?object $reference, array $context): array
    {
        if ($reference !== null) {
            return [get_class($reference), $reference->id];
        }

        if (isset($context['booking_id'])) {
            return [Booking::class, (int) $context['booking_id']];
        }

        if (isset($context['ride_id'])) {
            return [Ride::class, (int) $context['ride_id']];
        }

        return [null, null];
    }
}
