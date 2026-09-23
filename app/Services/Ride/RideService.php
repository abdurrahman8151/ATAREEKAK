<?php

namespace App\Services\Ride;

use App\DTOs\Ride\CreateRideDTO;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Events\RideCreated;
use App\Events\RideCancelled;
use App\Interfaces\PolicyRepositoryInterface;
use App\Interfaces\RideRepositoryInterface;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Payment\WalletTransactionService;
use App\Services\Score\ScoreService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use App\Services\Payment\CashRideFeeService;
use Illuminate\Support\Facades\Log;

final class  RideService
{
    public function __construct(
        private readonly RideRepositoryInterface  $rideRepository,
        private readonly RideValidationService    $validationService,
        private readonly RideSearchService        $searchService,
        private readonly WalletTransactionService $walletService,
        private readonly NotificationService      $notificationService,
        private readonly ScoreService             $scoreService,
        private readonly CashRideFeeService       $cashRideFeeService,
        private readonly PolicyRepositoryInterface $policySettings,
    ) {}

    // =========================================================================
    // CREATE RIDE
    // =========================================================================

    /**
     * Create a new ride and immediately charge the driver the creation fee.
     *
     * Fee: platform profit percentage of (price_per_seat × available_seats) — Driver wallet → SyCash wallet.
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

            // Cash ride: check eligibility and stamp fee fields
            if ($dto->paymentMethod->value === 'cash') {
                $feeAmount   = $dto->calculateRideCreationFee($this->policySettings->getPlatformProfitPercentage())->amount();
                $eligibility = $this->cashRideFeeService->canCreateCashRide($driver, $feeAmount);

                if (!$eligibility['allowed']) {
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
            $ride = Ride::lockForUpdate()->findOrFail($rideId);

            if ($ride->driver_id !== $driver->id) {
                throw new \InvalidArgumentException('Only the ride creator can cancel it');
            }

            $rideStatus = RideStatus::from($ride->status);
            if ($rideStatus->isTerminal()) {
                throw new \InvalidArgumentException(
                    "Cannot cancel a ride with status: {$rideStatus->label()}"
                );
            }

            $this->validationService->validateCanCancelRide(Carbon::parse($ride->departure_time));

            // Snapshot all active bookings before any status changes
            $activeBookings = $ride->bookings()
                ->whereNotIn('status', [
                    BookingStatus::CANCELLED->value,
                    BookingStatus::COMPLETED->value,
                    Booking::NO_SHOW,
                ])
                ->with('user')
                ->get();

            $confirmedBookings = $activeBookings->filter(
                fn($b) => $b->status === BookingStatus::CONFIRMED->value
            );
            $pendingBookings = $activeBookings->filter(
                fn($b) => $b->status === BookingStatus::PENDING->value
            );

            // Reconstruct original seat count for creation fee refund calculation
            $confirmedSeats = $confirmedBookings->sum('seats');
            $pendingSeats   = $pendingBookings->sum('seats');
            $originalSeats  = $ride->available_seats + $confirmedSeats + $pendingSeats;

            // Snapshot booking existence NOW — before step 2 cancels them.
            // refundCashRideCreationFee() needs to know whether passengers were
            // present at the moment of cancellation, not after the wipe.
            $hadActiveBookings = $activeBookings->isNotEmpty();

            // Calculate elapsed % once — reused for score AND fee-refund notification.
            $elapsedPct = ScoreService::calculateElapsedPct(
                $ride->created_at,
                Carbon::parse($ride->departure_time)
            );

            // 1. Mark ride as cancelled
            $ride->status = RideStatus::CANCELLED->value;
            $ride->save();

            // 2. Cancel every active booking
            foreach ($activeBookings as $booking) {
                $booking->status = BookingStatus::CANCELLED->value;
                $booking->save();
            }

            // 3. Refund confirmed E-PAY passengers (pending were never charged)
            if ($confirmedBookings->isNotEmpty()
                && $ride->payment_method === PaymentMethod::E_PAY->value
            ) {
                $this->walletService->refundPassengersForDriverCancellation($ride, $confirmedBookings);
            }

            // 4. Refund or retain the driver's cash-ride creation fee.
            //    Policy: elapsed < 30% → full refund always.
            //            elapsed ≥ 30% + had passengers → platform keeps fee.
            //            elapsed ≥ 30% + no passengers  → full refund.
            if ($ride->payment_method === PaymentMethod::CASH->value) {
                $this->cashRideFeeService->refundCashRideCreationFee($ride, $driver, $hadActiveBookings);
            }

            // 5. Score penalty for driver
            $this->scoreService->recordDriverCancelRide($driver, $ride, $elapsedPct);

            // 6. Notify all affected passengers
            $this->notifyPassengersOnRideCancelled($ride, $confirmedBookings, $pendingBookings);

            // 7. Notify driver — fee outcome is conditional on cancellation policy
            $feeKept = $ride->payment_method === PaymentMethod::CASH->value
                && $elapsedPct >= 30.0
                && $hadActiveBookings;

            $feeMessage = match (true) {
                $ride->payment_method !== PaymentMethod::CASH->value => '',
                $feeKept => ' Your creation fee has been retained by the platform due to '
                    . 'late cancellation while passengers were booked.',
                default  => ' Your creation fee has been refunded in full.',
            };

            $this->notificationService->createNotification(
                $driver,
                'ride_cancelled_driver',
                'Ride Cancelled',
                "Your ride from {$ride->pickup_address} to {$ride->destination_address} has been cancelled.{$feeMessage}",
                [
                    'ride_id'              => $ride->id,
                    'passengers_notified'  => $activeBookings->count(),
                    'elapsed_pct'          => round($elapsedPct, 2),
                    'creation_fee_kept'    => $feeKept,
                ],
                'normal', 'ride'
            );

            broadcast(new RideCancelled($ride, $activeBookings->toArray(), $driver));

            Log::info('Ride cancelled by driver', [
                'ride_id'        => $ride->id,
                'driver_id'      => $driver->id,
                'elapsed_pct'    => round($elapsedPct, 2),
                'confirmed'      => $confirmedBookings->count(),
                'pending'        => $pendingBookings->count(),
                'original_seats' => $originalSeats,
            ]);

            return $ride->fresh();
        });
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
        $ride = Ride::findOrFail($rideId);

        if ($ride->driver_id !== $driver->id) {
            throw new \InvalidArgumentException('Only the ride driver can finish it');
        }

        $rideStatus = RideStatus::from($ride->status);
        if (!in_array($rideStatus, [RideStatus::ACTIVE, RideStatus::FULL])) {
            throw new \InvalidArgumentException(
                "Can only finish an active or full ride (current: {$rideStatus->label()})"
            );
        }

        if (now()->lessThan(Carbon::parse($ride->departure_time))) {
            throw new \InvalidArgumentException('Cannot finish a ride before its departure time');
        }

        $confirmedBookings = $ride->bookings()
            ->where('status', BookingStatus::CONFIRMED->value)
            ->get();

        // ── Case A: empty ride ────────────────────────────────────────────────
        if ($confirmedBookings->isEmpty()) {
            return DB::transaction(function () use ($ride) {
                $ride->status      = RideStatus::FINISHED->value;
                $ride->finished_at = now();
                $ride->save();

                // Refund the creation fee (deferred → debt reduction; paid → wallet credit).
                if ($ride->payment_method === PaymentMethod::CASH->value) {
                    $this->cashRideFeeService->refundCashRideCreationFee($ride, $ride->driver);
                }

                $this->notificationService->createNotification(
                    $ride->driver,
                    'ride_finished_no_passengers',
                    'Ride Finished',
                    "Ride from {$ride->pickup_address} to {$ride->destination_address} finished. "
                    . "No passengers booked — creation fee refunded.",
                    ['ride_id' => $ride->id],
                    'normal', 'ride'
                );

                Log::info('Ride finished with no passengers', ['ride_id' => $ride->id]);

                return [
                    'status'                => RideStatus::FINISHED->value,
                    'message'               => 'Ride finished. No passengers booked — creation fee refunded.',
                    'requires_confirmation' => false,
                ];
            });
        }

        // ── Case B: confirmed passengers — await mutual confirmation ──────────
        return DB::transaction(function () use ($ride) {
            $ride->status      = RideStatus::AWAITING_CONFIRMATION->value;
            $ride->finished_at = now();
            $ride->save();

            $this->notifyAllForConfirmation($ride);

            Log::info('Ride moved to awaiting confirmation', ['ride_id' => $ride->id]);

            return [
                'status'                => RideStatus::AWAITING_CONFIRMATION->value,
                'message'               => 'Ride completed. Waiting for all parties to confirm.',
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
        $ride = Ride::findOrFail($rideId);

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
            'ride_id'   => $ride->id,
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

            if ($confirmedBookings->isNotEmpty()) {
                $allPassengersConfirmed = $confirmedBookings->every(
                    fn($b) => $b->passenger_confirmed_at !== null
                );
                if (! $allPassengersConfirmed) {
                    return;
                }
            }

            // ── Flip status to FINISHED *before* any side-effects ─────────
            $ride->status      = RideStatus::FINISHED->value;
            $ride->finished_at = now();
            $ride->save();

            // ── Release wallet escrow (E-PAY rides only) ──────────────────
            $ePayBookings = $confirmedBookings->filter(
                fn($b) => $ride->payment_method === PaymentMethod::E_PAY->value
            );
            if ($ePayBookings->isNotEmpty()) {
                $this->walletService->releaseEarningsToDriver($ride, $ePayBookings);
            }

            // ── Mark every confirmed booking as completed ─────────────────
            foreach ($confirmedBookings as $booking) {
                $booking->markAsCompleted();
            }

            // ── Record scores (+10 driver + +10 each confirmed passenger) ─
            // recordRideCompleted() internally awards the driver and iterates
            // every booking with status='completed', so no extra loop needed.
            $this->scoreService->recordRideCompleted($ride);

            // ── Notify driver ─────────────────────────────────────────────
            $this->notificationService->createNotification(
                $ride->driver,
                'ride_completed',
                'Ride Completed ✓',
                "Your ride from {$ride->pickup_address} to {$ride->destination_address} is complete. Earnings released.",
                ['ride_id' => $ride->id],
                'high',
                'ride'
            );

            // ── Notify each passenger ─────────────────────────────────────
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

            Log::info('Ride completed', [
                'ride_id'  => $ride->id,
                'bookings' => $confirmedBookings->count(),
                'payment'  => $ride->payment_method,
            ]);
        });
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
        return app(\App\Services\Ride\NoshowService::class)
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
        return $this->searchService->searchRides($criteria);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

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
            . "was completed to release your earnings and earn +10 trust points.",
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
                    . "{$ride->destination_address} was completed to earn +10 trust points.",
                    ['ride_id' => $ride->id, 'booking_id' => $booking->id],
                    'normal', 'ride'
                );
            });
    }

    /**
     * Notify each affected passenger when the driver cancels the entire ride.
     */
    private function notifyPassengersOnRideCancelled(
        Ride       $ride,
        Collection $confirmedBookings,
        Collection $pendingBookings
    ): void {
        $isEpay = $ride->payment_method === PaymentMethod::E_PAY->value;

        foreach ($confirmedBookings as $booking) {
            $refundAmount = $booking->seats * $ride->price_per_seat;
            $detail = $isEpay
                ? "A full refund of " . number_format($refundAmount, 0) . " SYP has been issued to your wallet."
                : "Cash ride — no wallet transaction needed.";

            $this->notificationService->createNotification(
                $booking->user,
                'ride_cancelled_by_driver',
                'Ride Cancelled by Driver',
                "The driver cancelled the ride from {$ride->pickup_address} to {$ride->destination_address}. {$detail}",
                [
                    'ride_id'       => $ride->id,
                    'booking_id'    => $booking->id,
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
                . "Your pending booking request has been cancelled — no payment was taken.",
                ['ride_id' => $ride->id, 'booking_id' => $booking->id],
                'normal', 'ride'
            );
        }
    }
}
