<?php

namespace App\Modules\Ride\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Money\Contracts\CancellationRefundInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The cancellation half of BookingService: cancelling a whole booking, cancelling part of its
 * seats, and the refund/score/notification work either path needs.
 *
 * Split out of BookingService (behaviour unchanged). It owns the cancellation rules and the
 * reversal of a confirmed booking; it does not create or accept bookings. Bodies moved
 * verbatim, so the amounts, refund tiers, status flips, score actions and notification text are
 * exactly as they were.
 */
final class BookingCancellationService
{
    public function __construct(
        private readonly CancellationRefundInterface $cancellationRefund,
        private readonly NotificationPort $notificationService,
        private readonly ScoreService $scoreService,
    ) {}

    /**
     * Passenger cancels their entire booking.
     *
     * CONFIRMED + E-PAY:
     *   Admin escrow → Passenger (refund%) + Admin → Driver (non-refundable%)
     *   Tiers: 0–30% elapsed = 100% refund · 30–50% = 70% · 50–70% = 50% · 70–100% = 0%
     *
     * CONFIRMED + CASH:
     *   No wallet operation. Score penalty applied:
     *   0–30% = 0pts · 30–50% = −5pts · 50–100% = −10pts
     *   cancelRate > 50% → always −10pts regardless of tier.
     *
     * PENDING (any payment method):
     *   No wallet operation (passenger was never charged for REQUEST bookings).
     *   No score penalty.
     */
    public function cancelBooking(int $bookingId, User $passenger): Booking
    {
        return DB::transaction(function () use ($bookingId, $passenger) {
            $booking = Booking::with(['ride.driver', 'user'])->lockForUpdate()->findOrFail($bookingId);
            $ride = $booking->ride;

            $status = $this->assertBookingCancellable($booking, $passenger);

            $wasConfirmed = ($status === BookingStatus::CONFIRMED);

            $booking->status = BookingStatus::CANCELLED->value;
            $booking->save();

            // Calculate time-elapsed percentage (needed for both wallet and score logic)
            $refundPolicy = $this->cancellationRefund->calculateRefundPolicy(
                Carbon::parse($ride->departure_time),
                $booking->created_at
            );

            if ($wasConfirmed) {
                $this->reverseConfirmedBooking($booking, $ride, $passenger, $refundPolicy);
            }

            $this->notifyCancellation($booking, $ride, $booking->seats, $refundPolicy, $wasConfirmed);

            Log::info('Booking cancelled by passenger', [
                'booking_id' => $booking->id,
                'passenger_id' => $passenger->id,
                'was_confirmed' => $wasConfirmed,
                'payment' => $ride->payment_method,
                'elapsed_pct' => $refundPolicy['time_elapsed_percentage'],
            ]);

            // RV-37 / un9: `refresh()` discards every loaded relation, so BookingResource's reads
            // (`$booking->user`, `$booking->ride->driver`) were always lazy - one query each, per
            // booking. Re-load the relations the response path needs. Stage 2 C2: the profile relations
            // are NOT here any more; the handler resolves the photo paths from Identity's port.
            return $booking->refresh()->load(['user', 'ride.driver']);
        }, attempts: 3);
    }

    /**
     * Passenger cancels a subset of their booked seats.
     * Same wallet/score rules as cancelBooking() applied per cancelled seat.
     * Booking stays active with reduced seat count unless all seats are cancelled.
     */
    public function cancelPartialSeats(int $bookingId, int $seatsToCancel, User $passenger): array
    {
        return DB::transaction(function () use ($bookingId, $seatsToCancel, $passenger) {
            $booking = Booking::with(['ride.driver', 'user'])->lockForUpdate()->findOrFail($bookingId);
            $ride = $booking->ride;

            $this->assertPartialCancellationAllowed($booking, $passenger, $seatsToCancel);

            $wasConfirmed = ($booking->status === BookingStatus::CONFIRMED->value);
            $remainingSeats = $booking->seats - $seatsToCancel;

            $refundPolicy = $this->cancellationRefund->calculateRefundPolicy(
                Carbon::parse($ride->departure_time),
                $booking->created_at
            );
            $totalPaid = $seatsToCancel * $ride->price_per_seat;
            $refundAmount = ($totalPaid * $refundPolicy['refund_percentage']) / 100;

            if ($wasConfirmed) {
                $this->refundPartialSeats($booking, $ride, $passenger, $seatsToCancel, $refundPolicy);
            }

            $message = $this->applyPartialSeatReduction($booking, $seatsToCancel, $remainingSeats);

            // Restore seats on ride (only for confirmed - pending seats were never deducted)
            if ($wasConfirmed) {
                $this->restoreRideSeats($ride, $seatsToCancel);
            }

            $this->notifyCancellation($booking, $ride, $seatsToCancel, $refundPolicy, $wasConfirmed);

            Log::info('Partial seats cancelled', [
                'booking_id' => $booking->id,
                'seats_cancelled' => $seatsToCancel,
                'remaining' => $remainingSeats,
            ]);

            return $this->partialCancellationResponse(
                $booking,
                $message,
                $seatsToCancel,
                $remainingSeats,
                $refundPolicy,
                $totalPaid,
                $refundAmount
            );
        }, attempts: 3);
    }

