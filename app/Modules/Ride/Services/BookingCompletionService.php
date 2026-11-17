<?php

namespace App\Modules\Ride\Services;

use App\Enums\BookingStatus;
use App\Enums\RideStatus;
use App\Enums\ScoreAction;
use App\Modules\Ride\Models\Booking;
use App\Modules\Ride\Models\NoshowReport;
use App\Modules\Ride\Models\Ride;
use App\Models\User;
use App\Modules\Money\Contracts\PaymentStrategyFactoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * The completion half of BookingService: a passenger confirming the ride, a driver reporting a
 * no-show, and the payment/score settlement either path triggers.
 *
 * Split out of BookingService (behaviour unchanged). It owns the confirm/no-show rules and the
 * release of the completion payment; it does not create, accept or cancel bookings. Bodies moved
 * verbatim.
 */
final class BookingCompletionService
{
    public function __construct(
        private readonly PaymentStrategyFactoryInterface $paymentFactory,
        private readonly ScoreService $scoreService,
        private readonly Noshowservice $noshowService,
    ) {}

    /**
     * Passenger confirms the ride actually took place.
     * Once the driver AND all confirmed passengers have confirmed,
     * RideService::checkAndCompleteRide() releases payment and records scores.
     */
    public function passengerConfirmCompletion(int $bookingId, User $passenger): array
    {
        return DB::transaction(function () use ($bookingId, $passenger) {

            // RV-37 / un9: `ride` is read further down (cash-fee + notify paths); eager-load it
            // rather than taking a second query per confirmation.
            $booking = Booking::lockForUpdate()->with('ride')->findOrFail($bookingId);

            $this->assertPassengerMayConfirm($booking, $passenger);

            // NO-SHOW MUTUAL EXCLUSION and the booking-state refusals live in the helper.

            $ride = $this->lockConfirmableRide($booking);

            $this->releaseCompletionPayment($booking, $ride, $passenger);

            $rideNowFinished = $this->finishRideWhenAllConfirmed($ride);

            return [
                'message' => $rideNowFinished
                    ? 'Confirmed. All passengers done — ride is now finished.'
                    : 'Confirmed successfully.',
                'ride_finished' => $rideNowFinished,
            ];
        }, attempts: 3);
    }

    /**
     * Driver reports that a confirmed passenger did not show up (after departure).
     *
     * Wallet (E-PAY only):
     *   Admin escrow → 95% Driver + 5% SyCash. Passenger receives nothing.
     *
     * Score (CASH only):
     *   −15 pts to passenger.
     *   E-PAY: no score penalty (wallet settlement acts as the financial penalty).
     */
    public function reportPassengerNoShow(int $bookingId, User $driver): array
    {
        return $this->noshowService
            ->reportPassengerNoShow($bookingId, $driver);
    }

    /**
     * Refuse a confirmation that is not this passenger's to make, that the booking
     * state forbids, or that would contradict an active driver-no-show report.
     *
     * A passenger who filed a driver-no-show report cannot simultaneously confirm the
     * ride: the two actions are mutually exclusive.
     */
    private function assertPassengerMayConfirm(Booking $booking, User $passenger): void
    {
        if ((int) $booking->user_id !== (int) $passenger->id) {
            throw new \InvalidArgumentException('You can only confirm your own bookings.');
        }

        if ($booking->status !== BookingStatus::CONFIRMED->value) {
            $msg = match ($booking->status) {
                BookingStatus::COMPLETED->value => 'You have already confirmed this ride.',
                BookingStatus::CANCELLED->value => 'This booking has been cancelled.',
                'no_show' => 'This booking was marked as a no-show.',
                default => 'This booking cannot be confirmed in its current state.',
            };
            throw new \InvalidArgumentException($msg);
        }

        $hasPendingNoShow = NoshowReport::where('ride_id', $booking->ride_id)
            ->where('reporter_id', $passenger->id)
            ->where('reporter_role', 'passenger')
            ->whereIn('status', ['pending', 'disputed'])
            ->exists();

        if ($hasPendingNoShow) {
            throw new \InvalidArgumentException(
                'You have an active no-show report for this ride. You cannot confirm and dispute simultaneously.'
            );
        }
    }

    /**
     * Lock the ride and move it into its confirmable state.
     *
     * A ride may be confirmed once passengers are allowed to be on it (active/full) or it
     * is already in a confirmable state (launched, or the legacy awaiting_confirmation
     * value still stored on rows created before LAUNCHED replaced it). The first
     * confirmation moves the ride into that state; legacy rows normalise to launched.
     */
    private function lockConfirmableRide(Booking $booking): Ride
    {
        // RV-37 / un9: this ride's `driver` is read by the completion/score paths below.
        $ride = Ride::lockForUpdate()->with('driver')->findOrFail($booking->ride_id);

        if (now()->lt($ride->departure_time)) {
            throw new \InvalidArgumentException(
                'The ride has not departed yet. Confirmation opens after the departure time.'
            );
        }

        $rideStatus = RideStatus::tryFrom($ride->status);

        if (! $rideStatus || (! $rideStatus->canBeBooked() && ! $rideStatus->isConfirmable())) {
            throw new \InvalidArgumentException(
                "This ride cannot be confirmed (current status: {$ride->status})."
            );
        }

        if ($ride->status !== RideStatus::LAUNCHED->value) {
            $ride->status = RideStatus::LAUNCHED->value;
            $ride->save();
        }

        return $ride;
    }

    /** Mark the booking completed, release the held payment and record the score. */
    private function releaseCompletionPayment(Booking $booking, Ride $ride, User $passenger): void
    {
        $booking->update([
            'status' => BookingStatus::COMPLETED->value,
            'completed_at' => now(),
        ]);

        $strategy = $this->paymentFactory->make($ride->payment_method);
        $paymentResult = $strategy->processRideCompletionPayment($booking, $ride, $passenger);

        if (! $paymentResult->success) {
            throw new \RuntimeException('Payment release failed: '.$paymentResult->message);
        }

        $this->scoreService->applyAction(
            user: $passenger,
            action: ScoreAction::RIDE_COMPLETED,
            reference: $booking,
        );
    }

    /** True when no unresolved booking remains; finishing also scores the driver. */
    private function finishRideWhenAllConfirmed(Ride $ride): bool
    {
        $unresolvedCount = Booking::where('ride_id', $ride->id)
            ->whereNotIn('status', [
                BookingStatus::COMPLETED->value,
                BookingStatus::CANCELLED->value,
                'no_show',
            ])
            ->count();

        if ($unresolvedCount !== 0) {
            return false;
        }

        $ride->status = RideStatus::FINISHED->value;
        $ride->save();

        $driver = User::find($ride->driver_id);
        if ($driver) {
            $this->scoreService->applyAction(
                user: $driver,
                action: ScoreAction::RIDE_COMPLETED,
                reference: $ride,
            );
        }

        return true;
    }
}
