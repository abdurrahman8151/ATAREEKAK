<?php

namespace App\Services\Ride;

use App\Enums\RideStatus;
use App\Models\Ride;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ride Search Service
 *
 * EXTRACTED: Search logic moved from RideRepository
 *
 * Responsibilities:
 * - Search rides by criteria
 * - Apply spatial filters
 * - Apply temporal filters
 *
 * Single Responsibility: Ride searching logic only
 */
final class RideSearchService
{
    // AF-4: thresholds are config so the live search is tunable per environment
    // (load tests vs. production) instead of compile-time constants. The
    // previous 20 km default is preserved exactly.
    private int $maxDistanceMeters;

    private float $routeBufferDegrees;

    public function __construct()
    {
        $this->maxDistanceMeters = (int) config('rides.search.max_distance_km', 20) * 1000;
        $this->routeBufferDegrees = (float) config('rides.search.route_buffer_degrees', 0.05);
    }

    /**
     * Search for available rides matching criteria
     */
    public function searchRides(array $params): Collection
    {
        $query = Ride::query()
            ->whereDate('departure_time', '=', Carbon::parse($params['departure_date']))
            ->where('available_seats', '>=', $params['seats_required'])
            ->where('status', RideStatus::ACTIVE->value);

        $this->applySpatialFilters($query, $params);

        return $query
            ->with([
                // AF-4: this used to select a `driver_rating` column that does
                // not exist on users — one reason the service could never have
                // served the live endpoint. NOTE: no select-restrictions here.
                // The repository path this replaces loaded the FULL driver row,
                // and the controller serializes raw models (no Resource), so any
                // trimmed column list would SUBTRACT fields from a live response
                // shape. The wired search must be strictly additive: existing
                // keys unchanged, new ones (profile, received_ratings,
                // total_booked_seats) appended. Ratings arrive in ONE batched
                // eager load (see the N+1 test), never per row.
                'driver',
                'driver.profile',
                'driver.receivedRatings',
            ])
            ->withCount(['bookings as total_booked_seats' => function ($query) {
                $query->selectRaw('COALESCE(SUM(seats), 0)');
            }])
            ->orderBy('departure_time', 'asc')
            ->get();
    }

    /**
     * Apply spatial filters to query
     *
     * Matches rides using two strategies:
     * 1. Endpoint matching: pickup/destination within MAX_DISTANCE_KM
     * 2. Route matching: search points near the ride's route geometry
     */
    private function applySpatialFilters(Builder $query, array $params): void
    {
        $maxDistanceMeters = $this->maxDistanceMeters;
        $srcWkt = sprintf('POINT(%F %F)', $params['source_lng'], $params['source_lat']);
        $dstWkt = sprintf('POINT(%F %F)', $params['dest_lng'], $params['dest_lat']);

        $query->where(function ($q) use ($maxDistanceMeters, $srcWkt, $dstWkt) {
            // Strategy A: Direct endpoint matching
            $q->where(function ($q2) use ($maxDistanceMeters, $srcWkt, $dstWkt) {
                $this->applyEndpointMatching($q2, $srcWkt, $dstWkt, $maxDistanceMeters);
            })
                // Strategy B: Route-based matching
                ->orWhere(function ($q2) use ($srcWkt, $dstWkt) {
                    $this->applyRouteMatching($q2, $srcWkt, $dstWkt);
                });
        });
    }

    /**
     * Match rides where pickup/destination are close to search points
     */
    private function applyEndpointMatching(
        Builder $query,
        string $srcWkt,
        string $dstWkt,
        int $maxDistance
    ): void {
        $query->whereRaw(
            'ST_Distance_Sphere(pickup_location, ST_GeomFromText(?, 4326)) <= ?',
            [$srcWkt, $maxDistance]
        )
            ->whereRaw(
                'ST_Distance_Sphere(destination_location, ST_GeomFromText(?, 4326)) <= ?',
                [$dstWkt, $maxDistance]
            );
    }

    /**
     * Match rides where route passes near search points
     */
    private function applyRouteMatching(Builder $query, string $srcWkt, string $dstWkt): void
    {
        $query
            ->whereNotNull('route_geometry')
            ->whereRaw('JSON_VALID(route_geometry)')
            ->whereRaw("JSON_EXTRACT(route_geometry, '$.coordinates') IS NOT NULL")
            ->whereRaw("JSON_TYPE(JSON_EXTRACT(route_geometry, '$.coordinates')) = 'ARRAY'")
            // Check if source point is near route
            ->whereRaw(
                'ST_Contains(
                    ST_Buffer(
                        ST_GeomFromGeoJSON(JSON_UNQUOTE(route_geometry)),
                        ?
                    ),
                    ST_GeomFromText(?, 4326)
                )',
                [$this->routeBufferDegrees, $srcWkt]
            )
            // Check if destination point is near route
            ->whereRaw(
                'ST_Contains(
                    ST_Buffer(
                        ST_GeomFromGeoJSON(JSON_UNQUOTE(route_geometry)),
                        ?
                    ),
                    ST_GeomFromText(?, 4326)
                )',
                [$this->routeBufferDegrees, $dstWkt]
            );
    }

    /**
     * Get nearby rides for a location
     */
    public function getNearbyRides(float $latitude, float $longitude, int $radiusKm = 20): Collection
    {
        $radiusMeters = $radiusKm * 1000;
        $pointWkt = sprintf('POINT(%F %F)', $longitude, $latitude);

        return Ride::query()
            ->where('status', RideStatus::ACTIVE->value)
            ->whereRaw(
                'ST_Distance_Sphere(pickup_location, ST_GeomFromText(?, 4326)) <= ?',
                [$pointWkt, $radiusMeters]
            )
            ->with(['driver', 'driver.profile'])
            ->orderBy('departure_time', 'asc')
            ->get();
    }
}
