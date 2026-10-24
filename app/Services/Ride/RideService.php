<?php

namespace App\Services\Ride;

use App\DTOs\Ride\CreateRideDTO;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Events\RideCancelled;
use App\Events\RideCreated;
use App\Interfaces\RideRepositoryInterface;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Services\Geocoding\RouteCalculationService;
use App\Services\NotificationService;
use App\Services\Payment\CancellationRefund;
use App\Services\Payment\CashRideFeeService;
use App\Services\Payment\RidePayout;
use App\Services\Score\ScoreService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RideService
{
    public function __construct(
        private readonly RideRepositoryInterface $rideRepository,
        private readonly RideValidationService $validationService,
        private readonly RideSearchService $searchService,
        private readonly CancellationRefund $cancellationRefund,
        private readonly RidePayout $ridePayout,
        private readonly NotificationService $notificationService,
        private readonly ScoreService $scoreService,
        private readonly CashRideFeeService $cashRideFeeService,
        private readonly RouteCalculationService $routeService,
    ) {}

    // =========================================================================
    // CREATE RIDE
    // =========================================================================

    /**
     * Create a new ride and immediately charge the driver the creation fee.
     *
     * Fee: 5% of (price_per_seat × available_seats) — Driver wallet → SyCash wallet.
     * Applies to both CASH and E-PAY rides; all drivers need a wallet.
     *
     * Score gate: driver score must be ≥ 50 (validated in RideValidationService).
     */
    public function createRide(CreateRideDTO $dto, User $driver): Ride
    {
        // Validate driver: verified status + score ≥ 50 + required documents
        $this->validationService->validateDriverCanCreateRide($driver);
        $this->validationService->validateDepartureTime($dto->departureTime);

        return DB::transaction(function () use ($dto, $driver) {
            $rideData = $dto->toArray();

            // RV-55: POST /api/rides does not pass a route, so distance/duration arrive null and the
            // NOT NULL columns raise SQLSTATE 1048. Fill them from the route service when missing.
            if ($rideData['distance'] === null || $rideData['duration'] === null) {
                $route = $this->routeService->getRouteDetails(
                    ['lat' => $dto->pickupLocation->latitude(), 'lng' => $dto->pickupLocation->longitude()],
                    ['lat' => $dto->destinationLocation->latitude(), 'lng' => $dto->destinationLocation->longitude()],
                );
                $rideData['distance'] ??= $route['distance'];
                $rideData['duration'] ??= $route['duration'];
            }

            // Cash ride: check eligibility and stamp fee fields
            if ($dto->paymentMethod->value === 'cash') {
                $feeAmount = $dto->calculateRideCreationFee()->amount();
                $eligibility = $this->cashRideFeeService->canCreateCashRide($driver, $feeAmount);

                if (! $eligibility['allowed']) {
                    throw new \InvalidArgumentException($eligibility['reason']);
                }

                $rideData['cash_creation_fee'] = $feeAmount;
                $rideData['cash_fee_deferred'] = $eligibility['deferred'];
            } else {
                $rideData['cash_creation_fee'] = null;
                $rideData['cash_fee_deferred'] = false;
            }

            $ride = $this->rideRepository->createRideWithGeometry($rideData);

            // Charge or defer inside the same transaction
            if ($dto->paymentMethod->value === 'cash') {
                $this->cashRideFeeService->chargeCashRideCreationFee($ride, $driver);
            }

            $this->notificationService->createNotification(
                $driver,
                'ride_created',
                'Ride Created ✓',
                "Your ride from {$ride->pickup_address} to {$ride->destination_address} is now live.",
                ['ride_id' => $ride->id],
                'normal',
                'ride'
            );

            broadcast(new RideCreated($ride));

            return $ride->fresh(['driver.profile']);
        });
    }

    // =========================================================================
    // CANCEL RIDE  (driver cancels entire ride)
    // =========================================================================

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

    // =========================================================================
    // FINISH RIDE  (driver signals ride is over)
    // =========================================================================

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

    // =========================================================================
    // DRIVER CONFIRMS COMPLETION
    // =========================================================================

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

    // =========================================================================
    // CHECK AND COMPLETE  (called after every confirmation)
    // =========================================================================

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

    // =========================================================================
    // REPORT DRIVER NO-SHOW  (passenger reports)
    // =========================================================================

    /**
     * Passenger reports the driver didn't appear at departure time.
     *
     * Wallet (E-PAY only):
     *   Admin escrow → 100% refund to passenger.
     *
     * Score — driver:
     *   −15 pts ALWAYS (both CASH and E-PAY rides).
     */
    public function reportDriverNoShow(int $rideId, User $passenger): array
    {
        return app(Noshowservice::class)
            ->reportDriverNoShow($rideId, $passenger);
    }

    // =========================================================================
    // GETTERS
    // =========================================================================

    public function getRideById(int $rideId): Ride
    {
        return $this->rideRepository->getRideById($rideId);
    }

    public function getUserRides(int $userId): Collection
    {
        return $this->rideRepository->getDriverRides($userId);
    }

    public function getDriverRides(int $driverId): Collection
    {
        return $this->rideRepository->getDriverRides($driverId);
    }

    public function searchRides(array $criteria): Collection
    {
        // AF-4: the live search now runs the extracted RideSearchService. Until
        // this line changed the service was bound in the container and injected
        // into this class (:29) but never called — every search went through
        // RideRepository::searchRides, a persistence object that also owned
        // spatial query logic. Same OR-route semantics, now with the RideStatus
        // enum, eager loading and booked-seat counts; the repository copy is
        // deleted, so there is exactly one search path to reason about and to
        // load-test.
        return $this->searchService->searchRides($criteria);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

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
