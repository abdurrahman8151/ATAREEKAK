<?php

namespace App\Services\Ride;

use App\Enums\RideStatus;
use App\Models\Ride;
use App\Support\GeoPoint;
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
            // RV-10: never surface a ride that has already departed. `whereDate` alone
            // matched ANY ride on the searched calendar day, so searching today returned
            // rides whose departure_time was hours ago — nothing advances active→finished
            // outside the scheduler (that auto-confirm half needs a product decision), so
            // without this filter a departed ride stays bookable-looking in search forever.
            // A >= now() guard is decision-free correctness and strictly narrows the set.
            ->where('departure_time', '>=', Carbon::now())
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
        // RV-25: search points must use the SAME axis order as the stored geometry, POINT(lat
        // lng) (lat-first, matching MySQL's EPSG:4326 axis-order). Built through the shared
        // GeoPoint helper so the convention cannot drift.
        $srcWkt = GeoPoint::fromLatLng((float) $params['source_lat'], (float) $params['source_lng'])->wkt();
        $dstWkt = GeoPoint::fromLatLng((float) $params['dest_lat'], (float) $params['dest_lng'])->wkt();

        // V3: strategy B compares against the route in CARTESIAN SRID 0, where there is no
        // EPSG axis-order rule, so it needs the other ordering. See routeProbeWkt().
        $srcProbeWkt = $this->routeProbeWkt((float) $params['source_lat'], (float) $params['source_lng']);
        $dstProbeWkt = $this->routeProbeWkt((float) $params['dest_lat'], (float) $params['dest_lng']);

        $query->where(function ($q) use ($maxDistanceMeters, $srcWkt, $dstWkt, $srcProbeWkt, $dstProbeWkt) {
            // Strategy A: Direct endpoint matching
            $q->where(function ($q2) use ($maxDistanceMeters, $srcWkt, $dstWkt) {
                $this->applyEndpointMatching($q2, $srcWkt, $dstWkt, $maxDistanceMeters);
            })
                // Strategy B: Route-based matching
                ->orWhere(function ($q2) use ($srcProbeWkt, $dstProbeWkt) {
                    $this->applyRouteMatching($q2, $srcProbeWkt, $dstProbeWkt);
                });
        });
    }

    /**
     * `POINT(lng lat)` — deliberately the ONLY longitude-first string in this application.
     *
     * V3. Everything else here is lat-first, because MySQL applies EPSG:4326 AXIS-ORDER
     * (latitude first) to the 4326 geometry columns (RV-25). Route matching is the exception
     * because it is the one comparison that cannot run in a geographic SRS: ST_Buffer on a
     * LINESTRING is unimplemented there (error 3618), so the route is relabelled to Cartesian
     * SRID 0. In SRID 0 the ordinate order is literally x, y with no re-ordering, and
     * ST_GeomFromGeoJSON preserves GeoJSON as-is — x = lng, y = lat. The probe point therefore
     * has to be written lng-first to match it.
     *
     * Do not "normalise" this to lat-first for consistency with the rest of the file: that
     * silently inverts the match, and because both orderings produce a valid-looking query the
     * breakage would only show up as rides that never match. V3RouteBufferTest pins both.
     */
    private function routeProbeWkt(float $lat, float $lng): string
    {
        return sprintf('POINT(%F %F)', $lng, $lat);
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
     *
     * V3. This strategy is an `orWhere` on EVERY search, so anything it raises is a 500 for
     * the whole endpoint rather than a missed match. It used to raise on every ride that
     * carries a route: MySQL 8 does not implement ST_Buffer for a LINESTRING in a GEOGRAPHIC
     * reference system (error 3618), and ST_GeomFromGeoJSON defaults to SRID 4326 — so
     * `ST_Buffer(ST_GeomFromGeoJSON(...), d)` was always a geographic buffer.
     *
     * ST_SRID(<geom>, 0) relabels the parsed route as Cartesian, which makes the buffer legal.
     * The radius stays in DEGREES, which is the unit `rides.search.route_buffer_degrees` is
     * configured in (0.05 deg ~= 5.5 km). No GROUP_CONCAT is involved, so group_concat_max_len
     * cannot truncate a long polyline.
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
                        ST_SRID(ST_GeomFromGeoJSON(JSON_UNQUOTE(route_geometry)), 0),
                        ?
                    ),
                    ST_GeomFromText(?, 0)
                )',
                [$this->routeBufferDegrees, $srcWkt]
            )
            // Check if destination point is near route
            ->whereRaw(
                'ST_Contains(
                    ST_Buffer(
                        ST_SRID(ST_GeomFromGeoJSON(JSON_UNQUOTE(route_geometry)), 0),
                        ?
                    ),
                    ST_GeomFromText(?, 0)
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
        // RV-25: POINT(lat lng) via the shared GeoPoint helper (lat-first).
        $pointWkt = GeoPoint::fromLatLng($latitude, $longitude)->wkt();

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
