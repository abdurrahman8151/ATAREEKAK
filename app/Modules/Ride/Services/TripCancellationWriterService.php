<?php

namespace App\Modules\Ride\Services;

use App\Models\Booking;
use App\Models\Ride;
use App\Modules\Ride\Contracts\TripCancellationWriter;

/**
 * The trip writes behind {@see TripCancellationWriter}.
 *
 * `findForCancellation()` is the old `Ride::findOrFail()` verbatim. `markCancelled()` is the old
 * `$ride->update(['status' => 'cancelled'])` as a query-builder update, which writes the same row, the same
 * `updated_at` and the same value - there is no Ride observer or model listener in this codebase, so the only
 * difference from the model path is the absence of events nobody listens to. `restoreSeats()` is the old
 * `increment` plus the conditional `'full' -> 'active'` write, with the status passed in so no extra `select`
 * appears. `cancelledBookings()` runs the same count the relation produced.
 */
final class TripCancellationWriterService implements TripCancellationWriter
{
    public function findForCancellation(int $rideId): mixed
    {
        return Ride::findOrFail($rideId);
    }

    public function markCancelled(int $rideId): void
    {
        Ride::where('id', $rideId)->update(['status' => 'cancelled']);
    }

    public function restoreSeats(int $rideId, int $seats, string $rideStatus): void
    {
        Ride::where('id', $rideId)->increment('available_seats', $seats);

        // If the ride was full, set it back to active.
        if ($rideStatus === 'full') {
            Ride::where('id', $rideId)->update(['status' => 'active']);
        }
    }

    public function cancelledBookings(int $rideId): int
    {
        return Booking::where('ride_id', $rideId)
            ->where('status', 'cancelled')
            ->count();
    }
}
