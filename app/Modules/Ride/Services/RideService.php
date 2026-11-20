<?php

namespace App\Modules\Ride\Services;

use App\DTOs\Ride\CreateRideDTO;
use App\Enums\RideStatus;
use App\Models\User;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Money\Contracts\CancellationRefundInterface;
use App\Modules\Money\Contracts\CashRideFeeServiceInterface;
use App\Modules\Money\Contracts\RidePayoutInterface;
use App\Modules\Ride\Contracts\RideRepositoryInterface;
use App\Modules\Ride\Events\RideCreated;
use App\Modules\Ride\Models\Ride;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class RideService
{
    private readonly RideCancellationService $cancellations;

    private readonly RideCompletionService $completions;

    public function __construct(
        private readonly RideRepositoryInterface $rideRepository,
        private readonly RideValidationService $validationService,
        private readonly RideSearchService $searchService,
        private readonly CancellationRefundInterface $cancellationRefund,
        private readonly RidePayoutInterface $ridePayout,
        private readonly NotificationPort $notificationService,
        private readonly ScoreService $scoreService,
        private readonly Noshowservice $noshowService,
        private readonly CashRideFeeServiceInterface $cashRideFeeService,
        private readonly RouteCalculationService $routeService,
    ) {
        $this->cancellations = new RideCancellationService(
            $validationService,
            $scoreService,
            $cancellationRefund,
            $cashRideFeeService,
            $notificationService,
        );
        $this->completions = new RideCompletionService(
            $ridePayout,
            $notificationService,
            $scoreService,
            $cashRideFeeService,
        );
    }

    // =========================================================================
    // DELEGATED LIFECYCLE - the implementations live in the two collaborators
    // =========================================================================

    /** Delegates to RideCancellationService. */
    public function cancelRide(int $rideId, User $driver): Ride
    {
        return $this->cancellations->cancelRide($rideId, $driver);
    }

    /** Delegates to RideCompletionService. */
    public function finishRide(int $rideId, User $driver): array
    {
        return $this->completions->finishRide($rideId, $driver);
    }

    /** Delegates to RideCompletionService. */
    public function driverConfirmCompletion(int $rideId, User $driver): array
    {
        return $this->completions->driverConfirmCompletion($rideId, $driver);
    }

    /** Delegates to RideCompletionService. */
    public function checkAndCompleteRide(Ride $ride): void
    {
        $this->completions->checkAndCompleteRide($ride);
    }

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
        return $this->noshowService
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
}
