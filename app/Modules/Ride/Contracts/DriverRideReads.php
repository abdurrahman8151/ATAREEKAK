<?php

namespace App\Modules\Ride\Contracts;

/**
 * The driver-profile screen's ride reads: the recent-rides page and the driver's contact number.
 *
 * WHY THIS IS SEPARATE FROM {@see RideQueryExpressions} (Stage 2 C2). That contract exists for expressions the
 * caller EMBEDS in its own statement so they cost zero queries; this one returns a finished row set and a
 * scalar. Two contracts because they are two different shapes crossing the boundary, and a single named
 * "expressions" port that also returned rows would describe neither honestly.
 *
 * Both methods are one query each - the same queries the Console presenter ran inline, with the same
 * ordering, the same limit and the same null semantics - so the endpoint's query count does not move.
 *
 * The contract names no model: the rows come back as plain arrays whose keys are the ones the existing
 * response already publishes, so nothing outside this module has to reference the Ride class.
 */
interface DriverRideReads
{
    /**
     * The driver's most recent rides, newest first, as response-shaped arrays.
     *
     * Each row carries exactly the keys the driver-profile response already had - `id`, `pickup_address`,
     * `destination_address`, `departure_time` (ISO-8601), `status`, `bookings_count` - because the
     * formatting moved with the query and the caller no longer touches a ride row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentRidesForDriver(int $driverId, int $limit = 5): array;

    /**
     * The newest non-null communication number among the driver's rides, or null when they have none.
     *
     * Ordering is `created_at DESC`, exactly as the inline query had it.
     */
    public function latestCommunicationNumberForDriver(int $driverId): ?string;

    /**
     * The three ride counters the driver dashboard reports, plus the cancel rate derived from them.
     *
     * THREE queries, exactly as the inline code had it: `total`, `finished` and `cancelled`. They are
     * deliberately not merged into one grouped query - that would move the dashboard's recorded query
     * budget, and a budget may only shrink.
     *
     * `cancel_rate` is `round(cancelled / total * 100, 1)`, or 0.0 when the driver has no rides; the
     * expression is unchanged, only its home is.
     *
     * @return array{total_rides: int, completed_rides: int, cancelled_rides: int, cancel_rate: float}
     */
    public function rideStatsForDriver(int $driverId): array;

    /**
     * The driver's most recent rides for the dashboard, newest first, as response-shaped arrays.
     *
     * Keys: `id`, `status`, `source` (pickup address), `destination` (destination address),
     * `price_per_seat` (raw, unrounded - the caller casts) and `date` (ISO-8601 departure time).
     *
     * @return array<int, array<string, mixed>>
     */
    public function dashboardRecentRidesForDriver(int $driverId, int $limit = 10): array;

    /**
     * The driver's most-visited destination among FINISHED rides, or null when there are none.
     *
     * Returns `['name' => string, 'visit_count' => mixed]` - the two keys the dashboard publishes - or
     * null. Ties resolve exactly as the previous `GROUP BY ... ORDER BY visit_count DESC LIMIT 1` did.
     *
     * @return array{name: string, visit_count: mixed}|null
     */
    public function favoriteDestinationForDriver(int $driverId): ?array;
}
