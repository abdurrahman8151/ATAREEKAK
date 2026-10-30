<?php

namespace App\Services\Ride;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Events\RideCancelled;
use App\Interfaces\NotificationPort;
use App\Interfaces\Payment\CancellationRefundInterface;
use App\Interfaces\Payment\CashRideFeeServiceInterface;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Services\Score\ScoreService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ride-cancellation lifecycle: lock the ride, snapshot the bookings, refund the confirmed
 * passengers and the cash creation fee, and tell the passengers and the driver.
 *
 * Split out of RideService (behaviour unchanged). Bodies moved verbatim, so the refund tiers,
 * the 95/5 split and the notification payloads are exactly as they were.
 */
final class RideCancellationService
{
    public function __construct(
        private readonly RideValidationService $validationService,
        private readonly ScoreService $scoreService,
        private readonly CancellationRefundInterface $cancellationRefund,
        private readonly CashRideFeeServiceInterface $cashRideFeeService,
        private readonly NotificationPort $notificationService,
    ) {}

    /**
     * Driver cancels their ride before it departs.
     *
     * Wallet — CONFIRMED + E-PAY bookings:
     *   Admin escrow → each passenger (100% full refund, no time-based tier).
     *
     * Wallet — always (regardless of payment method):
     *   SyCash → Driver (creation fee refunded in full).
     *
     * Score — driver:
     *   Elapsed 0–30%   → 0 pts (no penalty)
     *   Elapsed 30–50%  → −7 pts
     *   Elapsed 50–100% → −12 pts
     *   cancelRate ≥ 50% overrides tier → −15 pts always
     *   Elapsed % = (now − ride.created_at) / (departure − ride.created_at) × 100
     *
     * PENDING bookings are cancelled without financial impact
     * (REQUEST bookings were never charged).
     */
    public function cancelRide(int $rideId, User $driver): Ride
    {
        return DB::transaction(function () use ($rideId, $driver) {
            $ride = $this->lockRideForCancellation($rideId, $driver);

            $this->validationService->validateCanCancelRide(Carbon::parse($ride->departure_time));

            $snapshot = $this->snapshotRideBookings($ride);

            // Calculate elapsed % once - reused for score AND fee-refund notification.
            $elapsedPct = ScoreService::calculateElapsedPct(
                $ride->created_at,
                Carbon::parse($ride->departure_time)
            );

            // 1. Mark ride as cancelled
            $ride->status = RideStatus::CANCELLED->value;
            $ride->save();

            // 2. Cancel every active booking
            foreach ($snapshot['active'] as $booking) {
                $booking->status = BookingStatus::CANCELLED->value;
                $booking->save();
            }

            // 3. Refund confirmed E-PAY passengers (pending were never charged)
            $this->refundConfirmedPassengers($ride, $snapshot['confirmed']);

            // 4. Refund or retain the driver's cash-ride creation fee.
            $this->refundCashCreationFee($ride, $driver, $snapshot['hadActiveBookings']);

            // 5. Score penalty for driver
            $this->scoreService->recordDriverCancelRide($driver, $ride, $elapsedPct);

            // 6. Notify all affected passengers
            $this->notifyPassengersOnRideCancelled($ride, $snapshot['confirmed'], $snapshot['pending']);

            // 7. Notify driver - fee outcome is conditional on cancellation policy
            $feeKept = $ride->payment_method === PaymentMethod::CASH->value
                && $elapsedPct >= 30.0
                && $snapshot['hadActiveBookings'];

            $this->notifyDriverOfRideCancellation($ride, $driver, $snapshot['active'], $elapsedPct, $feeKept);

            broadcast(new RideCancelled($ride, $snapshot['active']->toArray(), $driver));

            Log::info('Ride cancelled by driver', [
                'ride_id' => $ride->id,
                'driver_id' => $driver->id,
                'elapsed_pct' => round($elapsedPct, 2),
                'confirmed' => $snapshot['confirmed']->count(),
                'pending' => $snapshot['pending']->count(),
                'original_seats' => $snapshot['originalSeats'],
            ]);

            return $ride->fresh();
        }, attempts: 3);
    }

    /**
     * Lock the ride and refuse a cancellation by anyone but its creator.
     *
     * RV-37 / un9: the driver is read by the refund/notify/score paths in every one of
     * these transactions - load it once, explicitly.
     */
    private function lockRideForCancellation(int $rideId, User $driver): Ride
    {
        $ride = Ride::lockForUpdate()->with('driver')->findOrFail($rideId);

        if ($ride->driver_id !== $driver->id) {
            throw new \InvalidArgumentException('Only the ride creator can cancel it');
        }

        $rideStatus = RideStatus::from($ride->status);
        if ($rideStatus->isTerminal()) {
            throw new \InvalidArgumentException(
                "Cannot cancel a ride with status: {$rideStatus->label()}"
            );
        }

        return $ride;
    }

