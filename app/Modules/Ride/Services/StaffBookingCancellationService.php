<?php

namespace App\Modules\Ride\Services;

use App\Models\Booking;
use App\Modules\Ride\Contracts\StaffBookingCancellation;
use Illuminate\Database\Eloquent\Collection;

/**
 * The booking writes and lookup behind {@see StaffBookingCancellation} - the three statements Console ran,
 * verbatim: the `with(['ride','user'])` find-or-fail, the status flip, and the `whereIn('id', [...])->get()`
 * collection the refund is handed.
 */
final class StaffBookingCancellationService implements StaffBookingCancellation
{
    public function findForCancellation(int $bookingId): mixed
    {
        return Booking::with(['ride', 'user'])->findOrFail($bookingId);
    }

    public function markCancelled(int $bookingId): void
    {
        Booking::where('id', $bookingId)->update(['status' => 'cancelled']);
    }

    public function forRefund(int $bookingId): Collection
    {
        return Booking::whereIn('id', [$bookingId])->get();
    }
}
