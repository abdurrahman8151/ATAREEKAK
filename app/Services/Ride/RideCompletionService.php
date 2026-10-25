<?php

namespace App\Services\Ride;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Payment\CashRideFeeService;
use App\Services\Payment\RidePayout;
use App\Services\Score\ScoreService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ride-completion lifecycle: the driver signals the ride is over, mutual confirmation is
 * awaited, an empty ride finishes immediately, and the payout and the score are released once
 * every passenger has confirmed.
 *
 * Split out of RideService (behaviour unchanged). Bodies moved verbatim, so the payout amounts
 * and the score actions are exactly as they were.
 */
final class RideCompletionService
{
    public function __construct(
        private readonly RidePayout $ridePayout,
        private readonly NotificationService $notificationService,
        private readonly ScoreService $scoreService,
        private readonly CashRideFeeService $cashRideFeeService,
    ) {}

    /**
     * Driver marks the ride as finished after departure.
     *
     * Case A — No confirmed bookings (empty ride):
     *   → Status: FINISHED immediately.
     *   → Creation fee refunded (SyCash → Driver).
     *   → No score event (no passengers completed anything).
     *
     * Case B — Has confirmed bookings (CASH or E-PAY):
     *   → Status: AWAITING_CONFIRMATION.
     *   → All parties (driver + every confirmed passenger) are notified to confirm.
     *   → Payment release and score are handled in checkAndCompleteRide() once
     *     every party has confirmed.
     *
     * The two-way confirmation step exists for both payment methods because it:
     *   (a) Verifies the ride actually happened before releasing E-PAY funds.
     *   (b) Generates trustworthy score events (+10) for both CASH and E-PAY rides.
     */
    public function finishRide(int $rideId, User $driver): array
    {
        $ride = Ride::with('driver')->findOrFail($rideId);

        $this->assertDriverMayFinishRide($ride, $driver);

        $confirmedBookings = $ride->bookings()
            ->where('status', BookingStatus::CONFIRMED->value)
            ->get();

        // Case A: empty ride - the driver is finished with no one to wait for.
        if ($confirmedBookings->isEmpty()) {
            return $this->finishEmptyRide($ride);
        }

        // Case B: confirmed passengers - await mutual confirmation.
        return $this->awaitMutualConfirmation($ride);
    }

    /** Ownership, a finishable status, and departure already passed. */
    private function assertDriverMayFinishRide(Ride $ride, User $driver): void
    {
        if ($ride->driver_id !== $driver->id) {
            throw new \InvalidArgumentException('Only the ride driver can finish it');
        }

        $rideStatus = RideStatus::from($ride->status);
        if (! in_array($rideStatus, [RideStatus::ACTIVE, RideStatus::FULL])) {
            throw new \InvalidArgumentException(
                "Can only finish an active or full ride (current: {$rideStatus->label()})"
            );
        }

        if (now()->lessThan(Carbon::parse($ride->departure_time))) {
            throw new \InvalidArgumentException('Cannot finish a ride before its departure time');
        }
    }

    /** Case A: finish immediately and refund the creation fee. */
    private function finishEmptyRide(Ride $ride): array
    {
        return DB::transaction(function () use ($ride) {
            $ride->status = RideStatus::FINISHED->value;
            $ride->finished_at = now();
            $ride->save();

            // Refund the creation fee (deferred -> debt reduction; paid -> wallet credit).
            if ($ride->payment_method === PaymentMethod::CASH->value) {
                $this->cashRideFeeService->refundCashRideCreationFee($ride, $ride->driver);
            }

            $this->notificationService->createNotification(
                $ride->driver,
                'ride_finished_no_passengers',
                'Ride Finished',
                "Ride from {$ride->pickup_address} to {$ride->destination_address} finished. "
                .'No passengers booked — creation fee refunded.',
                ['ride_id' => $ride->id],
                'normal', 'ride'
            );

            Log::info('Ride finished with no passengers', ['ride_id' => $ride->id]);

            return [
                'status' => RideStatus::FINISHED->value,
                'message' => 'Ride finished. No passengers booked — creation fee refunded.',
                'requires_confirmation' => false,
            ];
        });
    }

    /** Case B: move the ride to awaiting confirmation and ask everyone to confirm. */
    private function awaitMutualConfirmation(Ride $ride): array
    {
        return DB::transaction(function () use ($ride) {
            $ride->status = RideStatus::AWAITING_CONFIRMATION->value;
            $ride->finished_at = now();
            $ride->save();

            $this->notifyAllForConfirmation($ride);

            Log::info('Ride moved to awaiting confirmation', ['ride_id' => $ride->id]);

            return [
                'status' => RideStatus::AWAITING_CONFIRMATION->value,
                'message' => 'Ride completed. Waiting for all parties to confirm.',
                'requires_confirmation' => true,
            ];
        });
    }

    /**
     * Driver confirms the ride took place.
     * Triggers checkAndCompleteRide() — finalises everything if all passengers
     * have also confirmed.
     */
    public function driverConfirmCompletion(int $rideId, User $driver): array
    {
        $ride = Ride::with('driver')->findOrFail($rideId);

        if ($ride->driver_id !== $driver->id) {
            throw new \InvalidArgumentException('Only the ride driver can confirm completion');
        }
        if ($ride->status !== RideStatus::AWAITING_CONFIRMATION->value) {
            throw new \InvalidArgumentException('Ride is not awaiting confirmation');
        }
        if ($ride->driver_confirmed_at) {
            throw new \InvalidArgumentException('Driver has already confirmed this ride');
        }

        DB::transaction(function () use ($ride) {
            $ride->driver_confirmed_at = now();
            $ride->save();
        });

        Log::info('Driver confirmed completion', [
            'ride_id' => $ride->id,
            'driver_id' => $driver->id,
        ]);

        $this->checkAndCompleteRide($ride->fresh());

        return ['message' => 'Driver confirmation received. Waiting for passenger confirmations.'];
    }

