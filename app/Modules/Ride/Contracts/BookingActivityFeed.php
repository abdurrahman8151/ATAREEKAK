<?php

namespace App\Modules\Ride\Contracts;

/**
 * The recent-bookings feed behind the admin dashboard's "recent activity" panel.
 *
 * WHY ROWS AND NOT SCALARS HERE (Stage 2 C2). Unlike `BookingRevenueAggregates`, this one returns the rows
 * themselves - the panel needs the passenger, the driver, the route, the status, the moment and the value, and
 * every one of those is FORMATTED by the caller (a masked phone number, an RTL route arrow, a `Today, 14:05`
 * label, a `Money` string or `---`). So the port returns raw fields and the caller keeps its presentation; that
 * is also why `total_value` is not computed here - `seats x price_per_seat` is the caller's arithmetic on the
 * two raw numbers it needs for the money format.
 *
 * The eager loads are the caller's own: bookings, their ride, the ride's driver, the passenger - so the query
 * count is unchanged.
 */
interface BookingActivityFeed
{
    /**
     * The most recent bookings, newest first, as raw rows.
     *
     * Keys: `booking_id`, `status`, `seats`, `communication_number`, `created_at`, `user_first_name`,
     * `user_last_name`, `driver_first_name`, `driver_last_name`, `pickup_address`, `destination_address`,
     * `price_per_seat`.
     *
     * `created_at` arrives as the Carbon instance the row's cast produces, because the caller labels it with
     * `isToday()`/`isYesterday()`/`toIso8601String()` exactly as it did before. A booking whose ride or
     * passenger is missing yields nulls in those keys rather than being skipped - the caller's fallbacks
     * (`'---'`, an empty name, an em dash) depend on the row existing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentActivities(int $limit = 10): array;
}
