<?php

namespace App\Services\Ride;

use App\Models\Booking;
use App\Models\Ride;
use Illuminate\Support\Collection;

/**
 * AF-7 - the "rides as driver / bookings as passenger" status rollup, which was duplicated.
 *
 * These two queries were written out VERBATIM in two controllers, differing only in where the user
 * id came from:
 *
 *   ProfileController:413/426            StaffOperationsController:132/144
 *   Ride::where('driver_id', ...)        Ride::where('driver_id', ...)
 *     ->selectRaw('status, COUNT(*)')     ->selectRaw('status, COUNT(*)')
 *     ->groupBy('status')                  ->groupBy('status')
 *     ->pluck('count', 'status')           ->pluck('count', 'status')
 *
 * Identical SQL, two homes. The RESHAPING differs and deliberately stays in the controllers -
 * ProfileController exposes `total_created`/`total_booked`/`no_show`, StaffOperations exposes
 * `total` - because those are different public response shapes and AGENTS.md reserves changing a
 * public API shape. Only the query is shared here.
 *
 * Each call is ONE grouped query rather than one query per status, which is the property worth
 * preserving: if this is ever "simplified" into a loop of count() calls it becomes an N+1.
 *
 * Returns a Collection keyed by status string, so callers keep using `->get('finished', 0)` and an
 * absent status reads 0 exactly as it did before.
 */
class UserRideStatsService
{
    /**
     * Rides this user created, counted by ride status.
     *
     * @return Collection<string, int|float>
     */
    public function ridesAsDriver(int $userId): Collection
    {
        return Ride::where('driver_id', $userId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');
    }

    /**
     * Bookings this user holds, counted by booking status.
     *
     * @return Collection<string, int|float>
     */
    public function bookingsAsPassenger(int $userId): Collection
    {
        return Booking::where('user_id', $userId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');
    }
}
