<?php

namespace App\Modules\Ride\Services;

use App\Modules\Ride\Models\Ride;
use App\Modules\Ride\Contracts\RideQueryExpressions;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ride expressions behind {@see RideQueryExpressions}.
 *
 * Every statement here is the one that used to be written inline in Console, character for character:
 * the same `status = 'finished'` filter, the same `count(*)`, the same `whereNotNull`, the same
 * `orderByDesc('created_at')` and the same `limit(1)`. Nothing is added, renamed or reordered - the admin
 * driver table's JSON and its query count are pinned by snapshots, and the whole point of moving these
 * three expressions behind a contract is that the move is invisible.
 */
final class RideQueryExpressionsService implements RideQueryExpressions
{
    public function completedRidesForDriver(int $driverId): int
    {
        return Ride::where('driver_id', $driverId)
            ->where('status', 'finished')
            ->count();
    }

    public function rideCountSubquery(string $ownerKeyColumn): Builder
    {
        return Ride::query()
            ->selectRaw('count(*)')
            ->whereColumn('rides.driver_id', $ownerKeyColumn);
    }

    public function latestCommunicationNumberSubquery(string $ownerKeyColumn): Builder
    {
        return Ride::query()
            ->select('communication_number')
            ->whereColumn('rides.driver_id', $ownerKeyColumn)
            ->whereNotNull('communication_number')
            ->orderByDesc('created_at')
            ->limit(1);
    }
}
