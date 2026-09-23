<?php

namespace App\Services\Score;

use App\Domain\Score\ScorePolicyFactory;
use App\Domain\Score\ScoreResult;
use App\Enums\PaymentMethod;
use App\Enums\ScoreAction;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\ScoreTransaction;
use App\Models\User;
use App\Models\UserRating;
use App\Models\UserScore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ScoreService
{
    public function __construct(
        private readonly ScorePolicyFactory $policyFactory,
    ) {}

    // =========================================================================
    // INITIALIZATION (called by UserObserver on signup)
    // =========================================================================

    public function initializeScore(User $user): UserScore
    {
        return UserScore::firstOrCreate(
            ['user_id' => $user->id],
            ['score' => 70, 'total_rides' => 0, 'total_cancellations' => 0]
        );
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function recordRideCompleted(Ride $ride): void
    {
        DB::transaction(function () use ($ride) {
            $this->applyAction(
                user:      $ride->driver,
                action:    ScoreAction::RIDE_COMPLETED,
                reference: $ride,
                context:   [],
            );
            $this->incrementRides($ride->driver);

            $ride->bookings()
                ->where('status', 'completed')
                ->with('user')
                ->get()
                ->each(function ($booking) use ($ride) {
                    $this->applyAction(
                        user:      $booking->user,
                        action:    ScoreAction::RIDE_COMPLETED,
                        reference: $ride,
                        context:   [],
                    );
                    $this->incrementRides($booking->user);
                });
        });
    }

    /**
     * Award score points for a rating a driver just received.
     *
     *  +3 pts for any 4-or-5-star rating.
     *  +2 pts bonus every 3rd consecutive 5-star rating in a row (counter
     *  resets on any rating below 5 stars).
     */
    public function recordRating(User $ratedUser, UserRating $rating): void
    {
        DB::transaction(function () use ($ratedUser, $rating) {
            $userScore = $this->getOrCreateScore($ratedUser);

            if ((float) $rating->rating >= 4.0) {
                $this->applyAction(
                    user:      $ratedUser,
                    action:    ScoreAction::RATING_POSITIVE,
                    reference: $rating,
                );
            }

            if ((float) $rating->rating >= 5.0) {
                $userScore->incrementFiveStarStreak();
                $userScore->refresh();

                if ($userScore->consecutive_five_star_ratings >= 3) {
                    $this->applyAction(
                        user:      $ratedUser,
                        action:    ScoreAction::RATING_FIVE_STAR_STREAK,
                        reference: $rating,
                    );
                    $userScore->update(['consecutive_five_star_ratings' => 0]);
                }
            } else {
                $userScore->resetFiveStarStreak();
            }
        });
    }

    /**
     * Check whether a driver has newly crossed a 7/14/30-day cancellation-free
     * milestone and award the corresponding bonus. Intended to be called once
     * per driver per day by the score:driver-commitment-streaks command.
     *
     * Awards every milestone crossed since the last check (not just the
     * highest), so a gap in the scheduler still pays out each one in order.
     */
    public function evaluateDriverCommitmentStreak(User $driver): void
    {
        $userScore = $this->getOrCreateScore($driver);
        $anchor    = $userScore->last_driver_cancellation_at ?? $userScore->created_at;
        $daysClean = $anchor->diffInDays(now());

        $milestones = [
            7  => ScoreAction::DRIVER_COMMITMENT_STREAK_7,
            14 => ScoreAction::DRIVER_COMMITMENT_STREAK_14,
            30 => ScoreAction::DRIVER_COMMITMENT_STREAK_30,
        ];

        foreach ($milestones as $days => $action) {
            if ($daysClean >= $days && $userScore->last_streak_milestone_days < $days) {
                $this->applyAction(
                    user:      $driver,
                    action:    $action,
                    context:   ['days_clean' => $daysClean],
                );
                $userScore->update(['last_streak_milestone_days' => $days]);
            }
        }
    }

    public function recordPassengerCancel(
        User    $passenger,
        Booking $booking,
        float   $elapsedPct,
        string  $paymentMethod = 'cash',
    ): void {
        if ($paymentMethod !== 'cash') {
            return;
        }

        DB::transaction(function () use ($passenger, $booking, $elapsedPct) {
            $this->incrementCancellations($passenger);

            $action = ScoreAction::passengerCancelAction($elapsedPct);
            $this->applyAction(
                user:      $passenger,
                action:    $action,
                reference: $booking,
                context:   ['elapsed_pct' => $elapsedPct],
            );
        });
    }

    public function recordPassengerNoShow(
        User    $passenger,
        Booking $booking,
        string  $paymentMethod
    ): void {
        $userScore = $this->getOrCreateScore($passenger);
        $action    = ScoreAction::PASSENGER_NO_SHOW;
        $result    = $this->policyFactory->make($action)
            ->calculate($action, $userScore);

        $points = ($paymentMethod === PaymentMethod::E_PAY->value) ? 0 : $result->points;

        $previousScore = $userScore->score;
        $userScore->applyDelta($points);
        $userScore->incrementNoShows();

        ScoreTransaction::create([
            'user_id'                  => $passenger->id,
            'action'                   => $action->value,
            'points'                   => $points,
            'previous_score'           => $previousScore,
            'new_score'                => $userScore->score,
            'reference_type'           => Booking::class,
            'reference_id'             => $booking->id,
            'reason'                   => $paymentMethod === PaymentMethod::E_PAY->value
                ? 'Passenger no-show (e-pay) — score unchanged, wallet settled'
                : $result->reason,
            'high_cancel_rate_applied' => false,
            'metadata'                 => [
                'payment_method' => $paymentMethod,
                'booking_id'     => $booking->id,
            ],
        ]);
    }

    public function recordDriverCancelSeat(User $driver, Booking $booking): void
    {
        DB::transaction(function () use ($driver, $booking) {
            $this->getOrCreateScore($driver)->registerDriverCancellation();

            $this->applyAction(
                user:      $driver,
                action:    ScoreAction::DRIVER_CANCEL_SEAT,
                reference: $booking,
                context:   [],
            );
        });
    }

    public function recordDriverCancelRide(
        User  $driver,
        Ride  $ride,
        float $elapsedPct,
    ): void {
        DB::transaction(function () use ($driver, $ride, $elapsedPct) {
            $this->incrementCancellations($driver);
            $this->getOrCreateScore($driver)->registerDriverCancellation();

            $action = ScoreAction::driverCancelRideAction($elapsedPct);
            $this->applyAction(
                user:      $driver,
                action:    $action,
                reference: $ride,
                context:   ['elapsed_pct' => $elapsedPct],
            );
        });
    }

    public function recordDriverNoShow(
        User   $driver,
        Ride   $ride,
        string $paymentMethod
    ): void {
        $userScore = $this->getOrCreateScore($driver);
        $action    = ScoreAction::DRIVER_NO_SHOW;
        $result    = $this->policyFactory->make($action)
            ->calculate($action, $userScore);

        // Driver always loses −15 pts regardless of payment method.
        // For passengers, e-pay zeroes the score because their wallet IS the penalty.
        // For drivers, the refund goes to the passenger — the driver has nothing
        // deducted from their wallet, so the score deduction must always apply.
        $points = $result->points;

        $previousScore = $userScore->score;
        $userScore->applyDelta($points);
        $userScore->incrementNoShows();
        $userScore->registerDriverCancellation();

        ScoreTransaction::create([
            'user_id'                  => $driver->id,
            'action'                   => $action->value,
            'points'                   => $points,
            'previous_score'           => $previousScore,
            'new_score'                => $userScore->score,
            'reference_type'           => Ride::class,
            'reference_id'             => $ride->id,
            'reason'                   => $result->reason,
            'high_cancel_rate_applied' => false,
            'metadata'                 => [
                'payment_method' => $paymentMethod,
                'ride_id'        => $ride->id,
            ],
        ]);
    }

    // =========================================================================
    // READ
    // =========================================================================

    public function getScore(User $user): UserScore
    {
        return UserScore::firstOrCreate(
            ['user_id' => $user->id],
            ['score' => 70, 'total_rides' => 0, 'total_cancellations' => 0],
        );
    }

    public function getHistory(User $user, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return ScoreTransaction::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public static function calculateElapsedPct(
        \Carbon\Carbon $createdAt,
        \Carbon\Carbon $departureTime,
    ): float {
        $now            = now();
        $totalMinutes   = $createdAt->diffInMinutes($departureTime);
        $elapsedMinutes = $createdAt->diffInMinutes($now);

        return $totalMinutes > 0
            ? min(100, round(($elapsedMinutes / $totalMinutes) * 100, 2))
            : 100.0;
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function getOrCreateScore(User $user): UserScore
    {
        return UserScore::firstOrCreate(
            ['user_id' => $user->id],
            [
                'score'               => 70,
                'total_rides'         => 0,
                'total_cancellations' => 0,
                'total_no_shows'      => 0,
            ]
        );
    }

    // FIX: Added ?object $reference = null parameter — was missing, causing
    // "Unknown named parameter 'reference'" errors at every call site.
    public function applyAction(
        User        $user,
        ScoreAction $action,
        ?object     $reference = null,   // <-- THE FIX
        array       $context   = [],
    ): void {
        DB::transaction(function () use ($user, $action, $reference, $context) {

            $userScore = UserScore::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'score'               => 100,
                    'tier'                => 'bronze',
                    'total_rides'         => 0,
                    'total_cancellations' => 0,
                    'cancel_rate'         => 0.0,
                ]
            );

            $policy = $this->policyFactory->make($action);
            $result = $policy->calculate($action, $userScore, $context);

            $previousScore = (int) $userScore->score;
            $newScore      = max(0, min(100, $previousScore + $result->points));

            // NOTE: gated on the action itself, not $result->isPositive() (points > 0) —
            // rating and commitment-streak bonuses are also positive-point actions but
            // must NOT be counted as completed rides for total_rides / cancel_rate.
            if ($action === ScoreAction::RIDE_COMPLETED) {
                $userScore->total_rides = (int) $userScore->total_rides + 1;

                if ($userScore->total_rides > 0) {
                    $userScore->cancel_rate = round(
                        ((int) $userScore->total_cancellations / $userScore->total_rides) * 100,
                        2
                    );
                }
            }

            $userScore->score = $newScore;
            $userScore->tier  = $this->resolveTier($newScore);
            $userScore->save();

            // FIX: resolve reference from the passed $reference object directly,
            // falling back to context keys for callers that still use old style.
            $referenceType = null;
            $referenceId   = null;

            if ($reference !== null) {
                $referenceType = get_class($reference);
                $referenceId   = $reference->id;
            } elseif (isset($context['booking_id'])) {
                $referenceType = Booking::class;
                $referenceId   = $context['booking_id'];
            } elseif (isset($context['ride_id'])) {
                $referenceType = Ride::class;
                $referenceId   = $context['ride_id'];
            }

            ScoreTransaction::create([
                'user_id'                  => $user->id,
                'action'                   => $action->value,
                'points'                   => $result->points,
                'previous_score'           => $previousScore,
                'new_score'                => $newScore,
                'reason'                   => $result->reason,
                'high_cancel_rate_applied' => $result->highCancelRateApplied,
                'reference_type'           => $referenceType,
                'reference_id'             => $referenceId,
            ]);

            Log::info('Score action applied', [
                'user_id'        => $user->id,
                'action'         => $action->value,
                'points'         => $result->points,
                'previous_score' => $previousScore,
                'new_score'      => $newScore,
            ]);
        });
    }

    private function incrementRides(User $user): void
    {
        $this->getScore($user)->incrementRides();
    }

    private function incrementCancellations(User $user): void
    {
        $this->getScore($user)->incrementCancellations();
    }

    // FIX: was "rivate function" (missing 'p') — caused "Unexpected: identifier" at line 379
    private function resolveTier(int $score): string
    {
        return match (true) {
            $score >= 200 => 'platinum',
            $score >= 150 => 'gold',
            $score >= 100 => 'silver',
            default       => 'bronze',
        };
    }


    /**
     * ─────────────────────────────────────────────────────────────────────────────
     * If ScoreService.php doesn't already have an applyScore() method,
     * drop this into app/Services/Score/ScoreService.php alongside getScore()
     * and getHistory().
     *
     * Required use-statements in ScoreService.php:
     *
     *   use App\Domain\Score\ScorePolicyFactory;
     *   use App\Domain\Score\ScoreResult;
     *   use App\Enums\ScoreAction;
     *   use App\Models\ScoreTransaction;
     *   use App\Models\User;
     *   use App\Models\UserScore;
     *   use Illuminate\Support\Facades\DB;
     * ─────────────────────────────────────────────────────────────────────────────
     */

    /**
     * Apply a score action for a user and persist the result.
     *
     * Creates or updates the UserScore row, then inserts a ScoreTransaction
     * for the history feed (GET /api/score/history and GET /api/score/transactions).
     *
     * @param User $user The user receiving the score change
     * @param ScoreAction $action The action that triggered the change
     * @param mixed|null $reference The Booking or Ride that caused it (for history)
     * @param array $context Extra data the policy may need (e.g. elapsed_pct)
     */
    public function applyScore(
        User        $user,
        ScoreAction $action,
        mixed       $reference = null,
        array       $context = [],
    ): ScoreResult
    {
        return DB::transaction(function () use ($user, $action, $reference, $context) {

            // ── Get or create the user's score record ─────────────────────────
            $userScore = UserScore::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'score' => 100,   // starting score
                    'tier' => 'bronze',
                    'total_rides' => 0,
                    'total_cancellations' => 0,
                    'cancel_rate' => 0.0,
                ]
            );

            // ── Resolve the correct policy and calculate the result ───────────
            $policy = app(ScorePolicyFactory::class)->make($action);
            $result = $policy->calculate($action, $userScore, $context);

            // ── Update aggregate stats on UserScore ───────────────────────────
            $previousScore = $userScore->score;
            $newScore = max(0, min(100, $previousScore + $result->points));

            $updates = ['score' => $newScore];

            // Track ride and cancellation counters so cancel_rate stays accurate
            if ($action === ScoreAction::RIDE_COMPLETED) {
                $updates['total_rides'] = $userScore->total_rides + 1;
            } elseif (str_contains($action->value, 'cancel') || str_contains($action->value, 'no_show')) {
                $updates['total_cancellations'] = $userScore->total_cancellations + 1;
                $totalEvents = $userScore->total_rides + $userScore->total_cancellations + 1;
                $updates['cancel_rate'] = $totalEvents > 0
                    ? round(($updates['total_cancellations'] / $totalEvents) * 100, 2)
                    : 0.0;
            }

            // ── Tier recalculation ────────────────────────────────────────────
            $updates['tier'] = match (true) {
                $newScore >= 200 => 'platinum',
                $newScore >= 150 => 'gold',
                $newScore >= 100 => 'silver',
                default => 'bronze',
            };

            $userScore->update($updates);

            // ── Persist score history ─────────────────────────────────────────
            ScoreTransaction::create([
                'user_id' => $user->id,
                'action' => $action->value,
                'points' => $result->points,
                'previous_score' => $previousScore,
                'new_score' => $newScore,
                'reason' => $result->reason,
                'high_cancel_rate_applied' => $result->highCancelRateApplied,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
            ]);

            return $result;
        });
    }
}
