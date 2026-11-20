<?php

namespace App\Modules\Ride\Services;

use App\Modules\Ride\Contracts\BookingActivityFeed;
use App\Modules\Ride\Models\Booking;

/**
 * The recent-bookings feed behind {@see BookingActivityFeed} - the caller's query, eager loads and order, with
 * the mapping reduced to raw fields so the panel's formatting stays where it was.
 */
final class BookingActivityFeedService implements BookingActivityFeed
{
    public function recentActivities(int $limit = 10): array
    {
        return Booking::with([
            'ride:id,pickup_address,destination_address,price_per_seat,status,departure_time,driver_id',
            'ride.driver:id,first_name,last_name',
            'user:id,first_name,last_name',
        ])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($booking) => [
                'booking_id' => $booking->id,
                'status' => $booking->status,
                'seats' => $booking->seats,
                'communication_number' => $booking->communication_number,
                'created_at' => $booking->created_at,
                'user_first_name' => $booking->user?->first_name,
                'user_last_name' => $booking->user?->last_name,
                'driver_first_name' => $booking->ride?->driver?->first_name,
                'driver_last_name' => $booking->ride?->driver?->last_name,
                'pickup_address' => $booking->ride?->pickup_address,
                'destination_address' => $booking->ride?->destination_address,
                'price_per_seat' => $booking->ride?->price_per_seat,
            ])
            ->values()
            ->all();
    }
}
