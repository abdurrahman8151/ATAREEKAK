<?php

namespace App\Services\Ride;

use App\Enums\RideStatus;
use App\Models\Ride;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;

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
    private const MAX_DISTANCE_KM = 20;
    private const ROUTE_BUFFER_DEGREES = 0.05; // ~5km buffer around route

    /** Weights for the composite "best match" ranking. Must sum to 1.0. */
    private const WEIGHT_PRICE = 0.30;
    private const WEIGHT_DISTANCE = 0.25;
    private const WEIGHT_RATING = 0.25;
    private const WEIGHT_DRIVER_SCORE = 0.15;
    private const WEIGHT_TIMING = 0.05;

    private const VALID_SORTS = ['best', 'price_asc', 'price_desc', 'rating_desc', 'distance_asc', 'departure_time_asc'];

    /**
     * Search for available rides matching criteria
     *
     * $params['sort_by'] selects the ordering (default 'best', a weighted
     * composite of price/distance/rating/driver score/timing — see rankByBestMatch()).
     */
    public function searchRides(array $params): Collection
    {
        $query = Ride::query()
            ->whereDate('departure_time', '=', Carbon::parse($params['departure_date']))
            ->where('available_seats', '>=', $params['seats_required'])
            ->where('status', RideStatus::ACTIVE->value);

        $this->applySpatialFilters($query, $params);
        $this->selectMatchDistance($query, $params);

        $rides = $query
            ->with([
                'driver' => function ($query) {
                    // There is no driver_rating column on users — ratings live in
                    // user_ratings, so the average is aggregated into the alias
                    // RideResource reads. One extra sub-select, no N+1.
                    $query->select('id', 'first_name', 'last_name', 'avatar')
                        ->withAvg('receivedRatings as driver_rating', 'rating');
                },
                'driver.profile' => function ($query) {
                    $query->select('user_id', 'profile_photo');
                },
                'driver.userScore',
            ])
            ->withCount(['bookings as total_booked_seats' => function ($query) {
                $query->selectRaw('COALESCE(SUM(seats), 0)');
            }])
            ->get();

        $sortBy = in_array($params['sort_by'] ?? 'best', self::VALID_SORTS, true)
            ? $params['sort_by']
            : 'best';

        return $this->sortRides($rides, $sortBy);
    }

    /**
     * Select the ride-to-search-points distance (pickup+destination, in meters)
     * as a transient `match_distance_meters` attribute, used by rankByBestMatch().
     */
    private function selectMatchDistance(Builder $query, array $params): void
    {
        $srcWkt = sprintf('POINT(%F %F)', $params['source_lng'], $params['source_lat']);
        $dstWkt = sprintf('POINT(%F %F)', $params['dest_lng'], $params['dest_lat']);

        $query->addSelect('rides.*')->selectRaw(
            'ST_Distance_Sphere(pickup_location, ST_GeomFromText(?, 4326))
                + ST_Distance_Sphere(destination_location, ST_GeomFromText(?, 4326))
                AS match_distance_meters',
            [$srcWkt, $dstWkt]
        );
    }

    /**
     * Order the result set per the requested strategy.
     */
    private function sortRides(Collection $rides, string $sortBy): Collection
    {
        return match ($sortBy) {
            'price_asc' => $rides->sortBy('price_per_seat')->values(),
            'price_desc' => $rides->sortByDesc('price_per_seat')->values(),
            'rating_desc' => $rides->sortByDesc(fn (Ride $ride) => $ride->driver->driver_rating ?? 0)->values(),
            'distance_asc' => $rides->sortBy(fn (Ride $ride) => (float) $ride->match_distance_meters)->values(),
            'departure_time_asc' => $rides->sortBy('departure_time')->values(),
            default => $this->rankByBestMatch($rides),
        };
    }

    /**
     * Composite "best match" ranking: cheaper, closer, better-rated, more
     * reliable (driver score), and sooner-departing rides rank higher.
     *
     * Each factor is min-max normalized across the current result set (not
     * against a global scale) so the ranking stays meaningful regardless of
     * how prices/distances happen to spread for a given search.
     */
    private function rankByBestMatch(Collection $rides): Collection
    {
        if ($rides->isEmpty()) {
            return $rides;
        }

        $prices = $rides->map(fn (Ride $r) => (float) $r->price_per_seat);
        $distances = $rides->map(fn (Ride $r) => (float) $r->match_distance_meters);
        $ratings = $rides->map(fn (Ride $r) => (float) ($r->driver->driver_rating ?? 0));
        $driverScores = $rides->map(fn (Ride $r) => (float) ($r->driver->userScore->score ?? 70));
        $departureTimes = $rides->map(fn (Ride $r) => $r->departure_time->timestamp);

        $priceRange = [$prices->min(), $prices->max()];
        $distanceRange = [$distances->min(), $distances->max()];
        $ratingRange = [$ratings->min(), $ratings->max()];
        $driverScoreRange = [$driverScores->min(), $driverScores->max()];
        $timeRange = [$departureTimes->min(), $departureTimes->max()];

        return $rides
            ->map(function (Ride $ride) use (
                $priceRange, $distanceRange, $ratingRange, $driverScoreRange, $timeRange
            ) {
                $priceScore = 1 - $this->normalize((float) $ride->price_per_seat, $priceRange);
                $distanceScore = 1 - $this->normalize((float) $ride->match_distance_meters, $distanceRange);
                $ratingScore = $this->normalize((float) ($ride->driver->driver_rating ?? 0), $ratingRange);
                $driverScoreScore = $this->normalize((float) ($ride->driver->userScore->score ?? 70), $driverScoreRange);
                $timingScore = 1 - $this->normalize((float) $ride->departure_time->timestamp, $timeRange);

                $ride->match_score = round(
                    self::WEIGHT_PRICE * $priceScore
                    + self::WEIGHT_DISTANCE * $distanceScore
                    + self::WEIGHT_RATING * $ratingScore
                    + self::WEIGHT_DRIVER_SCORE * $driverScoreScore
                    + self::WEIGHT_TIMING * $timingScore,
                    4
                );

                return $ride;
            })
            ->sortByDesc('match_score')
            ->values();
    }

    /**
     * Min-max normalize a value into [0, 1]. A zero-width range (every ride
     * ties on this factor) normalizes to 1 so it stops influencing the ranking.
     */
    private function normalize(float $value, array $range): float
    {
        [$min, $max] = $range;

        return $max > $min ? ($value - $min) / ($max - $min) : 1.0;
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
        $maxDistanceMeters = self::MAX_DISTANCE_KM * 1000;
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
            "ST_Distance_Sphere(pickup_location, ST_GeomFromText(?, 4326)) <= ?",
            [$srcWkt, $maxDistance]
        )
            ->whereRaw(
                "ST_Distance_Sphere(destination_location, ST_GeomFromText(?, 4326)) <= ?",
                [$dstWkt, $maxDistance]
            );
    }

    /**
     * Match rides where route passes near search points
     *
     * The corridor is measured with a planar ST_Distance on SRID 0 geometries rather
     * than ST_Contains(ST_Buffer(...)): MySQL cannot buffer a LINESTRING in a geographic
     * SRS (error 3618), and forcing both operands to SRID 0 also keeps the GeoJSON
     * lon/lat axis order aligned with the POINT(lng lat) WKT built above.
     */
    private function applyRouteMatching(Builder $query, string $srcWkt, string $dstWkt): void
    {
        $query
            ->whereNotNull('route_geometry')
            ->whereRaw("JSON_VALID(route_geometry)")
            ->whereRaw("JSON_EXTRACT(route_geometry, '$.coordinates') IS NOT NULL")
            ->whereRaw("JSON_TYPE(JSON_EXTRACT(route_geometry, '$.coordinates')) = 'ARRAY'")
            // A LineString needs at least two positions, and each position must
            // itself be an array. ST_GeomFromGeoJSON raises error 3072 on anything
            // else, and that aborts the whole query rather than skipping the row,
            // so a single malformed ride would 500 the entire search.
            ->whereRaw("JSON_LENGTH(JSON_EXTRACT(route_geometry, '$.coordinates')) >= 2")
            ->whereRaw("JSON_TYPE(JSON_EXTRACT(route_geometry, '$.coordinates[0]')) = 'ARRAY'")
            // Check if source point is near route
            ->whereRaw(
                "ST_Distance(
                    ST_GeomFromGeoJSON(JSON_UNQUOTE(route_geometry), 1, 0),
                    ST_GeomFromText(?, 0)
                ) <= ?",
                [$srcWkt, self::ROUTE_BUFFER_DEGREES]
            )
            // Check if destination point is near route
            ->whereRaw(
                "ST_Distance(
                    ST_GeomFromGeoJSON(JSON_UNQUOTE(route_geometry), 1, 0),
                    ST_GeomFromText(?, 0)
                ) <= ?",
                [$dstWkt, self::ROUTE_BUFFER_DEGREES]
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
                "ST_Distance_Sphere(pickup_location, ST_GeomFromText(?, 4326)) <= ?",
                [$pointWkt, $radiusMeters]
            )
            ->with([
                'driver' => fn ($query) => $query->withAvg('receivedRatings as driver_rating', 'rating'),
                'driver.profile',
            ])
            ->orderBy('departure_time', 'asc')
            ->get();
    }
}