    /**
     * Checks whether all parties have confirmed. When they have:
     *   1. Release E-PAY escrow → driver (CASH: skip wallet, no-op).
     *   2. Mark ride FINISHED and all confirmed bookings COMPLETED.
     *   3. Record +10 score for driver and every confirmed passenger.
     *      (ScoreService::recordRideCompleted handles both driver and passengers.)
     *   4. Send completion notifications to all parties.
     *
     * Idempotent — returns immediately if the ride is not in AWAITING_CONFIRMATION
     * or if not all confirmations are in yet.
     */
    public function checkAndCompleteRide(Ride $ride): void
    {
        DB::transaction(function () use ($ride) {

            // ── Re-load inside a row-level lock ───────────────────────────
            $ride = Ride::lockForUpdate()->findOrFail($ride->id);

            // ── Idempotency guard ─────────────────────────────────────────
            if ($ride->status !== RideStatus::AWAITING_CONFIRMATION->value) {
                return;
            }

            // ── Gate: driver must have confirmed ─────────────────────────
            if (! $ride->driver_confirmed_at) {
                return;
            }

            // ── Gate: every confirmed booking must have passenger confirm ─
            $confirmedBookings = $ride->bookings()
                ->where('status', BookingStatus::CONFIRMED->value)
                ->get();

            if (! $this->everyPassengerConfirmed($confirmedBookings)) {
                return;
            }

            // ── Flip status to FINISHED *before* any side-effects ─────────
            $ride->status = RideStatus::FINISHED->value;
            $ride->finished_at = now();
            $ride->save();

            // ── Release wallet escrow (E-PAY rides only) ──────────────────
            $ePayBookings = $confirmedBookings->filter(
                fn ($b) => $ride->payment_method === PaymentMethod::E_PAY->value
            );
            if ($ePayBookings->isNotEmpty()) {
                $this->ridePayout->releaseEarningsToDriver($ride, $ePayBookings);
            }

            // ── Mark every confirmed booking as completed ─────────────────
            foreach ($confirmedBookings as $booking) {
                $booking->markAsCompleted();
            }

            // ── Record scores (+10 driver + +10 each confirmed passenger) ─
            // recordRideCompleted() internally awards the driver and iterates
            // every booking with status='completed', so no extra loop needed.
            $this->scoreService->recordRideCompleted($ride);

            // ── Notify driver and every passenger ─────────────────────────
            $this->notifyRideCompleted($ride, $confirmedBookings);

            Log::info('Ride completed', [
                'ride_id' => $ride->id,
                'bookings' => $confirmedBookings->count(),
                'payment' => $ride->payment_method,
            ]);
        }, attempts: 3);
    }

    /** An empty set counts as confirmed: there is no passenger left to wait for. */
    private function everyPassengerConfirmed(Collection $confirmedBookings): bool
    {
        return $confirmedBookings->every(
            fn ($b) => $b->passenger_confirmed_at !== null
        );
    }

    /** Tell the driver and every confirmed passenger that the ride is complete. */
    private function notifyRideCompleted(Ride $ride, Collection $confirmedBookings): void
    {
        $this->notificationService->createNotification(
            $ride->driver,
            'ride_completed',
            'Ride Completed ✓',
            "Your ride from {$ride->pickup_address} to {$ride->destination_address} is complete. Earnings released.",
            ['ride_id' => $ride->id],
            'high',
            'ride'
        );

        foreach ($confirmedBookings as $booking) {
            $this->notificationService->createNotification(
                $booking->user,
                'ride_completed',
                'Ride Completed ✓',
                "Your ride from {$ride->pickup_address} to {$ride->destination_address} is complete. Thank you!",
                ['ride_id' => $ride->id, 'booking_id' => $booking->id],
                'high',
                'ride'
            );
        }
    }

    /**
     * Notify the driver and every confirmed passenger to confirm completion.
     */
    private function notifyAllForConfirmation(Ride $ride): void
    {
        $this->notificationService->createNotification(
            $ride->driver,
            'confirm_completion_needed',
            'Confirm Ride Completion',
            "Please confirm that the ride from {$ride->pickup_address} to {$ride->destination_address} "
            .'was completed to release your earnings and earn +10 trust points.',
            ['ride_id' => $ride->id],
            'high', 'ride'
        );

        $ride->bookings()
            ->where('status', BookingStatus::CONFIRMED->value)
            ->with('user')
            ->get()
            ->each(function (Booking $booking) use ($ride) {
                $this->notificationService->createNotification(
                    $booking->user,
                    'confirm_completion_needed',
                    'Did the Ride Happen?',
                    "Please confirm that the ride from {$ride->pickup_address} to "
                    ."{$ride->destination_address} was completed to earn +10 trust points.",
                    ['ride_id' => $ride->id, 'booking_id' => $booking->id],
                    'normal', 'ride'
                );
            });
    }
}
