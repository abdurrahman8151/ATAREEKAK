<?php

namespace App\Modules\Ride\Contracts;

/**
 * The passenger-side booking reads Identity's admin passenger profile needs.
 *
 * WHY A PORT (Stage 2 C2). `bookings` belongs to Ride, and Identity ran five of its own statements against that
 * table - two scalar aggregates, one grouped monthly breakdown, the recent-trips list with its ride and driver
 * eager loads, and an eligibility `exists()`. Once the model moves, naming `App\Models\Booking` from Identity is
 * the cross-module implementation reference the boundary ratchet forbids, so the statements move here and
 * Identity keeps the presentation (month labels, money formatting, the panel keys).
 *
 * EVERY METHOD IS THE STATEMENT IT REPLACES: the same `status = 'completed'` filter, the same
 * `seats * rides.price_per_seat` sum, the same `DATE_FORMAT(...)` grouping and ordering, the same relation
 * column lists on the recent-trips eager loads, and the same `exists()` shape. The query counts are identical,
 * which is what keeps the frozen passenger-profile snapshots valid.
 */
interface PassengerBookingHistory
{
    /** Completed bookings for this passenger. ONE query. */
    public function completedRidesCount(int $userId): int;

    /**
     * Total spent on completed bookings, as `SUM(seats * rides.price_per_seat)`. ONE query.
     *
     * Returned raw (float) - the caller formats it through `Money`, as it did before.
     */
    public function completedSpending(int $userId): float;

    /**
     * Completed trips per calendar month for the last `$months` months, keyed by `YYYY-MM`.
     *
     * The caller walks its own month range and looks each key up, so a month with no trips is still emitted -
     * exactly as before. ONE query.
     *
     * @return array<string, array{trips: int, total_cost: float}>
     */
    public function monthlyTrips(int $userId, int $months = 6): array;

    /**
     * The passenger's most recent bookings, newest first, as response-shaped arrays.
     *
     * Keys: `id`, `date` (Y-m-d created date), `route_from`, `route_to`, `driver` (trimmed full name or null),
     * `seats`, `price_per_seat` (float, raw), `status`, `departure_time` (ISO-8601 or null). `total_cost` is
     * deliberately NOT here - the caller multiplies and formats it through `Money`, as it did before.
     *
     * The eager loads are the same three queries the caller's own `with([...])` issued.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentTrips(int $userId, int $limit = 10): array;

    /**
     * Whether this passenger has a COMPLETED booking on this ride with this driver - the eligibility gate for
     * rating or commenting on a driver. ONE query, the same `exists()`.
     */
    public function hasCompletedBookingWithDriver(int $userId, int $rideId, int $driverId): bool;
}
