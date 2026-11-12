<?php

namespace App\Modules\Ride\Services;

use App\Models\Ride;
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
}
