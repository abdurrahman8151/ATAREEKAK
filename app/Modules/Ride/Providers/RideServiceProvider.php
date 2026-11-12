<?php

namespace App\Modules\Ride\Providers;

use App\Modules\Ride\Contracts\AdminTripQueries;
use App\Modules\Ride\Contracts\BookingEscrowLedger;
use App\Modules\Ride\Contracts\BookingRevenueAggregates;
use App\Modules\Ride\Contracts\CashRideCounter;
use App\Modules\Ride\Contracts\DriverRideReads;
use App\Modules\Ride\Contracts\Geocoding\GeocodingServiceInterface;
use App\Modules\Ride\Contracts\Geocoding\RouteCalculationServiceInterface;
use App\Modules\Ride\Contracts\NoShowReportInterface;
use App\Modules\Ride\Contracts\PassengerBookingHistory;
use App\Modules\Ride\Contracts\RideQueryExpressions;
use App\Modules\Ride\Contracts\RideReportCounts;
use App\Modules\Ride\Contracts\RideRepositoryInterface;
use App\Modules\Ride\Contracts\ScoreServiceInterface;
use App\Modules\Ride\Contracts\TripCancellationWriter;
use App\Modules\Ride\Contracts\UserRideStatsInterface;
use App\Modules\Ride\Domain\ScorePolicyFactory;
use App\Modules\Ride\Repositories\RideRepository;
use App\Modules\Ride\Services\AdminTripQueriesService;
use App\Modules\Ride\Services\ArabicPlaceNameService;
use App\Modules\Ride\Services\BookingEscrowWriter;
use App\Modules\Ride\Services\BookingRevenueAggregatesService;
use App\Modules\Ride\Services\BookingService;
use App\Modules\Ride\Services\CashRideCounterService;
use App\Modules\Ride\Services\DriverRideReadsService;
use App\Modules\Ride\Services\GeocodingService;
use App\Modules\Ride\Services\Noshowservice;
use App\Modules\Ride\Services\PassengerBookingHistoryService;
use App\Modules\Ride\Services\RideQueryExpressionsService;
use App\Modules\Ride\Services\RideReportCountsService;
use App\Modules\Ride\Services\RideSearchService;
use App\Modules\Ride\Services\RideService;
use App\Modules\Ride\Services\RideValidationService;
use App\Modules\Ride\Services\RouteCalculationService;
use App\Modules\Ride\Services\ScoreLedger;
use App\Modules\Ride\Services\ScoreService;
use App\Modules\Ride\Services\TripCancellationWriterService;
use App\Modules\Ride\Services\UserRideStatsService;
use Illuminate\Support\ServiceProvider;

/**
 * Stage 1, phase 1(a): the Ride module's container bindings, moved verbatim out of
 * `App\Support\ContainerBindings`. Lifetimes unchanged; the geocoding, score and route-calculation
 * services are registered here because the ride context owns them (batch 233's context map), and
 * the ride repository's port is bound immediately after the services it may use.
 */
final class RideServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Geocoding, score and route calculation.
        $this->app->singleton(ArabicPlaceNameService::class);
        // Stage 2 C2: the guarded escrow decrement, so Money stops writing ookings itself.
        $this->app->bind(BookingEscrowLedger::class, BookingEscrowWriter::class);

        $this->app->singleton(GeocodingService::class, function ($app) {
            return new GeocodingService(
                $app->make(ArabicPlaceNameService::class)
            );
        });
        $this->app->singleton(ScoreService::class, function ($app) {
            // RV-11 (`R2 sec 73`): `ScoreService` no longer takes `ScorePolicyFactory` - all policy
            // lookups moved into `ScoreLedger`, which is now its only dependency. This binding passed
            // the factory alone and would have thrown ArgumentCountError on first resolution.
            return new ScoreService(
                $app->make(ScoreLedger::class)
            );
        });
        $this->app->singleton(ScoreLedger::class);
        $this->app->singleton(ScorePolicyFactory::class);
        $this->app->singleton(RouteCalculationService::class);

        // Ride lifecycle.
        $this->app->singleton(RideService::class);
        $this->app->singleton(BookingService::class);
        $this->app->singleton(RideValidationService::class);
        $this->app->singleton(RideSearchService::class);

        // Ports (batch 227/233).
        $this->app->bind(GeocodingServiceInterface::class, GeocodingService::class);
        $this->app->bind(RouteCalculationServiceInterface::class, RouteCalculationService::class);
        $this->app->bind(ScoreServiceInterface::class, ScoreService::class);
        $this->app->bind(UserRideStatsInterface::class, UserRideStatsService::class);
        $this->app->bind(RideRepositoryInterface::class, RideRepository::class);
        // Stage 2 C2: the admin driver screens embed two ride aggregates in their own query, so the
        // expressions - not result sets - cross the boundary and their query count stays zero.
        $this->app->bind(RideQueryExpressions::class, RideQueryExpressionsService::class);
        // Stage 2 C2: the driver-profile screen's recent-rides page and contact number.
        $this->app->bind(DriverRideReads::class, DriverRideReadsService::class);
        // Stage 2 C2: the report's fleet-wide trip counters are counts over Ride's own table.
        $this->app->bind(RideReportCounts::class, RideReportCountsService::class);
        // Stage 2 C2: the admin/staff trip screens' four queries over Ride's own table.
        $this->app->bind(AdminTripQueries::class, AdminTripQueriesService::class);
        // Stage 2 C2: the staff cancellation flow's trip writes (Console keeps the workflow itself).
        $this->app->bind(TripCancellationWriter::class, TripCancellationWriterService::class);
        // Stage 2 C2: Money's cash-creation-fee tiers count the driver's cash rides through this port.
        $this->app->bind(CashRideCounter::class, CashRideCounterService::class);
        // Stage 2 C2: Identity's admin passenger profile reads this passenger's booking history.
        $this->app->bind(PassengerBookingHistory::class, PassengerBookingHistoryService::class);
        // Stage 2 C2: the admin report's three booking-based money projections.
        $this->app->bind(BookingRevenueAggregates::class, BookingRevenueAggregatesService::class);
        $this->app->bind(NoShowReportInterface::class, Noshowservice::class);
    }
}
