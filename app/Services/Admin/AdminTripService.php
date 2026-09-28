<?php

namespace App\Services\Admin;

use App\Models\Ride;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * AdminTripService
 *
 * Provides all data for the admin trips dashboard page.
 *
 * Design — mirrors AdminReportService intentionally:
 *   - Queries Eloquent models directly (admin aggregations don't fit
 *     the CRUD repository contract).
 *   - Each public method is a single, self-contained data slice.
 *   - Private helpers keep each public method readable.
 *
 * ─── Status semantics used throughout ───────────────────────────────────────
 *  "scheduled" → DB status = 'active'  AND departure_time >  now()
 *  "active"    → DB status = 'active'  AND departure_time <= now()
 *  "completed" → DB status = 'finished'
 *  "cancelled" → DB status = 'cancelled'
 *  "awaiting"  → DB status = 'awaiting_confirmation'
 *  "all"       → all of the above
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class AdminTripService
{
    // =========================================================================
    // TRIP LIST  (paginated, filterable)
    // =========================================================================

    /**
     * Paginated trip list for the admin table.
     *
     * @param  string  $filter  one of: all | active | scheduled | completed | cancelled
     * @param  int  $perPage  1-50
     */
    public function getFilteredTrips(
        string $filter = 'all',
        int $perPage = 15,
        int $page = 1,
    ): LengthAwarePaginator {
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
            'awaiting' => $query->where('status', 'awaiting_confirmation'), // ← ADD THIS
            default => $query->whereIn('status', ['active', 'finished', 'cancelled', 'awaiting_confirmation']),
        };

        return $query
            ->orderByDesc('departure_time')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Shape a single Ride model into the array the controller will return.
     * Kept separate so the controller loop stays one-liner.
     */
    public function formatTrip(Ride $ride): array
    {
        $now = Carbon::now();
        $driver = $ride->driver;

        $bookedSeats = $ride->bookings->sum('seats');
        $totalSeats = $ride->available_seats + $bookedSeats;

        return [
            'id' => $ride->id,
            'trip_ref' => '#TR-'.$ride->id,

            'driver' => [
                'id' => $driver?->id,
                'name' => trim(($driver?->first_name ?? '').' '.($driver?->last_name ?? '')),
                'profile_photo' => $driver?->profile?->profile_photo
                    ? asset('storage/'.$driver->profile->profile_photo)
                    : null,
            ],

            'route' => [
                'from' => $ride->pickup_address,
                'to' => $ride->destination_address,
            ],

            'timing' => [
                'departure_time' => $ride->departure_time->toIso8601String(),
                'label' => $this->timingLabel($ride->departure_time, $now),
                'time_only' => $ride->departure_time->format('H:i'),
            ],

            'passengers' => [
                'booked' => $bookedSeats,
                'total' => $totalSeats,
                'label' => "{$bookedSeats}/{$totalSeats}",
            ],

            'status' => $this->resolveUiStatus($ride, $now),
            'payment_method' => $ride->payment_method,
            'vehicle_type' => $ride->vehicle_type,
            'price_per_seat' => $ride->price_per_seat,
        ];
    }

    // =========================================================================
    // STATUS COUNTS  (tab badge numbers)
    // =========================================================================

    /**
     * Returns per-filter counts for the tab badges.
     * Runs 5 lean COUNT queries instead of loading models.
     */
    public function getStatusCounts(): array
    {
        $now = Carbon::now();

        return [
            'all' => Ride::whereIn('status', ['active', 'finished', 'cancelled', 'awaiting_confirmation'])->count(),
            'scheduled' => Ride::where('status', 'active')->where('departure_time', '>', $now)->count(),
            'active' => Ride::where('status', 'active')->where('departure_time', '<=', $now)->count(),
            'completed' => Ride::where('status', 'finished')->count(),
            'cancelled' => Ride::where('status', 'cancelled')->count(),
            'awaiting' => Ride::where('status', 'awaiting_confirmation')->count(), // ← ADD THIS
        ];
    }

    // =========================================================================
    // POPULAR ROUTES  (route monitoring)
    // =========================================================================

    /**
     * Most-ridden routes sorted by trip count descending.
     *
     * Groups by the stored address strings (already normalised at ride-creation
     * time by GeocodingService::reverseGeocode), so no fuzzy matching is needed.
     *
     * @param  int  $limit  1-50
     */
    public function getPopularRoutes(int $limit = 10): array
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
            ->limit($limit)
            ->get();
        $maxCount = $rows->max('trip_count') ?: 1;

        return $rows->map(function ($row) use ($maxCount): array {
            $pct = ($row->trip_count / $maxCount) * 100;

            return [
                'from' => $row->pickup_address,
                'to' => $row->destination_address,
                'trip_count' => (int) $row->trip_count,
                'total_passengers' => (int) $row->total_passengers,
                'demand_percentage' => (int) round($pct),
                'demand_level' => $this->demandLevel($pct),
            ];
        })->values()->all();
    }

    // =========================================================================
    // TOP DRIVERS
    // =========================================================================

    /**
     * Verified drivers sorted by average rating, highest first.
     *
     * Drivers with no ratings yet are placed last (NULL LAST).
     *
     * @param  int  $limit  1-50
     */
    public function getTopDrivers(int $limit = 10): array
    {
        $drivers = User::select(
            'users.id',
            'users.first_name',
            'users.last_name',
            DB::raw('AVG(user_ratings.rating) as avg_rating'),
            DB::raw('COUNT(DISTINCT user_ratings.id) as rating_count'),
            DB::raw('COUNT(DISTINCT finished_rides.id) as completed_rides'), // ← count from rides table
        )
            ->leftJoin('user_ratings', 'user_ratings.rated_user_id', '=', 'users.id')
            ->leftJoin(
                DB::raw("(SELECT id, driver_id FROM rides WHERE status = 'finished') as finished_rides"),
                'finished_rides.driver_id', '=', 'users.id'
            )
            ->where('users.is_verified_driver', true)
            ->with('profile:user_id,profile_photo')
            ->groupBy('users.id', 'users.first_name', 'users.last_name')
            ->orderByRaw('AVG(user_ratings.rating) DESC')
            ->limit($limit)
            ->get();

        return $drivers->values()->map(function (User $driver, int $index): array {
            return [
                'rank' => $index + 1,
                'id' => $driver->id,
                'name' => trim("{$driver->first_name} {$driver->last_name}"),
                'profile_photo' => $driver->profile?->profile_photo
                    ? asset('storage/'.$driver->profile->profile_photo)
                    : null,
                'avg_rating' => $driver->avg_rating !== null
                    ? round((float) $driver->avg_rating, 1)
                    : null,
                'rating_count' => (int) $driver->rating_count,
                'total_rides' => (int) $driver->completed_rides, // ← now accurate
            ];
        })->all();
    }
    // =========================================================================
    // LIVE TRIPS  (departure passed, ride not finished)
    // =========================================================================

    /**
     * Rides currently on the road:
     *   status = 'active'  AND  departure_time <= now()
     *
     * Returns full detail including route geometry and confirmed passengers,
     * so the frontend can render a map view or contact-driver panel.
     */
    public function getLiveTrips(): array
    {
        $now = Carbon::now();

        $rides = Ride::with([
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

        return $rides->map(function (Ride $ride) use ($now): array {
            $minutesElapsed = (int) $ride->departure_time->diffInMinutes($now);
            $durationMins = $ride->duration ? (int) round($ride->duration / 60) : null;
            $etaMins = $durationMins !== null
                ? max(0, $durationMins - $minutesElapsed)
                : null;

            return [
                'id' => $ride->id,
                'trip_ref' => '#TR-'.$ride->id,

                'driver' => [
                    'id' => $ride->driver?->id,
                    'name' => trim(($ride->driver?->first_name ?? '').' '.($ride->driver?->last_name ?? '')),
                    'profile_photo' => $ride->driver?->profile?->profile_photo
                        ? asset('storage/'.$ride->driver->profile->profile_photo)
                        : null,
                    'communication_number' => $ride->communication_number,
                ],

                'route' => [
                    'from' => $ride->pickup_address,
                    'to' => $ride->destination_address,
                    'pickup_coords' => $this->safeCoords($ride, 'pickup'),
                    'destination_coords' => $this->safeCoords($ride, 'destination'),
                    'geometry' => $ride->route_geometry,
                ],

                'timing' => [
                    'departure_time' => $ride->departure_time->toIso8601String(),
                    'minutes_elapsed' => $minutesElapsed,
                    'eta_minutes' => $etaMins,
                    'duration_total' => $durationMins,
                ],

                'passengers' => $ride->bookings->map(fn ($b) => [
                    'id' => $b->user?->id,
                    'name' => trim(($b->user?->first_name ?? '').' '.($b->user?->last_name ?? '')),
                    'seats' => $b->seats,
                ])->values()->all(),

                'passenger_count' => $ride->bookings->sum('seats'),
                'vehicle_type' => $ride->vehicle_type,
                'distance_km' => $ride->distance
                    ? round($ride->distance / 1000, 1)
                    : null,
            ];
        })->all();
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Translate the stored DB status + departure_time into the UI status string
     * the frontend uses for badges and filter matching.
     */
    private function resolveUiStatus(Ride $ride, Carbon $now): string
    {
        return match (true) {
            $ride->status === 'finished' => 'completed',
            $ride->status === 'cancelled' => 'cancelled',
            $ride->status === 'awaiting_confirmation' => 'awaiting',
            $ride->departure_time->gt($now) => 'scheduled',
            default => 'active',
        };
    }

    /**
     * Human-readable timing label for the table ("Today", "Tomorrow", etc.).
     */
    private function timingLabel(Carbon $departure, Carbon $now): string
    {
        return match (true) {
            $departure->isToday() => 'Today',
            $departure->isTomorrow() => 'Tomorrow',
            $departure->isYesterday() => 'Yesterday',
            default => $departure->format('d M Y'),
        };
    }

    /**
     * Demand level label from a 0-100 percentage relative to the max route count.
     */
    private function demandLevel(float $pct): string
    {
        return match (true) {
            $pct >= 75 => 'Very High',
            $pct >= 50 => 'High',
            $pct >= 25 => 'Medium',
            default => 'Low',
        };
    }

    /**
     * Safely extract coordinates from the Ride's spatial accessor.
     * Returns null instead of throwing if the DB row has no geometry.
     */
    private function safeCoords(Ride $ride, string $type): ?array
    {
        try {
            return $type === 'pickup'
                ? $ride->pickup_location
                : $ride->destination_location;
        } catch (\Throwable) {
            return null;
        }
    }
}
