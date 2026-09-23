<?php

namespace App\Services\Ride;

use App\Domain\Payment\Strategies\PaymentStrategyFactory;
use App\DTOs\Ride\BookRideDTO;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Enums\ScoreAction;
use App\Events\RideBooked;
use App\Interfaces\RideRepositoryInterface;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Payment\WalletTransactionService;
use App\Services\Score\ScoreService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class BookingService
{
    public function __construct(
        private readonly RideRepositoryInterface  $rideRepository,
        private readonly WalletTransactionService $walletService,
        private readonly NotificationService      $notificationService,
        private readonly RideValidationService    $validationService,
        private readonly ScoreService             $scoreService,
        private readonly PaymentStrategyFactory   $paymentFactory,   // ← NEW
    ) {}


    // =========================================================================
    // BOOK RIDE
    // =========================================================================

    /**
     * Create a booking for a ride.
     *
     * Payment flow — DIRECT + E-PAY:
     *   Passenger wallet → Primary Admin escrow (immediately).
     *
     * Payment flow — REQUEST + E-PAY:
     *   Deferred — charged only when driver calls acceptBooking().
     *
     * Payment flow — CASH (any booking type):
     *   No wallet operations. Payment collected offline at ride time.
     *
     * Score gate: passenger score must be ≥ 40 (validated in RideValidationService).
     */
    public function bookRide(BookRideDTO $dto, User $passenger): \Illuminate\Database\Eloquent\Builder|array|Collection|\Illuminate\Database\Eloquent\Model
    {
        // 1. Validate passenger (verified + score gate ≥ 40)
        $this->validationService->validatePassengerCanBook($passenger);

        // 2. Idempotency — return existing booking if the same key is replayed
        $cacheKey = "booking:idem:{$dto->idempotencyKey}";
        if ($existingId = Cache::get($cacheKey)) {
            Log::info('Duplicate booking request detected', [
                'idempotency_key'     => $dto->idempotencyKey,
                'existing_booking_id' => $existingId,
            ]);
            return Booking::with(['ride', 'user'])->findOrFail($existingId);
        }

        return DB::transaction(function () use ($dto, $passenger, $cacheKey) {
            // 3. Load and lock ride row to prevent race conditions on seat count
            $ride = Ride::lockForUpdate()->findOrFail($dto->rideId);

            // 4. Business rule validations
            $this->assertBookingRules($dto, $ride, $passenger);

            // 5. Determine initial booking status from the ride's booking type
            $bookingType   = BookingType::from($ride->booking_type);
            $initialStatus = $bookingType->initialBookingStatus(); // CONFIRMED or PENDING

            // 6. Create the booking record
            $booking = Booking::create([
                'user_id'              => $dto->passengerId,
                'ride_id'              => $dto->rideId,
                'seats'                => $dto->seats,
                'status'               => $initialStatus->value,
                'communication_number' => $dto->communicationNumber->number(),
            ]);

            // 7. Charge passenger for DIRECT + E-PAY only.
            //    REQUEST bookings defer payment until driver accepts.
            if ($initialStatus === BookingStatus::CONFIRMED
                && $ride->payment_method === PaymentMethod::E_PAY->value
            ) {
                $this->walletService->chargePassengerForBooking($booking, $ride, $passenger);
            }

            // 8. Deduct seats from ride only when booking is immediately confirmed
            if ($initialStatus === BookingStatus::CONFIRMED) {
                $this->deductSeats($ride, $dto->seats);
            }

            // 9. Cache idempotency key for 24 hours
            Cache::put($cacheKey, $booking->id, 86400);

            // 10. Notify driver and passenger
            $this->notifyOnBookingCreated($booking, $ride, $passenger, $bookingType);

            // 11. Broadcast real-time event to all listeners
            broadcast(new RideBooked($ride, $booking, $passenger));

            Log::info('Ride booked successfully', [
                'ride_id'        => $ride->id,
                'booking_id'     => $booking->id,
                'passenger_id'   => $passenger->id,
                'status'         => $initialStatus->value,
                'payment_method' => $ride->payment_method,
            ]);

            return $booking->refresh();
        });
    }

    // =========================================================================
    // ACCEPT BOOKING  (driver — REQUEST-type rides only)
    // =========================================================================

    /**
     * Driver approves a pending booking request.
     *
     * Payment flow (E-PAY): Passenger wallet → Admin escrow (deferred from book time).
     * Payment flow (CASH): No wallet operation.
     */
    public function acceptBooking(int $bookingId, User $driver): Booking
    {
        return DB::transaction(function () use ($bookingId, $driver) {
            $booking = Booking::with(['ride', 'user'])->lockForUpdate()->findOrFail($bookingId);
            $ride    = $booking->ride;

            if ($ride->driver_id !== $driver->id) {
                throw new \InvalidArgumentException('Only the ride driver can accept bookings');
            }
            if ($ride->booking_type !== BookingType::REQUEST->value) {
                throw new \InvalidArgumentException('Only request-type bookings can be accepted');
            }
            if ($booking->status !== BookingStatus::PENDING->value) {
                throw new \InvalidArgumentException('Only pending bookings can be accepted');
            }

            // Re-check seats — availability may have changed since the request was made
            $this->validationService->validateSeatsAvailable($booking->seats, $ride->available_seats);

            $booking->status = BookingStatus::CONFIRMED->value;
            $booking->save();

            // E-PAY: charge passenger now that driver accepted
            if ($ride->payment_method === PaymentMethod::E_PAY->value) {
                $this->walletService->chargePassengerForBooking($booking, $ride, $booking->user);
            }

            $this->deductSeats($ride, $booking->seats);

            $paymentNote = $ride->payment_method === PaymentMethod::E_PAY->value
                ? ' Payment has been deducted from your wallet.'
                : ' Please pay the driver in cash.';

            $this->notificationService->createNotification(
                $booking->user,
                'booking_accepted',
                'Booking Accepted ✓',
                "{$driver->first_name} {$driver->last_name} accepted your request for {$booking->seats} seat(s).{$paymentNote}",
                ['booking_id' => $booking->id, 'ride_id' => $ride->id],
                'high', 'ride'
            );

            Log::info('Booking accepted by driver', [
                'booking_id' => $booking->id,
                'driver_id'  => $driver->id,
            ]);

            return $booking->refresh();
        });
    }

    // =========================================================================
    // REJECT BOOKING  (driver — REQUEST-type rides only)
    // =========================================================================

    /**
     * Driver rejects a pending booking request.
     * No wallet operation — passenger was never charged for REQUEST bookings.
     * No score penalty — rejection is within the driver's rights.
     */
    public function rejectBooking(int $bookingId, User $driver): Booking
    {
        return DB::transaction(function () use ($bookingId, $driver) {
            $booking = Booking::with(['ride', 'user'])->lockForUpdate()->findOrFail($bookingId);
            $ride    = $booking->ride;

            if ($ride->driver_id !== $driver->id) {
                throw new \InvalidArgumentException('Only the ride driver can reject bookings');
            }
            if ($ride->booking_type !== BookingType::REQUEST->value) {
                throw new \InvalidArgumentException('Only request-type bookings can be rejected');
            }
            if ($booking->status !== BookingStatus::PENDING->value) {
                throw new \InvalidArgumentException('Only pending bookings can be rejected');
            }

            $booking->status = BookingStatus::CANCELLED->value;
            $booking->save();

            $this->notificationService->createNotification(
                $booking->user,
                'booking_rejected',
                'Booking Request Declined',
                "Your request for {$booking->seats} seat(s) on the ride from "
                . "{$ride->pickup_address} to {$ride->destination_address} was declined by the driver.",
                ['booking_id' => $booking->id, 'ride_id' => $ride->id],
                'normal', 'ride'
            );

            Log::info('Booking rejected by driver', [
                'booking_id' => $booking->id,
                'driver_id'  => $driver->id,
            ]);

            return $booking->refresh();
        });
    }

    // =========================================================================
    // CANCEL BOOKING  (passenger — full booking)
    // =========================================================================

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
            $booking = Booking::with(['ride', 'user'])->lockForUpdate()->findOrFail($bookingId);
            $ride    = $booking->ride;

            if ($booking->user_id !== $passenger->id) {
                throw new \InvalidArgumentException('You can only cancel your own bookings');
            }

            $status = BookingStatus::from($booking->status);
            if (!$status->canBeCancelled()) {
                throw new \InvalidArgumentException(
                    "Cannot cancel a booking with status: {$status->label()}"
                );
            }

            $wasConfirmed = ($status === BookingStatus::CONFIRMED);

            $booking->status = BookingStatus::CANCELLED->value;
            $booking->save();

            // Calculate time-elapsed percentage (needed for both wallet and score logic)
            $refundPolicy = $this->walletService->calculateRefundPolicy(
                Carbon::parse($ride->departure_time),
                $booking->created_at
            );

            if ($wasConfirmed) {
                // Wallet refund — E-PAY confirmed bookings only
                if ($ride->payment_method === PaymentMethod::E_PAY->value) {
                    $this->walletService->processTimeBasedCancellation(
                        $booking, $ride, $booking->seats, $refundPolicy
                    );
                }

                // Score penalty — CASH confirmed bookings only
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

            $this->notifyCancellation($booking, $ride, $booking->seats, $refundPolicy, $wasConfirmed);

            Log::info('Booking cancelled by passenger', [
                'booking_id'    => $booking->id,
                'passenger_id'  => $passenger->id,
                'was_confirmed' => $wasConfirmed,
                'payment'       => $ride->payment_method,
                'elapsed_pct'   => $refundPolicy['time_elapsed_percentage'],
            ]);

            return $booking->refresh();
        });
    }

    // =========================================================================
    // CANCEL PARTIAL SEATS  (passenger)
    // =========================================================================

    /**
     * Passenger cancels a subset of their booked seats.
     * Same wallet/score rules as cancelBooking() applied per cancelled seat.
     * Booking stays active with reduced seat count unless all seats are cancelled.
     */
    public function cancelPartialSeats(int $bookingId, int $seatsToCancel, User $passenger): array
    {
        return DB::transaction(function () use ($bookingId, $seatsToCancel, $passenger) {
            $booking = Booking::with(['ride', 'user'])->lockForUpdate()->findOrFail($bookingId);
            $ride    = $booking->ride;

            if ($booking->user_id !== $passenger->id) {
                throw new \InvalidArgumentException('You can only cancel your own bookings');
            }
            if (!in_array($booking->status, [BookingStatus::PENDING->value, BookingStatus::CONFIRMED->value])) {
                throw new \InvalidArgumentException('This booking cannot be partially cancelled');
            }
            if ($seatsToCancel < 1 || $seatsToCancel > $booking->seats) {
                throw new \InvalidArgumentException(
                    "Cannot cancel {$seatsToCancel} seat(s). You have {$booking->seats} seat(s) booked."
                );
            }

            $wasConfirmed   = ($booking->status === BookingStatus::CONFIRMED->value);
            $remainingSeats = $booking->seats - $seatsToCancel;

            $refundPolicy = $this->walletService->calculateRefundPolicy(
                Carbon::parse($ride->departure_time),
                $booking->created_at
            );
            $totalPaid    = $seatsToCancel * $ride->price_per_seat;
            $refundAmount = ($totalPaid * $refundPolicy['refund_percentage']) / 100;

            if ($wasConfirmed) {
                // Wallet — E-PAY only
                if ($ride->payment_method === PaymentMethod::E_PAY->value) {
                    $this->walletService->processTimeBasedCancellation(
                        $booking, $ride, $seatsToCancel, $refundPolicy
                    );
                }

                // Score — CASH only
                $this->scoreService->recordPassengerCancel(
                    $passenger,
                    $booking,
                    $refundPolicy['time_elapsed_percentage'],
                    $ride->payment_method
                );
            }

            // Update booking
            if ($remainingSeats > 0) {
                $booking->seats = $remainingSeats;
                $booking->save();
                $message = "Cancelled {$seatsToCancel} seat(s). You still have {$remainingSeats} seat(s) booked.";
            } else {
                $booking->seats  = 0;
                $booking->status = BookingStatus::CANCELLED->value;
                $booking->save();
                $message = 'All seats cancelled. Your booking has been fully cancelled.';
            }

            // Restore seats on ride (only for confirmed — pending seats were never deducted)
            if ($wasConfirmed) {
                $ride->increment('available_seats', $seatsToCancel);
                $ride->refresh();
                if ($ride->status === RideStatus::FULL->value) {
                    $ride->update(['status' => RideStatus::ACTIVE->value]);
                }
            }

            $this->notifyCancellation($booking, $ride, $seatsToCancel, $refundPolicy, $wasConfirmed);

            Log::info('Partial seats cancelled', [
                'booking_id'      => $booking->id,
                'seats_cancelled' => $seatsToCancel,
                'remaining'       => $remainingSeats,
            ]);

            return [
                'message' => $message,
                'data'    => [
                    'booking_id'      => $booking->id,
                    'seats_cancelled' => $seatsToCancel,
                    'remaining_seats' => $remainingSeats,
                    'booking_status'  => $booking->status,
                    'refund_policy'   => [
                        'refund_percentage'       => $refundPolicy['refund_percentage'],
                        'refund_amount'           => $refundAmount,
                        'non_refundable_amount'   => $totalPaid - $refundAmount,
                        'time_elapsed_percentage' => round($refundPolicy['time_elapsed_percentage'], 2),
                        'policy_tier'             => $refundPolicy['policy_tier'],
                    ],
                ],
            ];
        });
    }

    // =========================================================================
    // REPORT PASSENGER NO-SHOW  (driver reports)
    // =========================================================================

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
        return app(\App\Services\Ride\NoshowService::class)
            ->reportPassengerNoShow($bookingId, $driver);
    }

    // =========================================================================
    // PASSENGER CONFIRMS RIDE COMPLETION
    // =========================================================================

    /**
     * Passenger confirms the ride actually took place.
     * Once the driver AND all confirmed passengers have confirmed,
     * RideService::checkAndCompleteRide() releases payment and records scores.
     */

    public function passengerConfirmCompletion(int $bookingId, User $passenger): array
    {
        return DB::transaction(function () use ($bookingId, $passenger) {

            $booking = Booking::lockForUpdate()->findOrFail($bookingId);

            if ((int) $booking->user_id !== (int) $passenger->id) {
                throw new \InvalidArgumentException('You can only confirm your own bookings.');
            }

            if ($booking->status !== BookingStatus::CONFIRMED->value) {
                $msg = match ($booking->status) {
                    BookingStatus::COMPLETED->value => 'You have already confirmed this ride.',
                    BookingStatus::CANCELLED->value => 'This booking has been cancelled.',
                    'no_show'                       => 'This booking was marked as a no-show.',
                    default                         => 'This booking cannot be confirmed in its current state.',
                };
                throw new \InvalidArgumentException($msg);
            }

            // ── NO-SHOW MUTUAL EXCLUSION ──────────────────────────────────────────
            // A passenger who filed a driver-no-show report cannot simultaneously
            // confirm the ride. The two actions are mutually exclusive.
            $hasPendingNoShow = \App\Models\NoshowReport::where('ride_id', $booking->ride_id)
                ->where('reporter_id', $passenger->id)
                ->where('reporter_role', 'passenger')
                ->whereIn('status', ['pending', 'disputed'])
                ->exists();

            if ($hasPendingNoShow) {
                throw new \InvalidArgumentException(
                    'You have an active no-show report for this ride. You cannot confirm and dispute simultaneously.'
                );
            }
            // ─────────────────────────────────────────────────────────────────────

            $ride = Ride::lockForUpdate()->findOrFail($booking->ride_id);

            if (now()->lt($ride->departure_time)) {
                throw new \InvalidArgumentException(
                    'The ride has not departed yet. Confirmation opens after the departure time.'
                );
            }

            if (in_array($ride->status, [RideStatus::ACTIVE->value, RideStatus::FULL->value])) {
                $ride->status = RideStatus::LAUNCHED->value;
                $ride->save();
            } elseif ($ride->status !== RideStatus::LAUNCHED->value) {
                throw new \InvalidArgumentException(
                    "This ride cannot be confirmed (current status: {$ride->status})."
                );
            }

            $booking->update([
                'status'       => BookingStatus::COMPLETED->value,
                'completed_at' => now(),
            ]);

            $strategy      = $this->paymentFactory->make($ride->payment_method);
            $paymentResult = $strategy->processRideCompletionPayment($booking, $ride, $passenger);

            if (!$paymentResult->success) {
                throw new \RuntimeException('Payment release failed: ' . $paymentResult->message);
            }

            $this->scoreService->applyAction(
                user:      $passenger,
                action:    ScoreAction::RIDE_COMPLETED,
                reference: $booking,
            );

            $unresolvedCount = Booking::where('ride_id', $ride->id)
                ->whereNotIn('status', [
                    BookingStatus::COMPLETED->value,
                    BookingStatus::CANCELLED->value,
                    'no_show',
                ])
                ->count();

            $rideNowFinished = ($unresolvedCount === 0);

            if ($rideNowFinished) {
                $ride->status = RideStatus::FINISHED->value;
                $ride->save();

                $driver = User::find($ride->driver_id);
                if ($driver) {
                    $this->scoreService->applyAction(
                        user:      $driver,
                        action:    ScoreAction::RIDE_COMPLETED,
                        reference: $ride,
                    );
                }
            }

            return [
                'message'       => $rideNowFinished
                    ? 'Confirmed. All passengers done — ride is now finished.'
                    : 'Confirmed successfully.',
                'ride_finished' => $rideNowFinished,
            ];
        });
    }


    // =========================================================================
    // GETTERS
    // =========================================================================

    public function getUserBookings(int $userId): Collection
    {
        return Booking::with([
            'ride',
            'ride.driver',
            'ride.driver.profile',
        ])
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Run all business rule checks before creating a booking.
     *
     * @throws \InvalidArgumentException on any violation
     */
    private function assertBookingRules(BookRideDTO $dto, Ride $ride, User $passenger): void
    {
        // Driver cannot book their own ride
        if ($ride->driver_id === $passenger->id) {
            throw new \InvalidArgumentException('Drivers cannot book their own rides');
        }

        // Ride must be in a bookable state (ACTIVE or FULL with available seats)
        $rideStatus = RideStatus::from($ride->status);
        if (!$rideStatus->canBeBooked()) {
            throw new \InvalidArgumentException(
                "This ride is not available for booking (status: {$rideStatus->label()})"
            );
        }

        // Sufficient seats must be available
        $this->validationService->validateSeatsAvailable($dto->seats, $ride->available_seats);

        // Passenger must not already have an active booking on this ride
        $alreadyBooked = Booking::where('user_id', $passenger->id)
            ->where('ride_id', $ride->id)
            ->whereIn('status', BookingStatus::activeStatuses())
            ->exists();

        if ($alreadyBooked) {
            throw new \InvalidArgumentException('You already have an active booking for this ride');
        }
    }

    /**
     * Decrement available_seats on the ride and mark it FULL if none remain.
     */
    private function deductSeats(Ride $ride, int $seats): void
    {
        $ride->decrement('available_seats', $seats);
        $ride->refresh();

        if ($ride->available_seats <= 0) {
            $ride->update(['status' => RideStatus::FULL->value]);
            Log::info('Ride marked as full', ['ride_id' => $ride->id]);
        }
    }

    /**
     * Send creation notifications to both driver and passenger.
     */
    private function notifyOnBookingCreated(
        Booking     $booking,
        Ride        $ride,
        User        $passenger,
        BookingType $bookingType
    ): void {
        $isDirect = $bookingType === BookingType::DIRECT;

        // Notify driver
        $this->notificationService->createNotification(
            $ride->driver,
            $isDirect ? 'ride_booked' : 'booking_requested',
            $isDirect ? 'New Booking Received' : 'New Booking Request',
            $isDirect
                ? "{$passenger->first_name} {$passenger->last_name} booked {$booking->seats} seat(s) on your ride."
                : "{$passenger->first_name} {$passenger->last_name} requested {$booking->seats} seat(s). Please accept or reject.",
            [
                'ride_id'      => $ride->id,
                'booking_id'   => $booking->id,
                'passenger_id' => $passenger->id,
                'seats'        => $booking->seats,
            ],
            'high', 'ride'
        );

        // Notify passenger
        $this->notificationService->createNotification(
            $passenger,
            $isDirect ? 'booking_confirmed' : 'booking_request_sent',
            $isDirect ? 'Booking Confirmed ✓' : 'Request Sent',
            $isDirect
                ? "Your {$booking->seats} seat(s) on the ride from {$ride->pickup_address} to {$ride->destination_address} are confirmed."
                : "Your request for {$booking->seats} seat(s) has been sent. Waiting for driver approval.",
            ['ride_id' => $ride->id, 'booking_id' => $booking->id, 'seats' => $booking->seats],
            'normal', 'ride'
        );
    }

    /**
     * Send cancellation/partial-cancel notifications to both driver and passenger.
     */
    private function notifyCancellation(
        Booking $booking,
        Ride    $ride,
        int     $seatsCancelled,
        array   $refundPolicy,
        bool    $wasConfirmed
    ): void {
        $totalPaid    = $seatsCancelled * $ride->price_per_seat;
        $refundAmount = ($totalPaid * $refundPolicy['refund_percentage']) / 100;
        $driverAmount = $totalPaid - $refundAmount;
        $isEpay       = $ride->payment_method === PaymentMethod::E_PAY->value;

        // Passenger message
        if ($wasConfirmed && $isEpay) {
            $passengerDetail = $refundAmount > 0
                ? "Refund of " . number_format($refundAmount, 0) . " SYP ({$refundPolicy['refund_percentage']}%) issued. ({$refundPolicy['policy_tier']})"
                : "No refund — {$refundPolicy['policy_tier']}.";
        } elseif ($wasConfirmed) {
            $passengerDetail = "Cash ride — no wallet transaction needed.";
        } else {
            $passengerDetail = "Pending request cancelled — no payment was taken.";
        }

        $this->notificationService->createNotification(
            $booking->user,
            'booking_cancelled',
            'Booking Cancelled',
            "Cancelled {$seatsCancelled} seat(s) on the ride from {$ride->pickup_address} "
            . "to {$ride->destination_address}. {$passengerDetail}",
            [
                'booking_id'      => $booking->id,
                'ride_id'         => $ride->id,
                'seats_cancelled' => $seatsCancelled,
            ],
            'normal', 'ride'
        );

        // Driver message — only meaningful if booking was confirmed
        if ($wasConfirmed) {
            $driverDetail = ($isEpay && $driverAmount > 0)
                ? "{$booking->user->first_name} cancelled {$seatsCancelled} seat(s). You received "
                . number_format($driverAmount, 0) . " SYP cancellation fee ({$refundPolicy['policy_tier']})."
                : "{$booking->user->first_name} cancelled {$seatsCancelled} seat(s) (cash ride — no wallet impact).";

            $this->notificationService->createNotification(
                $ride->driver,
                'passenger_cancelled',
                'Passenger Cancelled Seats',
                $driverDetail,
                [
                    'booking_id'      => $booking->id,
                    'ride_id'         => $ride->id,
                    'seats_cancelled' => $seatsCancelled,
                ],
                'normal', 'ride'
            );
        }
    }
}