    /**
     * Snapshot the ride's active bookings before any status changes.
     *
     * `hadActiveBookings` is taken NOW - before the cancellations below - because
     * refundCashRideCreationFee() needs to know whether passengers were present at the
     * moment of cancellation, not after the wipe. `originalSeats` reconstructs the seat
     * count the ride was created with.
     *
     * @return array{active: Collection, confirmed: Collection, pending: Collection, originalSeats: int, hadActiveBookings: bool}
     */
    private function snapshotRideBookings(Ride $ride): array
    {
        $activeBookings = $ride->bookings()
            ->whereNotIn('status', [
                BookingStatus::CANCELLED->value,
                BookingStatus::COMPLETED->value,
                Booking::NO_SHOW,
            ])
            ->with('user')
            ->get();

        $confirmedBookings = $activeBookings->filter(
            fn ($b) => $b->status === BookingStatus::CONFIRMED->value
        );
        $pendingBookings = $activeBookings->filter(
            fn ($b) => $b->status === BookingStatus::PENDING->value
        );

        return [
            'active' => $activeBookings,
            'confirmed' => $confirmedBookings,
            'pending' => $pendingBookings,
            'originalSeats' => $ride->available_seats + $confirmedBookings->sum('seats') + $pendingBookings->sum('seats'),
            'hadActiveBookings' => $activeBookings->isNotEmpty(),
        ];
    }

    /** Refund confirmed E-PAY passengers; pending bookings were never charged. */
    private function refundConfirmedPassengers(Ride $ride, Collection $confirmedBookings): void
    {
        if ($confirmedBookings->isNotEmpty()
            && $ride->payment_method === PaymentMethod::E_PAY->value
        ) {
            $this->cancellationRefund->refundPassengersForDriverCancellation($ride, $confirmedBookings);
        }
    }

    /**
     * Refund or retain the driver's cash-ride creation fee.
     *
     * Policy: elapsed < 30%                -> full refund always.
     *         elapsed >= 30% + had passengers -> platform keeps the fee.
     *         elapsed >= 30% + no passengers  -> full refund.
     */
    private function refundCashCreationFee(Ride $ride, User $driver, bool $hadActiveBookings): void
    {
        if ($ride->payment_method === PaymentMethod::CASH->value) {
            $this->cashRideFeeService->refundCashRideCreationFee($ride, $driver, $hadActiveBookings);
        }
    }

    /**
     * Tell the driver their ride is cancelled, including the creation-fee outcome.
     */
    private function notifyDriverOfRideCancellation(
        Ride $ride,
        User $driver,
        Collection $activeBookings,
        float $elapsedPct,
        bool $feeKept
    ): void {
        $feeMessage = match (true) {
            $ride->payment_method !== PaymentMethod::CASH->value => '',
            $feeKept => ' Your creation fee has been retained by the platform due to '
                .'late cancellation while passengers were booked.',
            default => ' Your creation fee has been refunded in full.',
        };

        $this->notificationService->createNotification(
            $driver,
            'ride_cancelled_driver',
            'Ride Cancelled',
            "Your ride from {$ride->pickup_address} to {$ride->destination_address} has been cancelled.{$feeMessage}",
            [
                'ride_id' => $ride->id,
                'passengers_notified' => $activeBookings->count(),
                'elapsed_pct' => round($elapsedPct, 2),
                'creation_fee_kept' => $feeKept,
            ],
            'normal', 'ride'
        );
    }

    /**
     * Notify each affected passenger when the driver cancels the entire ride.
     */
    private function notifyPassengersOnRideCancelled(
        Ride $ride,
        Collection $confirmedBookings,
        Collection $pendingBookings
    ): void {
        $isEpay = $ride->payment_method === PaymentMethod::E_PAY->value;

        foreach ($confirmedBookings as $booking) {
            $refundAmount = $booking->seats * $ride->price_per_seat;
            $detail = $isEpay
                ? 'A full refund of '.number_format($refundAmount, 0).' SYP has been issued to your wallet.'
                : 'Cash ride — no wallet transaction needed.';

            $this->notificationService->createNotification(
                $booking->user,
                'ride_cancelled_by_driver',
                'Ride Cancelled by Driver',
                "The driver cancelled the ride from {$ride->pickup_address} to {$ride->destination_address}. {$detail}",
                [
                    'ride_id' => $ride->id,
                    'booking_id' => $booking->id,
                    'refund_amount' => $isEpay ? $refundAmount : 0,
                ],
                'high', 'ride'
            );
        }

        foreach ($pendingBookings as $booking) {
            $this->notificationService->createNotification(
                $booking->user,
                'ride_cancelled_by_driver',
                'Ride Cancelled by Driver',
                "The ride from {$ride->pickup_address} to {$ride->destination_address} was cancelled. "
                .'Your pending booking request has been cancelled — no payment was taken.',
                ['ride_id' => $ride->id, 'booking_id' => $booking->id],
                'normal', 'ride'
            );
        }
    }
}
