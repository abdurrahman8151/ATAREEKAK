<?php

namespace App\Modules\Ride\Services;

use App\Modules\Ride\Models\Ride;
use App\Modules\Ride\Contracts\DriverRideReads;

/**
 * The driver-profile ride reads behind {@see DriverRideReads}.
 *
 * Both statements are the ones Console ran inline, character for character - the same `withCount('bookings')`,
 * the same `orderByDesc('created_at')`, the same `limit`, the same `whereNotNull`. The row formatting moved with
 * the query rather than being reimplemented, because the payload it produces is pinned by a frozen response
 * snapshot and by the admin driver unit tests.
 */
final class DriverRideReadsService implements DriverRideReads
{
    public function recentRidesForDriver(int $driverId, int $limit = 5): array
    {
        return Ride::withCount('bookings')
            ->where('driver_id', $driverId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'pickup_address' => $r->pickup_address,
                'destination_address' => $r->destination_address,
                'departure_time' => $r->departure_time->toIso8601String(),
                'status' => $r->status,
                'bookings_count' => $r->bookings_count,
            ])->values()->all();
    }

    public function latestCommunicationNumberForDriver(int $driverId): ?string
    {
        return Ride::where('driver_id', $driverId)
            ->whereNotNull('communication_number')
            ->orderByDesc('created_at')
            ->value('communication_number');
    }

    /**
     * @return array{total_rides: int, completed_rides: int, cancelled_rides: int, cancel_rate: float}
     */
    public function rideStatsForDriver(int $driverId): array
    {
        $totalRides = Ride::where('driver_id', $driverId)->count();
        $completedRides = Ride::where('driver_id', $driverId)->where('status', 'finished')->count();
        $cancelledRides = Ride::where('driver_id', $driverId)->where('status', 'cancelled')->count();

        return [
            'total_rides' => $totalRides,
            'completed_rides' => $completedRides,
            'cancelled_rides' => $cancelledRides,
            'cancel_rate' => $totalRides > 0
                ? round(($cancelledRides / $totalRides) * 100, 1)
                : 0.0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dashboardRecentRidesForDriver(int $driverId, int $limit = 10): array
    {
        return Ride::where('driver_id', $driverId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'status', 'pickup_address', 'destination_address', 'price_per_seat', 'departure_time'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'source' => $r->pickup_address,
                'destination' => $r->destination_address,
                'price_per_seat' => $r->price_per_seat,
                'date' => $r->departure_time->toIso8601String(),
            ])->values()->all();
    }

    /**
     * @return array{name: string, visit_count: mixed}|null
     */
    public function favoriteDestinationForDriver(int $driverId): ?array
    {
        $row = Ride::where('driver_id', $driverId)
            ->where('status', 'finished')
            ->select('destination_address')
            ->selectRaw('COUNT(*) as visit_count')
            ->groupBy('destination_address')
            ->orderByDesc('visit_count')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'name' => $row->destination_address,
            'visit_count' => $row->visit_count,
        ];
    }
}
