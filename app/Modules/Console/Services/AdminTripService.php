<?php

namespace App\Modules\Console\Services;

use App\Models\User;
use App\Modules\Identity\Contracts\ProfileDirectory;
use App\Modules\Ride\Contracts\AdminTripQueries;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * AdminTripService
 *
 * Provides all data for the admin trips dashboard page.
 *
 * Design — mirrors AdminReportService intentionally:
 *   - Queries Eloquent models directly (admin aggregations don't fit
 *     the CRUD repository contract).
 *   - Each public method is a single, self-contained data slice.
 *   - Private helpers keep each public method readable.
 *
 * ─── Status semantics used throughout ───────────────────────────────────────
 *  "scheduled" → DB status = 'active'  AND departure_time >  now()
 *  "active"    → DB status = 'active'  AND departure_time <= now()
 *  "completed" → DB status = 'finished'
 *  "cancelled" → DB status = 'cancelled'
 *  "awaiting"  → DB status = 'awaiting_confirmation'
 *  "all"       → all of the above
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AdminTripService
{
    // =========================================================================
    // TRIP LIST  (paginated, filterable)
    // =========================================================================

    /**
     * Paginated trip list for the admin table.
     *
     * @param  string  $filter  one of: all | active | scheduled | completed | cancelled
     * @param  int  $perPage  1-50
     */
    public function getFilteredTrips(
        string $filter = 'all',
        int $perPage = 15,
        int $page = 1,
    ): LengthAwarePaginator {
        // Stage 2 C2: the query over `rides` is Ride's; the rows come back here and are formatted below.
        return app(AdminTripQueries::class)->paginateTrips($filter, $perPage, $page);
    }

    /**
     * Shape a whole page of trips. The drivers' photos come from Identity in ONE query for the page - the
     * same single query the removed `driver.profile` eager load cost.
     *
     * @return array<int, array<string, mixed>>
     */
    public function formatTrips(LengthAwarePaginator $trips): array
    {
        $photos = $this->driverPhotosFor($trips->getCollection()->pluck('driver_id')->all());

        return $trips->getCollection()
            ->map(fn ($ride) => $this->formatTrip($ride, ['profile_photo' => $photos[$ride->driver_id] ?? null]))
            ->values()
            ->all();
    }

    /**
     * Shape a single Ride model into the array the controller will return.
     * Kept separate so the controller loop stays one-liner.
     *
     * Stage 2 C2: the parameter is deliberately UNTYPED - naming the Ride model is the one thing a module
     * may not do to another module, and an untyped parameter is this codebase's established way to hold a
     * value from another module. The body is unchanged.
     */
    public function formatTrip($ride, ?array $driverProfile = null): array
    {
        $now = Carbon::now();
        $driver = $ride->driver;

        // Stage 2 C2: the driver photo is Identity's (`profiles`). A caller shaping a page passes the
        // batched map in as `['profile_photo' => ?string]`; a direct call resolves this one driver - the
        // same single query the removed `driver.profile` eager load cost.
        $storedPhoto = $driverProfile !== null
            ? ($driverProfile['profile_photo'] ?? null)
            : ($driver ? $this->driverPhotoFor($driver->id) : null);

        $bookedSeats = $ride->bookings->sum('seats');
        $totalSeats = $ride->available_seats + $bookedSeats;

        return [
            'id' => $ride->id,
            'trip_ref' => '#TR-'.$ride->id,

            'driver' => [
                'id' => $driver?->id,
                'name' => trim(($driver?->first_name ?? '').' '.($driver?->last_name ?? '')),
                'profile_photo' => $storedPhoto
                    ? asset('storage/'.$storedPhoto)
                    : null,
            ],

            'route' => [
                'from' => $ride->pickup_address,
                'to' => $ride->destination_address,
            ],

            'timing' => [
                'departure_time' => $ride->departure_time->toIso8601String(),
                'label' => $this->timingLabel($ride->departure_time, $now),
                'time_only' => $ride->departure_time->format('H:i'),
            ],

            'passengers' => [
                'booked' => $bookedSeats,
                'total' => $totalSeats,
                'label' => "{$bookedSeats}/{$totalSeats}",
            ],

            'status' => $this->resolveUiStatus($ride, $now),
            'payment_method' => $ride->payment_method,
            'vehicle_type' => $ride->vehicle_type,
            'price_per_seat' => $ride->price_per_seat,
        ];
    }

    // =========================================================================
    // STATUS COUNTS  (tab badge numbers)
    // =========================================================================

    /**
     * Returns per-filter counts for the tab badges.
     * Runs 5 lean COUNT queries instead of loading models.
     */
    public function getStatusCounts(): array
    {
        // Stage 2 C2: six counts over Ride's own table; `$now` is passed so the badge counts and the
        // list filter on the same request agree on the instant.
        return app(AdminTripQueries::class)->statusCounts(Carbon::now());
    }

    // =========================================================================
    // POPULAR ROUTES  (route monitoring)
    // =========================================================================

    /**
     * Most-ridden routes sorted by trip count descending.
     *
     * Groups by the stored address strings (already normalised at ride-creation
     * time by GeocodingService::reverseGeocode), so no fuzzy matching is needed.
     *
     * @param  int  $limit  1-50
     */
    public function getPopularRoutes(int $limit = 10): array
    {
        // Stage 2 C2: the grouped query over `rides` is Ride's; the demand figures below are this
        // screen's presentation of the numbers it returns.
        $rows = app(AdminTripQueries::class)->popularRoutes($limit);
        $maxCount = collect($rows)->max('trip_count') ?: 1;

        return collect($rows)->map(function ($row) use ($maxCount): array {
            $pct = ($row['trip_count'] / $maxCount) * 100;

            return [
                'from' => $row['from'],
                'to' => $row['to'],
                'trip_count' => $row['trip_count'],
                'total_passengers' => $row['total_passengers'],
                'demand_percentage' => (int) round($pct),
                'demand_level' => $this->demandLevel($pct),
            ];
        })->values()->all();
    }

    // =========================================================================
    // TOP DRIVERS
    // =========================================================================

    /**
     * Verified drivers sorted by average rating, highest first.
     *
     * Drivers with no ratings yet are placed last (NULL LAST).
     *
     * @param  int  $limit  1-50
     */
    public function getTopDrivers(int $limit = 10): array
    {
        $drivers = User::select(
            'users.id',
            'users.first_name',
            'users.last_name',
            DB::raw('AVG(user_ratings.rating) as avg_rating'),
            DB::raw('COUNT(DISTINCT user_ratings.id) as rating_count'),
            DB::raw('COUNT(DISTINCT finished_rides.id) as completed_rides'), // ← count from rides table
        )
            ->leftJoin('user_ratings', 'user_ratings.rated_user_id', '=', 'users.id')
            ->leftJoin(
                DB::raw("(SELECT id, driver_id FROM rides WHERE status = 'finished') as finished_rides"),
                'finished_rides.driver_id', '=', 'users.id'
            )
            ->where('users.is_verified_driver', true)
            ->groupBy('users.id', 'users.first_name', 'users.last_name')
            ->orderByRaw('AVG(user_ratings.rating) DESC')
            ->limit($limit)
            ->get();

        // Stage 2 C2: `profiles` is Identity's. The photo column arrives in ONE batched read, replacing the
        // `profile:user_id,profile_photo` eager load 1:1 - same one query, and the ranking is untouched.
        $photoPaths = app(ProfileDirectory::class)->photoPathsForUsers($drivers->pluck('id')->all());

        return $drivers->values()->map(function (User $driver, int $index) use ($photoPaths): array {
            $storedPhoto = $photoPaths[$driver->id] ?? null;

            return [
                'rank' => $index + 1,
                'id' => $driver->id,
                'name' => trim("{$driver->first_name} {$driver->last_name}"),
                'profile_photo' => $storedPhoto
                    ? asset('storage/'.$storedPhoto)
                    : null,
                'avg_rating' => $driver->avg_rating !== null
                    ? round((float) $driver->avg_rating, 1)
                    : null,
                'rating_count' => (int) $driver->rating_count,
                'total_rides' => (int) $driver->completed_rides, // ← now accurate
            ];
        })->all();
    }
    // =========================================================================
    // LIVE TRIPS  (departure passed, ride not finished)
    // =========================================================================

    /**
     * Rides currently on the road:
     *   status = 'active'  AND  departure_time <= now()
     *
     * Returns full detail including route geometry and confirmed passengers,
     * so the frontend can render a map view or contact-driver panel.
     */
    public function getLiveTrips(): array
    {
        $now = Carbon::now();

        $rides = app(AdminTripQueries::class)->liveTrips($now);

        // Stage 2 C2: one batched photo read for the whole live map, replacing the `driver.profile` eager
        // load on the ride query 1:1.
        $photos = $this->driverPhotosFor($rides->pluck('driver_id')->all());

        return $rides
            ->map(fn ($ride) => $this->formatLiveTrip($ride, $now, $photos[$ride->driver_id] ?? null))
            ->all();
    }

    /**
     * @param  array<int, int|null>  $driverIds
     * @return array<int, string|null>
     */
    private function driverPhotosFor(array $driverIds): array
    {
        $ids = array_values(array_unique(array_filter($driverIds, fn ($id) => $id !== null)));

        return app(ProfileDirectory::class)->photoPathsForUsers($ids);
    }

    /** The single-driver fallback for a direct `formatTrip()` call. */
    private function driverPhotoFor(int $driverId): ?string
    {
        return app(ProfileDirectory::class)->photoPathsForUsers([$driverId])[$driverId] ?? null;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Shape one live trip for the map view / contact-driver panel.
     */
    private function formatLiveTrip($ride, Carbon $now, ?string $driverPhoto = null): array
    {
        $minutesElapsed = (int) $ride->departure_time->diffInMinutes($now);
        $durationMins = $ride->duration ? (int) round($ride->duration / 60) : null;
        $etaMins = $durationMins !== null
            ? max(0, $durationMins - $minutesElapsed)
            : null;

        return [
            'id' => $ride->id,
            'trip_ref' => '#TR-'.$ride->id,

            'driver' => $this->liveTripDriverBlock($ride, $driverPhoto),

            'route' => $this->liveTripRouteBlock($ride),

            'timing' => [
                'departure_time' => $ride->departure_time->toIso8601String(),
                'minutes_elapsed' => $minutesElapsed,
                'eta_minutes' => $etaMins,
                'duration_total' => $durationMins,
            ],

            'passengers' => $this->liveTripPassengersBlock($ride),

            'passenger_count' => $ride->bookings->sum('seats'),
            'vehicle_type' => $ride->vehicle_type,
            'distance_km' => $ride->distance
                ? round($ride->distance / 1000, 1)
                : null,
        ];
    }

    private function liveTripDriverBlock($ride, ?string $driverPhoto = null): array
    {
        return [
            'id' => $ride->driver?->id,
            'name' => trim(($ride->driver?->first_name ?? '').' '.($ride->driver?->last_name ?? '')),
            // Stage 2 C2: the stored path arrives from Identity's batched port read.
            'profile_photo' => $driverPhoto
                ? asset('storage/'.$driverPhoto)
                : null,
            'communication_number' => $ride->communication_number,
        ];
    }

    private function liveTripRouteBlock($ride): array
    {
        return [
            'from' => $ride->pickup_address,
            'to' => $ride->destination_address,
            'pickup_coords' => $this->safeCoords($ride, 'pickup'),
            'destination_coords' => $this->safeCoords($ride, 'destination'),
            'geometry' => $ride->route_geometry,
        ];
    }

    private function liveTripPassengersBlock($ride): array
    {
        return $ride->bookings->map(fn ($b) => [
            'id' => $b->user?->id,
            'name' => trim(($b->user?->first_name ?? '').' '.($b->user?->last_name ?? '')),
            'seats' => $b->seats,
        ])->values()->all();
    }

    /**
     * Translate the stored DB status + departure_time into the UI status string
     * the frontend uses for badges and filter matching.
     */
    private function resolveUiStatus($ride, Carbon $now): string
    {
        return match (true) {
            $ride->status === 'finished' => 'completed',
            $ride->status === 'cancelled' => 'cancelled',
            $ride->status === 'awaiting_confirmation' => 'awaiting',
            $ride->departure_time->gt($now) => 'scheduled',
            default => 'active',
        };
    }

    /**
     * Human-readable timing label for the table ("Today", "Tomorrow", etc.).
     */
    private function timingLabel(Carbon $departure, Carbon $now): string
    {
        return match (true) {
            $departure->isToday() => 'Today',
            $departure->isTomorrow() => 'Tomorrow',
            $departure->isYesterday() => 'Yesterday',
            default => $departure->format('d M Y'),
        };
    }

    /**
     * Demand level label from a 0-100 percentage relative to the max route count.
     */
    private function demandLevel(float $pct): string
    {
        return match (true) {
            $pct >= 75 => 'Very High',
            $pct >= 50 => 'High',
            $pct >= 25 => 'Medium',
            default => 'Low',
        };
    }

    /**
     * Safely extract coordinates from the Ride's spatial accessor.
     * Returns null instead of throwing if the DB row has no geometry.
     */
    private function safeCoords($ride, string $type): ?array
    {
        try {
            return $type === 'pickup'
                ? $ride->pickup_location
                : $ride->destination_location;
        } catch (\Throwable) {
            return null;
        }
    }
}
