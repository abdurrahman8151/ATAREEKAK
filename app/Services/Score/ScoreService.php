<?php

namespace App\Services\Score;

use App\Domain\Score\ScorePolicyFactory;
use App\Enums\PaymentMethod;
use App\Enums\ScoreAction;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\ScoreTransaction;
use App\Models\User;
use App\Models\UserScore;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ScoreService
{
    /**
     * The owner's score policy (un2, 2026-10-02, R2 sec 40.1) as ONE source of truth.
     *
     * RV-11 (R2 sec 67): these three numbers were previously written as four separate literals
     * across the creation paths, and one of them said 100 while the other three said 70 - so a
     * user could start at 70 or 100 depending on which method touched them first. The bands
     * themselves live in `UserScore::getTierAttribute` and are pinned by `RV37ScorePolicyTest`;
     * `R2 sec 26.14` notes there is still no `config/score.php`, which remains open.
     */
    public const START_SCORE = 70;

    public const MIN_SCORE = 0;

    public const MAX_SCORE = 100;

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
            ['score' => self::START_SCORE, 'total_rides' => 0, 'total_cancellations' => 0]
        );
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function recordRideCompleted(Ride $ride): void
    {
        DB::transaction(function () use ($ride) {
            // RV-37 / un9: the driver's score row is read twice below; load the relation once.
            $ride->loadMissing('driver');

            // RV-11 (R2 sec 67): `applyAction` already counts the ride - RIDE_COMPLETED is the only
            // action that increments `total_rides`. The `incrementRides()` that used to follow each
            // call counted EVERY completed ride TWICE, which inflated the denominator behind
            // `cancel_rate` (total_cancellations / (total_rides + total_cancellations)) so the 50%
            // high-cancel gate stopped firing when it should. One ride, one increment.
            $this->applyAction(
                user: $ride->driver,
                action: ScoreAction::RIDE_COMPLETED,
                reference: $ride,
                context: [],
            );

            $ride->bookings()
                ->where('status', 'completed')
                ->with('user')
                ->get()
                ->each(function ($booking) use ($ride) {
                    $this->applyAction(
                        user: $booking->user,
                        action: ScoreAction::RIDE_COMPLETED,
                        reference: $ride,
                        context: [],
                    );
                });
        });
    }

    public function recordPassengerCancel(
        User $passenger,
        Booking $booking,
        float $elapsedPct,
        string $paymentMethod = 'cash',
    ): void {
        if ($paymentMethod !== 'cash') {
            return;
        }

        DB::transaction(function () use ($passenger, $booking, $elapsedPct) {
            $this->incrementCancellations($passenger);

            $action = ScoreAction::passengerCancelAction($elapsedPct);
            $this->applyAction(
                user: $passenger,
                action: $action,
                reference: $booking,
                context: ['elapsed_pct' => $elapsedPct],
            );
        });
    }

    public function recordPassengerNoShow(
        User $passenger,
        Booking $booking,
        string $paymentMethod
    ): void {
        $userScore = $this->getOrCreateScore($passenger);
        $action = ScoreAction::PASSENGER_NO_SHOW;
        $result = $this->policyFactory->make($action)
            ->calculate($action, $userScore);

        $points = ($paymentMethod === PaymentMethod::E_PAY->value) ? 0 : $result->points;

        $previousScore = $userScore->score;
        $userScore->applyDelta($points);
        $userScore->incrementNoShows();

        ScoreTransaction::create([
            'user_id' => $passenger->id,
            'action' => $action->value,
            'points' => $points,
            'previous_score' => $previousScore,
            'new_score' => $userScore->score,
            'reference_type' => Booking::class,
            'reference_id' => $booking->id,
            'reason' => $paymentMethod === PaymentMethod::E_PAY->value
                ? 'Passenger no-show (e-pay) — score unchanged, wallet settled'
                : $result->reason,
            'high_cancel_rate_applied' => false,
            'metadata' => [
                'payment_method' => $paymentMethod,
                'booking_id' => $booking->id,
            ],
        ]);
    }

    public function recordDriverCancelSeat(User $driver, Booking $booking): void
    {
        DB::transaction(function () use ($driver, $booking) {
            $this->applyAction(
                user: $driver,
                action: ScoreAction::DRIVER_CANCEL_SEAT,
                reference: $booking,
                context: [],
            );
        });
    }

    public function recordDriverCancelRide(
        User $driver,
        Ride $ride,
        float $elapsedPct,
    ): void {
        DB::transaction(function () use ($driver, $ride, $elapsedPct) {
            $this->incrementCancellations($driver);

            $action = ScoreAction::driverCancelRideAction($elapsedPct);
            $this->applyAction(
                user: $driver,
                action: $action,
                reference: $ride,
                context: ['elapsed_pct' => $elapsedPct],
            );
        });
    }

    public function recordDriverNoShow(
        User $driver,
        Ride $ride,
        string $paymentMethod
    ): void {
        $userScore = $this->getOrCreateScore($driver);
        $action = ScoreAction::DRIVER_NO_SHOW;
        $result = $this->policyFactory->make($action)
            ->calculate($action, $userScore);

        // Driver always loses −15 pts regardless of payment method.
        // For passengers, e-pay zeroes the score because their wallet IS the penalty.
        // For drivers, the refund goes to the passenger — the driver has nothing
        // deducted from their wallet, so the score deduction must always apply.
        $points = $result->points;

        $previousScore = $userScore->score;
        $userScore->applyDelta($points);
        $userScore->incrementNoShows();

        ScoreTransaction::create([
            'user_id' => $driver->id,
            'action' => $action->value,
            'points' => $points,
            'previous_score' => $previousScore,
            'new_score' => $userScore->score,
            'reference_type' => Ride::class,
            'reference_id' => $ride->id,
            'reason' => $result->reason,
            'high_cancel_rate_applied' => false,
            'metadata' => [
                'payment_method' => $paymentMethod,
                'ride_id' => $ride->id,
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
            ['score' => self::START_SCORE, 'total_rides' => 0, 'total_cancellations' => 0],
        );
    }

    public function getHistory(User $user, int $limit = 20): Collection
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
        Carbon $createdAt,
        Carbon $departureTime,
    ): float {
        $now = now();
        $totalMinutes = $createdAt->diffInMinutes($departureTime);
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
                'score' => self::START_SCORE,
                'total_rides' => 0,
                'total_cancellations' => 0,
                'total_no_shows' => 0,
            ]
        );
    }

    // FIX: Added ?object $reference = null parameter — was missing, causing
    // "Unknown named parameter 'reference'" errors at every call site.
    public function applyAction(
        User $user,
        ScoreAction $action,
        ?object $reference = null,   // <-- THE FIX
        array $context = [],
    ): void {
        DB::transaction(function () use ($user, $action, $reference, $context) {

            // RV-11 (R2 sec 67): this was the ONLY one of the four creation sites that started a user
            // at 100, while `initializeScore`, `getScore` and `getOrCreateScore` all started at 70.
            // un2 pinned the start score at 70, so this path silently contradicted the owner's own
            // decision whenever `applyAction` happened to be the first thing to touch a score row.
            // `tier` and `cancel_rate` are dropped: both are COMPUTED (`setTierAttribute` and
            // `setCancelRateAttribute` discard assignments), so listing them here implied they were
            // stored - which is what made the dead `cancel_rate` write below look load-bearing.
            $userScore = UserScore::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'score' => self::START_SCORE,
                    'total_rides' => 0,
                    'total_cancellations' => 0,
                    'total_no_shows' => 0,
                ]
            );

            $policy = $this->policyFactory->make($action);
            $result = $policy->calculate($action, $userScore, $context);

            $previousScore = (int) $userScore->score;

            // RV-11 (R2 sec 67): this clamped the FLOOR only. un2 pinned the ceiling at 100 and
            // `UserScore::applyDelta` already clamps both ends, so the two mutation paths disagreed
            // and a run of positive actions could push a user above their maximum score.
            $newScore = max(
                self::MIN_SCORE,
                min(self::MAX_SCORE, $previousScore + $result->points)
            );

            // RV-11 (R2 sec 67): was `if ($result->isPositive())`. RIDE_COMPLETED is the only positive
            // action in the enum, so this is behaviour-preserving today - but it said the wrong
            // thing. A future positive bonus would have inflated the ride count, and the ride count
            // is what `cancel_rate` divides by. Gate it on the action, which is what it means.
            //
            // The `cancel_rate` recomputation that used to live here was DEAD CODE: the column has a
            // computed accessor (`UserScore::getCancelRateAttribute`) and a mutator that discards
            // writes, so the assignment was silently thrown away - and it used a different formula
            // from the accessor anyway. The policies read the accessor, so deleting it changes no
            // behaviour; keeping it only made the model look like it stored something it does not.
            if ($action === ScoreAction::RIDE_COMPLETED) {
                $userScore->total_rides = (int) $userScore->total_rides + 1;
            }

            $userScore->score = $newScore;
            // R2 sec 42: the stored `tier` write is GONE. The column is legacy and
            // `UserScore::setTierAttribute` deliberately discards assignments ("computed from score
            // - never stored"), so this line silently did nothing while appearing to maintain a
            // third copy of the bands - and it used a 200/150/100 scale against a 0-100 score, so
            // it labelled every user `bronze`. The computed accessor is now the only definition.
            $userScore->save();

            // FIX: resolve reference from the passed $reference object directly,
            // falling back to context keys for callers that still use old style.
            $referenceType = null;
            $referenceId = null;

            if ($reference !== null) {
                $referenceType = get_class($reference);
                $referenceId = $reference->id;
            } elseif (isset($context['booking_id'])) {
                $referenceType = Booking::class;
                $referenceId = $context['booking_id'];
            } elseif (isset($context['ride_id'])) {
                $referenceType = Ride::class;
                $referenceId = $context['ride_id'];
            }

            ScoreTransaction::create([
                'user_id' => $user->id,
                'action' => $action->value,
                'points' => $result->points,
                'previous_score' => $previousScore,
                'new_score' => $newScore,
                'reason' => $result->reason,
                'high_cancel_rate_applied' => $result->highCancelRateApplied,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            Log::info('Score action applied', [
                'user_id' => $user->id,
                'action' => $action->value,
                'points' => $result->points,
                'previous_score' => $previousScore,
                'new_score' => $newScore,
            ]);
        });
    }

    private function incrementCancellations(User $user): void
    {
        $this->getScore($user)->incrementCancellations();
    }
}
