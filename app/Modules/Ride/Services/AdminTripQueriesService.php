<?php

namespace App\Modules\Ride\Services;

use App\Models\Ride;
use App\Modules\Ride\Contracts\AdminTripQueries;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The trip queries behind {@see AdminTripQueries} - moved VERBATIM from Console.
 *
 * Nothing here is reformatted or "improved": the eager loads, the `select` column lists on the relations, the
 * filter `match`, the `orderByDesc('departure_time')`, the popular-routes join with its tiebreaker and every
 * limit are the code that was already running. The frozen `admin/trips`, `admin/trips-live`,
 * `admin/routes-popular` and staff-trip snapshots are the evidence, not this comment.
 */
final class AdminTripQueriesService implements AdminTripQueries
{
    public function paginateTrips(string $filter, int $perPage, int $page): LengthAwarePaginator
    {
        $now = Carbon::now();

        $query = Ride::with([
            'driver:id,first_name,last_name',
            'driver.profile:user_id,profile_photo',
            // Only load booked seats that count toward capacity
            'bookings' => fn ($q) => $q
                ->select('id', 'ride_id', 'user_id', 'seats', 'status')
                ->whereIn('status', ['confirmed', 'completed']),
        ]);

        match ($filter) {
            'scheduled' => $query->where('status', 'active')->where('departure_time', '>', $now),
            'active' => $query->where('status', 'active')->where('departure_time', '<=', $now),
            'completed' => $query->where('status', 'finished'),
            'cancelled' => $query->where('status', 'cancelled'),
            'awaiting' => $query->where('status', 'awaiting_confirmation'),
            default => $query->whereIn('status', ['active', 'finished', 'cancelled', 'awaiting_confirmation']),
        };

        return $query
            ->orderByDesc('departure_time')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function statusCounts(Carbon $now): array
    {
        return [
            'all' => Ride::whereIn('status', ['active', 'finished', 'cancelled', 'awaiting_confirmation'])->count(),
            'scheduled' => Ride::where('status', 'active')->where('departure_time', '>', $now)->count(),
            'active' => Ride::where('status', 'active')->where('departure_time', '<=', $now)->count(),
            'completed' => Ride::where('status', 'finished')->count(),
            'cancelled' => Ride::where('status', 'cancelled')->count(),
            'awaiting' => Ride::where('status', 'awaiting_confirmation')->count(),
        ];
    }

    public function liveTrips(Carbon $now): Collection
    {
        return Ride::with([
            'driver:id,first_name,last_name',
            'driver.profile:user_id,profile_photo',
            'bookings' => fn ($q) => $q
                ->where('status', 'confirmed')
                ->with('user:id,first_name,last_name'),
        ])
            ->where('status', 'active')
            ->where('departure_time', '<=', $now)
            ->orderBy('departure_time')
            ->get();
    }

    public function popularRoutes(int $limit = 10): array
    {
        $rows = Ride::select(
            'rides.pickup_address',
            'rides.destination_address',
            DB::raw('COUNT(*) as trip_count'),
            DB::raw('COALESCE(SUM(b.seats), 0) as total_passengers'),
        )
            ->leftJoin(
                DB::raw("(
        SELECT ride_id, SUM(seats) as seats
        FROM bookings
        WHERE status IN ('confirmed', 'completed')
        GROUP BY ride_id
    ) as b"),
                'b.ride_id', '=', 'rides.id'
            )
            ->whereNotIn('rides.status', ['cancelled'])
            ->groupBy('rides.pickup_address', 'rides.destination_address')
            ->orderByDesc('trip_count')
            // The tiebreaker, kept because it is not cosmetic: trip_count alone left two destinations with
            // the same count in whatever order MySQL's grouping produced (stage 0.5's order matrix caught it).
            // destination_address is a grouping column, so ordering by it is stable. It only ever decides ties.
            ->orderBy('rides.destination_address')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row): array => [
            'from' => $row->pickup_address,
            'to' => $row->destination_address,
            'trip_count' => (int) $row->trip_count,
            'total_passengers' => (int) $row->total_passengers,
        ])->values()->all();
    }
}
