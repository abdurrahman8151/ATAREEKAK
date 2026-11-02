<?php

namespace App\Modules\Ride\Providers;

use App\Modules\Ride\Contracts\Geocoding\GeocodingServiceInterface;
use App\Modules\Ride\Contracts\Geocoding\RouteCalculationServiceInterface;
use App\Modules\Ride\Contracts\RideRepositoryInterface;
use App\Modules\Ride\Contracts\ScoreServiceInterface;
use App\Modules\Ride\Contracts\UserRideStatsInterface;
use App\Repositories\RideRepository;
use App\Services\Geocoding\ArabicPlaceNameService;
use App\Services\Geocoding\GeocodingService;
use App\Services\Geocoding\RouteCalculationService;
use App\Services\Ride\BookingService;
use App\Services\Ride\RideSearchService;
use App\Services\Ride\RideService;
use App\Services\Ride\RideValidationService;
use App\Services\Ride\UserRideStatsService;
use App\Services\Score\ScoreLedger;
use App\Services\Score\ScorePolicyFactory;
use App\Services\Score\ScoreService;
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
    }
}