    /** Refuse a cancellation that is not this passenger's, or that the status forbids. */
    private function assertBookingCancellable(Booking $booking, User $passenger): BookingStatus
    {
        if ($booking->user_id !== $passenger->id) {
            throw new \InvalidArgumentException('You can only cancel your own bookings');
        }

        $status = BookingStatus::from($booking->status);
        if (! $status->canBeCancelled()) {
            throw new \InvalidArgumentException(
                "Cannot cancel a booking with status: {$status->label()}"
            );
        }

        return $status;
    }

    /**
     * Undo a confirmed booking: refund the E-PAY escrow, apply the score penalty and
     * put the seats back on the ride (re-opening a FULL ride as ACTIVE).
     */
    private function reverseConfirmedBooking(
        Booking $booking,
        Ride $ride,
        User $passenger,
        array $refundPolicy
    ): void {
        // Wallet refund - E-PAY confirmed bookings only
        if ($ride->payment_method === PaymentMethod::E_PAY->value) {
            $this->cancellationRefund->processTimeBasedCancellation(
                $booking, $ride, $booking->seats, $refundPolicy
            );
        }

        // Score penalty - CASH confirmed bookings only
        $this->scoreService->recordPassengerCancel(
            $passenger,
            $booking,
            $refundPolicy['time_elapsed_percentage'],
            $ride->payment_method
        );

        // Restore seats on the ride
        $ride->increment('available_seats', $booking->seats);
        $ride->refresh();

        if ($ride->status === RideStatus::FULL->value) {
            $ride->update(['status' => RideStatus::ACTIVE->value]);
        }
    }

    /**
     * Send cancellation/partial-cancel notifications to both driver and passenger.
     */
    private function notifyCancellation(
        Booking $booking,
        Ride $ride,
        int $seatsCancelled,
        array $refundPolicy,
        bool $wasConfirmed
    ): void {
        $totalPaid = $seatsCancelled * $ride->price_per_seat;
        $refundAmount = ($totalPaid * $refundPolicy['refund_percentage']) / 100;
        $driverAmount = $totalPaid - $refundAmount;
        $isEpay = $ride->payment_method === PaymentMethod::E_PAY->value;

        // Passenger message
        if ($wasConfirmed && $isEpay) {
            $passengerDetail = $refundAmount > 0
                ? 'Refund of '.number_format($refundAmount, 0)." SYP ({$refundPolicy['refund_percentage']}%) issued. ({$refundPolicy['policy_tier']})"
                : "No refund — {$refundPolicy['policy_tier']}.";
        } elseif ($wasConfirmed) {
            $passengerDetail = 'Cash ride — no wallet transaction needed.';
        } else {
            $passengerDetail = 'Pending request cancelled — no payment was taken.';
        }

        $this->notificationService->createNotification(
            $booking->user,
            'booking_cancelled',
            'Booking Cancelled',
            "Cancelled {$seatsCancelled} seat(s) on the ride from {$ride->pickup_address} "
            ."to {$ride->destination_address}. {$passengerDetail}",
            [
                'booking_id' => $booking->id,
                'ride_id' => $ride->id,
                'seats_cancelled' => $seatsCancelled,
            ],
            'normal', 'ride'
        );

        // Driver message — only meaningful if booking was confirmed
        if ($wasConfirmed) {
            $driverDetail = ($isEpay && $driverAmount > 0)
                ? "{$booking->user->first_name} cancelled {$seatsCancelled} seat(s). You received "
                .number_format($driverAmount, 0)." SYP cancellation fee ({$refundPolicy['policy_tier']})."
                : "{$booking->user->first_name} cancelled {$seatsCancelled} seat(s) (cash ride — no wallet impact).";

            $this->notificationService->createNotification(
                $ride->driver,
                'passenger_cancelled',
                'Passenger Cancelled Seats',
                $driverDetail,
                [
                    'booking_id' => $booking->id,
                    'ride_id' => $ride->id,
                    'seats_cancelled' => $seatsCancelled,
                ],
                'normal', 'ride'
            );
        }
    }

