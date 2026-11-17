<?php

namespace App\Modules\Ride\Services;

use App\Modules\Ride\Models\Booking;
use App\Modules\Ride\Contracts\PassengerBookingHistory;

/**
 * The passenger-booking reads behind {@see PassengerBookingHistory} - moved VERBATIM from Identity's
 * `PassengerProfilePanelBuilder` and `ProfileInteractionService`.
 *
 * Nothing here is re-worded: the aggregates, the join, the `DATE_FORMAT` grouping, the eager-load column lists,
 * the `orderByDesc`/`limit` and the eligibility `exists()` are the statements that were already running. The
 * money and month formatting stayed with the caller.
 */
final class PassengerBookingHistoryService implements PassengerBookingHistory
{
    public function completedRidesCount(int $userId): int
    {
        return Booking::where('user_id', $userId)
            ->whereIn('status', ['completed'])
            ->count();
    }

    public function completedSpending(int $userId): float
    {
        $total = Booking::join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->where('bookings.user_id', $userId)
            ->where('bookings.status', 'completed')
            ->selectRaw('SUM(bookings.seats * rides.price_per_seat) as total')
            ->value('total') ?? 0.0;

        return (float) $total;
    }

    /**
     * @return array<string, array{trips: int, total_cost: float}>
     */
    public function monthlyTrips(int $userId, int $months = 6): array
    {
        $rows = Booking::join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->where('bookings.user_id', $userId)
            ->where('bookings.status', 'completed')
            ->where('rides.departure_time', '>=', now()->subMonths($months)->startOfMonth())
            ->selectRaw(
                "DATE_FORMAT(rides.departure_time, '%Y-%m') as month_key,
                 COUNT(*) as trips,
                 SUM(bookings.seats * rides.price_per_seat) as total_cost"
            )
            ->groupBy('month_key')
            ->orderBy('month_key')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->month_key] = [
                'trips' => (int) $row->trips,
                'total_cost' => (float) $row->total_cost,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentTrips(int $userId, int $limit = 10): array
    {
        return Booking::with([
            'ride:id,pickup_address,destination_address,departure_time,price_per_seat,driver_id',
            'ride.driver:id,first_name,last_name',
        ])
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'date' => $b->created_at->toDateString(),
                'route_from' => $b->ride?->pickup_address,
                'route_to' => $b->ride?->destination_address,
                'driver' => $b->ride?->driver
                    ? trim("{$b->ride->driver->first_name} {$b->ride->driver->last_name}")
                    : null,
                'seats' => $b->seats,
                'price_per_seat' => (float) ($b->ride?->price_per_seat ?? 0),
                'status' => $b->status,
                'departure_time' => $b->ride?->departure_time?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function hasCompletedBookingWithDriver(int $userId, int $rideId, int $driverId): bool
    {
        return Booking::query()
            ->where('user_id', $userId)
            ->where('ride_id', $rideId)
            ->where('status', 'completed')
            ->whereHas('ride', fn ($q) => $q->where('driver_id', $driverId))
            ->exists();
    }
}
