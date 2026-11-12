<?php

namespace App\Modules\Ride\Contracts;

use Carbon\Carbon;

/**
 * The fleet-wide trip counters the admin report publishes.
 *
 * WHY A PORT AND NOT A QUERY (Stage 2 C2): every method here is a COUNT over the `rides` table, and `rides` is
 * Ride's. Console used to build those counts itself, which meant naming `App\Models\Ride` - the one thing a module
 * may not do to another module. The counts are also the whole surface: no report screen reads a ride row, so this
 * contract returns numbers only.
 *
 * ONE QUERY PER METHOD, deliberately. `statistics()` issues five (total plus one per status), which is what the
 * inline code did; folding them into one grouped query would move the admin report's recorded query budget, and a
 * budget may only shrink.
 *
 * The status vocabulary stays HERE: callers ask for "active trips" or "completed trips", never for
 * `status = 'active'`. The frozen enum values (`active`, `finished`, `cancelled`, `awaiting_confirmation`) are
 * Ride's domain language and no caller has to know them to read a number.
 */
interface RideReportCounts
{
    /** Trips currently `active` - i.e. departed but not finished, cancelled or awaiting confirmation. */
    public function activeTrips(): int;

    /** Trips in the `finished` state. */
    public function completedTrips(): int;

    /**
     * Trips that FINISHED inside the window, measured on `finished_at`.
     *
     * The window is closed on both ends (a month is passed as its start and end), exactly as the inline
     * `whereBetween('finished_at', ...)` had it.
     */
    public function completedTripsBetween(Carbon $start, Carbon $end): int;

    /**
     * The five trip counters the report's statistics block publishes.
     *
     * `total` counts every trip (optionally limited by `created_at` when a window is given); the other four
     * apply the same window and then one status each. Keys are the ones the response already had.
     *
     * @return array{total: int, active: int, completed: int, cancelled: int, awaiting_confirmation: int}
     */
    public function statistics(?Carbon $start = null, ?Carbon $end = null): array;
}
