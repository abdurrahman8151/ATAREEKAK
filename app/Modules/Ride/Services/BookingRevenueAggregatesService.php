<?php

namespace App\Modules\Ride\Services;

use App\Models\Booking;
use App\Modules\Ride\Contracts\BookingRevenueAggregates;

/**
 * The booking money projections behind {@see BookingRevenueAggregates} - the three Console expressions, verbatim.
 *
 * The share rate stays read from `config('fees.driver_share_rate')` inside the SQL, exactly as it was, so the
 * number and its single definition are unchanged.
 */
final class BookingRevenueAggregatesService implements BookingRevenueAggregates
{
    public function estimatedGrossForDriver(int $driverId): float
    {
        $total = Booking::join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->where('rides.driver_id', $driverId)
            ->where('bookings.status', 'completed')
            ->selectRaw('SUM(bookings.seats * rides.price_per_seat * ?) as total', [(float) config('fees.driver_share_rate', 0.95)])
            ->value('total') ?? 0.0;

        return (float) $total;
    }

    public function completedBookingsRevenue(): float
    {
        return (float) Booking::where('bookings.status', 'completed')
            ->join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->selectRaw('SUM(bookings.seats * rides.price_per_seat) as total')
            ->value('total');
    }

    public function lockedFundsInActiveRides(): float
    {
        return (float) Booking::whereHas('ride', fn ($q) => $q->whereIn('status', ['active', 'full', 'awaiting_confirmation'])
        )
            ->join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->where('bookings.status', 'confirmed')
            ->selectRaw('SUM(bookings.seats * rides.price_per_seat) as total')
            ->value('total');
    }
}
