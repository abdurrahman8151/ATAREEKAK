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
}
