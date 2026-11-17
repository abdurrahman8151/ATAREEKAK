<?php

namespace App\Modules\Ride\Services;

use App\DTOs\Ride\BookRideDTO;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Modules\Ride\Models\Booking;
use App\Modules\Ride\Models\Ride;
use App\Models\User;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Identity\Contracts\ProfileDirectory;
use App\Modules\Money\Contracts\CancellationRefundInterface;
use App\Modules\Money\Contracts\PaymentStrategyFactoryInterface;
use App\Modules\Ride\Contracts\RideRepositoryInterface;
use App\Modules\Ride\Events\RideBooked;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class BookingService
{
    private readonly BookingCancellationService $cancellations;

    private readonly BookingCompletionService $completions;

    public function __construct(
        private readonly RideRepositoryInterface $rideRepository,
        private readonly CancellationRefundInterface $cancellationRefund,
        private readonly NotificationPort $notificationService,
        private readonly RideValidationService $validationService,
        private readonly ScoreService $scoreService,
        private readonly Noshowservice $noshowService,
        private readonly PaymentStrategyFactoryInterface $paymentFactory,   // ← NEW
    ) {
        $this->cancellations = new BookingCancellationService($cancellationRefund, $notificationService, $scoreService);
        $this->completions = new BookingCompletionService($paymentFactory, $scoreService, $noshowService);
    }

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
    public function bookRide(BookRideDTO $dto, User $passenger): Builder|array|Collection|Model
    {
        // 1. Validate passenger (verified + score gate ≥ 40)
        $this->validationService->validatePassengerCanBook($passenger);

        return DB::transaction(function () use ($dto, $passenger) {
            // 2. Idempotency — DB-backed, scoped to THIS user, INSIDE the transaction (RV-15/RV-40).
            if ($replay = $this->findReplayedBooking($dto, $passenger)) {
                return $replay;
            }
            // 3. Load and lock ride row to prevent race conditions on seat count.
            // RV-37 / un9: eager-load `driver` here rather than lazily at the
            // notification/cash-fee call sites below — those read `$ride->driver` on a booking that
            // is about to be notified, which is one query per passenger booking today and an N+1
            // under the armed lazy-loading guard.
            // Stage 2 C2: `driver.profile` is GONE - the response's driver photo comes from Identity's
            // batched port read in the handler that renders BookingResource.
            $ride = Ride::lockForUpdate()->with(['driver'])->findOrFail($dto->rideId);

            // 4. Business rule validations
            $this->assertBookingRules($dto, $ride, $passenger);

            // 5. Determine initial booking status from the ride's booking type
            $bookingType = BookingType::from($ride->booking_type);
            $initialStatus = $bookingType->initialBookingStatus(); // CONFIRMED or PENDING

            // 6. Create the booking record (carrying the RV-40 idempotency key), then
            //    settle it at once when the booking type confirms without driver
            //    approval. A REQUEST booking is charged later, in acceptBooking().
            $booking = $this->createBookingRecord($dto, $initialStatus);

            if ($initialStatus === BookingStatus::CONFIRMED) {
                $this->settleConfirmedBooking($booking, $ride, $passenger, $dto->seats);
            }

            // 9. Notify driver and passenger
            $this->notifyOnBookingCreated($booking, $ride, $passenger, $bookingType);

            // 10. Broadcast real-time event to all listeners
            broadcast(new RideBooked($ride, $booking, $passenger));

            $this->logBookingCreated($booking, $ride, $passenger, $initialStatus);

            // RV-37 / un9: `refresh()` discards every loaded relation, so BookingResource's reads
            // (`$booking->user`, `$booking->ride->driver`) were always lazy - one query each, per
            // booking. Re-load the relations the response path needs. Stage 2 C2: the profile relations
            // are NOT here any more; the handler resolves the photo paths from Identity's port.
            return $booking->refresh()->load(['user', 'ride.driver']);
        }, attempts: 3);
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
            $booking = Booking::with(['ride.driver', 'user'])->lockForUpdate()->findOrFail($bookingId);
            $ride = $booking->ride;

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

            // Charge the passenger now that the driver accepted (RV-20: through the strategy,
            // for the same reason as site 7 - see the comment there).
            $paymentResult = $this->paymentFactory->make($ride->payment_method)
                ->processBookingPayment($booking, $ride, $booking->user);

            if (! $paymentResult->success) {
                throw new \RuntimeException('Booking payment failed: '.$paymentResult->message);
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
                'driver_id' => $driver->id,
            ]);

            // RV-37 / un9: `refresh()` discards every loaded relation, so BookingResource's reads
            // (`$booking->user`, `$booking->ride->driver`) were always lazy - one query each, per
            // booking. Re-load the relations the response path needs. Stage 2 C2: the profile relations
            // are NOT here any more; the handler resolves the photo paths from Identity's port.
            return $booking->refresh()->load(['user', 'ride.driver']);
        }, attempts: 3);
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
            $booking = Booking::with(['ride.driver', 'user'])->lockForUpdate()->findOrFail($bookingId);
            $ride = $booking->ride;

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
                ."{$ride->pickup_address} to {$ride->destination_address} was declined by the driver.",
                ['booking_id' => $booking->id, 'ride_id' => $ride->id],
                'normal', 'ride'
            );

            Log::info('Booking rejected by driver', [
                'booking_id' => $booking->id,
                'driver_id' => $driver->id,
            ]);

            // RV-37 / un9: `refresh()` discards every loaded relation, so BookingResource's reads
            // (`$booking->user`, `$booking->ride->driver`) were always lazy - one query each, per
            // booking. Re-load the relations the response path needs. Stage 2 C2: the profile relations
            // are NOT here any more; the handler resolves the photo paths from Identity's port.
            return $booking->refresh()->load(['user', 'ride.driver']);
        }, attempts: 3);
    }

    // =========================================================================
    // CANCEL BOOKING  (passenger — full booking)
    // =========================================================================

    /** Delegates to BookingCancellationService; that class carries the implementation notes. */
    public function cancelBooking(int $bookingId, User $passenger): Booking
    {
        return $this->cancellations->cancelBooking($bookingId, $passenger);
    }
    // =========================================================================
    // CANCEL PARTIAL SEATS  (passenger)
    // =========================================================================

    /** Delegates to BookingCancellationService; that class carries the implementation notes. */
    public function cancelPartialSeats(int $bookingId, int $seatsToCancel, User $passenger): array
    {
        return $this->cancellations->cancelPartialSeats($bookingId, $seatsToCancel, $passenger);
    }
    // =========================================================================
    // REPORT PASSENGER NO-SHOW  (driver reports)
    // =========================================================================

    /** Delegates to BookingCompletionService; that class carries the implementation notes. */
    public function reportPassengerNoShow(int $bookingId, User $driver): array
    {
        return $this->completions->reportPassengerNoShow($bookingId, $driver);
    }
    // =========================================================================
    // PASSENGER CONFIRMS RIDE COMPLETION
    // =========================================================================

    /** Delegates to BookingCompletionService; that class carries the implementation notes. */
    public function passengerConfirmCompletion(int $bookingId, User $passenger): array
    {
        return $this->completions->passengerConfirmCompletion($bookingId, $passenger);
    }
    // =========================================================================
    // GETTERS
    // =========================================================================

    public function getUserBookings(int $userId): Collection
    {
        return Booking::with([
            'ride',
            'user.receivedRatings', // AF-4: batch the passenger rating for BookingResource
            'ride.driver',
            // Stage 2 C2: `ride.driver.profile` is gone - the handler batches it from Identity's port.
        ])
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Stage 2 C2: the stored profile photos a single BookingResource renders - the passenger's and the
     * ride's driver's - in ONE Identity query. The response handlers hand the result to the resource
     * (`withProfilePhotos()`), so the resource itself never names a module port.
     *
     * @return array<int, string|null>
     */
    public function profilePhotosFor(Booking $booking): array
    {
        return app(ProfileDirectory::class)->photoPathsForUsers(array_values(array_filter([
            $booking->user_id,
            $booking->ride?->driver_id,
        ])));
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Return an idempotent replay of an earlier booking request, or null when the key is new.
     *
     * RV-15 replaced a Redis read that had three defects: the key
     * `booking:idem:{key}` was NOT user-scoped (a different user replaying the same key got
     * the original booking, including its phone numbers - a cross-tenant leak); the check ran
     * OUTSIDE the transaction, so two concurrent same-key requests both passed it and created
     * two bookings; and it lived only in Redis, so a cache flush erased the dedup. The
     * unique(user_id, idempotency_key) index from RV-40 is the durable guarantee, and this
     * re-check runs inside the caller's transaction, atomic with the insert.
     */
    private function findReplayedBooking(BookRideDTO $dto, User $passenger): ?Booking
    {
        $existing = Booking::where('user_id', $passenger->id)
            ->where('idempotency_key', $dto->idempotencyKey)
            ->first();

        if (! $existing) {
            return null;
        }

        Log::info('Duplicate booking request detected', [
            'idempotency_key' => $dto->idempotencyKey,
            'existing_booking_id' => $existing->id,
        ]);

        // RV-37 / un9: the idempotent-replay return goes straight into BookingResource, which
        // reads `user` and `ride.driver` - load the same set as a fresh booking. Stage 2 C2: the
        // profile relations are the handler's batched port read now.
        return $existing->load(['user', 'ride.driver']);
    }

    /**
     * Create the booking row for a new request.
     *
     * The idempotency key is stored so the unique(user_id, idempotency_key) index (RV-40)
     * is the durable dedup guarantee, not an evictable cache entry.
     */
    private function createBookingRecord(BookRideDTO $dto, BookingStatus $initialStatus): Booking
    {
        return Booking::create([
            'user_id' => $dto->passengerId,
            'ride_id' => $dto->rideId,
            'seats' => $dto->seats,
            'status' => $initialStatus->value,
            'communication_number' => $dto->communicationNumber->number(),
            'idempotency_key' => $dto->idempotencyKey,
        ]);
    }

    /**
     * Charge an immediately-confirmed booking through the payment strategy (RV-20) and
     * deduct its seats from the ride.
     *
     * The E-PAY branch is deliberately NOT spelled out: the factory picks the strategy from
     * the ride's own payment_method, so adding a payment method no longer means editing this
     * service, and for CASH the strategy is a documented no-op.
     *
     * The `success` check is defence for a future strategy registered through
     * PaymentStrategyFactory::register() that RETURNS a failure instead of throwing. Today's
     * strategies throw (RV-09(a)), so it cannot fire - but `sec 83` was written precisely
     * because a caller that ignores a soft result loses money silently.
     */
    private function settleConfirmedBooking(Booking $booking, Ride $ride, User $passenger, int $seats): void
    {
        $paymentResult = $this->paymentFactory->make($ride->payment_method)
            ->processBookingPayment($booking, $ride, $passenger);

        if (! $paymentResult->success) {
            throw new \RuntimeException('Booking payment failed: '.$paymentResult->message);
        }

        $this->deductSeats($ride, $seats);
    }

    /** The "Ride booked successfully" audit line. */
    private function logBookingCreated(
        Booking $booking,
        Ride $ride,
        User $passenger,
        BookingStatus $initialStatus
    ): void {
        Log::info('Ride booked successfully', [
            'ride_id' => $ride->id,
            'booking_id' => $booking->id,
            'passenger_id' => $passenger->id,
            'status' => $initialStatus->value,
            'payment_method' => $ride->payment_method,
        ]);
    }

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
        if (! $rideStatus->canBeBooked()) {
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
        Booking $booking,
        Ride $ride,
        User $passenger,
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
                'ride_id' => $ride->id,
                'booking_id' => $booking->id,
                'passenger_id' => $passenger->id,
                'seats' => $booking->seats,
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
}