    /** The partial-cancellation guards: own booking, cancellable status, seat range. */
    private function assertPartialCancellationAllowed(Booking $booking, User $passenger, int $seatsToCancel): void
    {
        if ($booking->user_id !== $passenger->id) {
            throw new \InvalidArgumentException('You can only cancel your own bookings');
        }
        if (! in_array($booking->status, [BookingStatus::PENDING->value, BookingStatus::CONFIRMED->value])) {
            throw new \InvalidArgumentException('This booking cannot be partially cancelled');
        }
        if ($seatsToCancel < 1 || $seatsToCancel > $booking->seats) {
            throw new \InvalidArgumentException(
                "Cannot cancel {$seatsToCancel} seat(s). You have {$booking->seats} seat(s) booked."
            );
        }
    }

    /**
     * Refund the cancelled seats and apply the score rule for a confirmed booking.
     *
     * Wallet refund - E-PAY only. Score penalty - CASH only (the score service decides
     * from the payment method).
     */
    private function refundPartialSeats(
        Booking $booking,
        Ride $ride,
        User $passenger,
        int $seatsToCancel,
        array $refundPolicy
    ): void {
        if ($ride->payment_method === PaymentMethod::E_PAY->value) {
            $this->cancellationRefund->processTimeBasedCancellation(
                $booking, $ride, $seatsToCancel, $refundPolicy
            );
        }

        $this->scoreService->recordPassengerCancel(
            $passenger,
            $booking,
            $refundPolicy['time_elapsed_percentage'],
            $ride->payment_method
        );
    }

    /** Reduce the booking's seats, closing it entirely when none remain. */
    private function applyPartialSeatReduction(Booking $booking, int $seatsToCancel, int $remainingSeats): string
    {
        if ($remainingSeats > 0) {
            $booking->seats = $remainingSeats;
            $booking->save();

            return "Cancelled {$seatsToCancel} seat(s). You still have {$remainingSeats} seat(s) booked.";
        }

        $booking->seats = 0;
        $booking->status = BookingStatus::CANCELLED->value;
        $booking->save();

        return 'All seats cancelled. Your booking has been fully cancelled.';
    }

    /** Put the cancelled seats back on the ride and re-open a FULL ride. */
    private function restoreRideSeats(Ride $ride, int $seatsToCancel): void
    {
        $ride->increment('available_seats', $seatsToCancel);
        $ride->refresh();

        if ($ride->status === RideStatus::FULL->value) {
            $ride->update(['status' => RideStatus::ACTIVE->value]);
        }
    }

    /**
     * The partial-cancellation payload, including the refund breakdown for the seats.
     *
     * @return array{message: string, data: array<string, mixed>}
     */
    private function partialCancellationResponse(
        Booking $booking,
        string $message,
        int $seatsToCancel,
        int $remainingSeats,
        array $refundPolicy,
        int $totalPaid,
        float $refundAmount
    ): array {
        return [
            'message' => $message,
            'data' => [
                'booking_id' => $booking->id,
                'seats_cancelled' => $seatsToCancel,
                'remaining_seats' => $remainingSeats,
                'booking_status' => $booking->status,
                'refund_policy' => [
                    'refund_percentage' => $refundPolicy['refund_percentage'],
                    'refund_amount' => $refundAmount,
                    'non_refundable_amount' => $totalPaid - $refundAmount,
                    'time_elapsed_percentage' => round($refundPolicy['time_elapsed_percentage'], 2),
                    'policy_tier' => $refundPolicy['policy_tier'],
                ],
            ],
        ];
    }
}
