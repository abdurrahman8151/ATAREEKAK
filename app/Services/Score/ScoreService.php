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

    // RV-11 (`R2 sec 73`): `ScorePolicyFactory` is no longer injected here. Every policy lookup moved
    // into `ScoreLedger::apply()`, so this service does not need the factory at all - and leaving a
    // dead constructor dependency behind would be the same "two definitions of one thing" problem
    // the ledger exists to remove.
    public function __construct(
        private readonly ScoreLedger $ledger,
    ) {}

    // =========================================================================
    // INITIALIZATION (called by UserObserver on signup)
    // =========================================================================

    public function initializeScore(User $user): UserScore
    {
        // RV-11 (`R2 sec 73`): the last of the four creation sites. It happened to start at 70, so it
        // disagreed only with `applyAction`'s copy that started at 100 - but a user could therefore
        // start at 70 or 100 depending on which method touched them first. One creation path now.
        return $this->ledger->scoreRow($user);
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
        // RV-11 (`R2 sec 73`): this used to run the policy, `applyDelta()`, `incrementNoShows()`
        // and then write its OWN `ScoreTransaction` with `high_cancel_rate_applied` hard-coded
        // false. That last part was the real defect: the audit trail denied a high-cancel gate that
        // had actually fired, on the path where it is most likely to. It also saved the row twice.
        //
        // An E-PAY passenger's penalty IS the wallet settlement, so their SCORE change is zero -
        // but the no-show still counts against them, which is why the override is on points only.
        $isEPay = $paymentMethod === PaymentMethod::E_PAY->value;

        $this->ledger->apply(
            user: $passenger,
            action: ScoreAction::PASSENGER_NO_SHOW,
            reference: $booking,
            context: [],
            pointsOverride: $isEPay ? 0 : null,
            reasonOverride: $isEPay
                ? 'Passenger no-show (e-pay) — score unchanged, wallet settled'
                : null,
            metadata: [
                'payment_method' => $paymentMethod,
                'booking_id' => $booking->id,
            ],
        );
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
        // RV-11 (`R2 sec 73`): the second copy of the no-show shape. Driver always loses the full
        // −15 regardless of payment method - for a passenger the refund is the penalty, but a
        // driver's refund goes TO the passenger, so nothing was deducted from their wallet and the
        // score deduction must always apply. Hence no `pointsOverride` here, unlike the passenger.
        $this->ledger->apply(
            user: $driver,
            action: ScoreAction::DRIVER_NO_SHOW,
            reference: $ride,
            context: [],
            metadata: [
                'payment_method' => $paymentMethod,
                'ride_id' => $ride->id,
            ],
        );
    }

    // =========================================================================
    // READ
    // =========================================================================

    public function getScore(User $user): UserScore
    {
        return $this->ledger->scoreRow($user);
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

    // RV-11 (`R2 sec 73`): `getOrCreateScore()` is GONE. It was a fourth copy of the score-creation
    // query - the one that omitted `total_no_shows` - and after both no-show paths moved to the
    // ledger nothing called it. The single copy now lives in `ScoreLedger::scoreRow()`.

    // FIX: Added ?object $reference = null parameter — was missing, causing
    // "Unknown named parameter 'reference'" errors at every call site.
    public function applyAction(
        User $user,
        ScoreAction $action,
        ?object $reference = null,   // <-- THE FIX
        array $context = [],
    ): void {
        // RV-11 (`R2 sec 73`): this was already the correct write path, and is now a thin delegate
        // so that it and the no-show paths cannot drift apart again. Its own clamp, its own
        // `firstOrCreate` (the one that started at 100), its own RIDE_COMPLETED ride increment and
        // its own `ScoreTransaction` write all now live in `ScoreLedger::apply()`.
        $this->ledger->apply(
            user: $user,
            action: $action,
            reference: $reference,
            context: $context,
        );
    }

    private function incrementCancellations(User $user): void
    {
        // RV-11 (`R2 sec 73`): the counter write now goes through the ledger too, so every write to
        // a score row passes through one class.
        $this->ledger->recordCancellation($user);
    }
}
