<?php

namespace App\Modules\Ride\Services;

use App\Modules\Ride\Contracts\RideReportCounts;
use App\Modules\Ride\Models\Ride;
use Carbon\Carbon;

/**
 * The report counters behind {@see RideReportCounts}.
 *
 * Every statement is the inline one it replaces, unchanged: the same `status` values, the same
 * `whereBetween('finished_at', ...)` and the same optional `created_at` window on the statistics block. The
 * clone-builder shape of `statistics()` is kept as it was - one base query, then one count per status - because
 * that is five queries either way and the report's budget is pinned.
 */
final class RideReportCountsService implements RideReportCounts
{
    public function activeTrips(): int
    {
        return Ride::where('status', 'active')->count();
    }

    public function completedTrips(): int
    {
        return Ride::where('status', 'finished')->count();
    }

    public function completedTripsBetween(Carbon $start, Carbon $end): int
    {
        return Ride::where('status', 'finished')
            ->whereBetween('finished_at', [$start, $end])
            ->count();
    }

    public function statistics(?Carbon $start = null, ?Carbon $end = null): array
    {
        $q = Ride::query();

        if ($start && $end) {
            $q->whereBetween('created_at', [$start, $end]);
        }

        return [
            'total' => $q->count(),
            'active' => (clone $q)->where('status', 'active')->count(),
            'completed' => (clone $q)->where('status', 'finished')->count(),
            'cancelled' => (clone $q)->where('status', 'cancelled')->count(),
            'awaiting_confirmation' => (clone $q)->where('status', 'awaiting_confirmation')->count(),
        ];
    }
}
