<?php

namespace App\Modules\Ride\Contracts;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The trip queries behind the admin and staff trip screens.
 *
 * WHY THE FORMATTING DID NOT MOVE WITH THEM (Stage 2 C2). The screens' payloads - `trip_ref`, the driver block,
 * the route block, the UI status, the timing label - are the back office's presentation of a trip, not the ride
 * itself, and they are pinned by frozen response snapshots for three endpoints. Moving them into Ride would put a
 * second module's response shape inside this one for no ownership gain. So the port returns ROWS (and counts) and
 * the caller keeps formatting them; the caller's formatter methods simply take their ride argument UNTYPED, which
 * is the established way in this codebase to hold a value from another module without naming its class.
 *
 * Every method issues exactly the statements the inline code did - the same eager loads, the same filters, the
 * same ORDER BY and tiebreaker, the same limits - so the recorded query budgets do not move.
 *
 * The status vocabulary stays here: callers pass a screen filter name (`scheduled`, `active`, `completed`,
 * `cancelled`, `awaiting`, `all`), never a raw `status` value.
 */
interface AdminTripQueries
{
    /**
     * A page of trips for one tab, with the driver, the driver's profile photo and the capacity-counting
     * bookings eager-loaded.
     *
     * Filters: `scheduled` (departed later), `active` (departed already), `completed`, `cancelled`,
     * `awaiting`, or anything else for every trip that is not purely `pending`.
     *
     * @param  int  $perPage  1-50
     */
    public function paginateTrips(string $filter, int $perPage, int $page): LengthAwarePaginator;

    /**
     * The six per-tab counters. `$now` is passed in rather than read here so every count on one request is
     * measured against the same instant the caller's list filter used.
     *
     * @return array{all: int, scheduled: int, active: int, completed: int, cancelled: int, awaiting: int}
     */
    public function statusCounts(Carbon $now): array;

    /**
     * Trips on the road right now - `active` and already departed - oldest departure first, with the driver,
     * the driver's profile and the confirmed bookings (and their users) eager-loaded.
     */
    public function liveTrips(Carbon $now): Collection;

    /**
     * The most-ridden routes, busiest first, ties broken by destination address.
     *
     * Returns RAW grouped rows - `from`, `to`, `trip_count`, `total_passengers` - because the demand
     * percentage and its banding are the caller's presentation of these numbers, computed from the set.
     *
     * @return array<int, array{from: string, to: string, trip_count: int, total_passengers: int}>
     */
    public function popularRoutes(int $limit = 10): array;
}
