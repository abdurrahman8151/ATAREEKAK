<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * City Trip Resource
 *
 * Shape for GET /api/rides/city-trips.
 *
 * Differs from RideResource on purpose:
 *  - Coordinates come from the ST_X/ST_Y aliases selected by CityTripService,
 *    NOT from the Ride model's pickup_location accessor (which issues a
 *    separate SELECT per row and would N+1 the whole page).
 *  - communication_number and route_geometry are omitted. The driver's phone
 *    number is only exposed after a booking exists, and the route polyline is
 *    far too heavy for a paginated browse list — GET /rides/{id} carries both.
 *
 * Requires the query to have selected: pickup_lat/lng, dest_lat/dest_lng,
 * driver_avg_rating, and withCount(bookings as total_booked_seats).
 */
class CityTripResource extends JsonResource
{
    public function toArray($request): array
    {
        $bookedSeats = (int) ($this->total_booked_seats ?? 0);

        return [
            'id'       => $this->id,
            'trip_ref' => '#TR-' . $this->id,

            'driver' => [
                'id'          => $this->driver?->id,
                'name'        => trim(($this->driver?->first_name ?? '') . ' ' . ($this->driver?->last_name ?? '')),
                'gender'      => $this->driver?->gender,
                'avatar'      => $this->driver?->profile?->profile_photo
                    ? asset('storage/' . $this->driver->profile->profile_photo)
                    : $this->driver?->avatar,
                'rating'      => $this->driver_avg_rating !== null
                    ? round((float) $this->driver_avg_rating, 1)
                    : null,
                'is_verified' => (bool) ($this->driver?->is_verified_driver),
            ],

            'pickup' => [
                'address'     => $this->pickup_address,
                'coordinates' => $this->pickup_lat !== null ? [
                    'lat' => (float) $this->pickup_lat,
                    'lng' => (float) $this->pickup_lng,
                ] : null,
            ],

            'destination' => [
                'address'     => $this->destination_address,
                'coordinates' => $this->dest_lat !== null ? [
                    'lat' => (float) $this->dest_lat,
                    'lng' => (float) $this->dest_lng,
                ] : null,
            ],

            'departure_time'       => $this->departure_time->toIso8601String(),
            'departure_time_human' => $this->departure_time->format('M j, Y \a\t g:i A'),

            'seats' => [
                'available' => (int) $this->available_seats,
                'booked'    => $bookedSeats,
                'total'     => (int) $this->available_seats + $bookedSeats,
            ],

            'price_per_seat' => $this->price_per_seat,
            'status'         => $this->status,

            'distance' => [
                'meters'     => $this->distance,
                'kilometers' => round($this->distance / 1000, 1),
            ],

            'duration' => [
                'seconds' => $this->duration,
                'minutes' => (int) round($this->duration / 60),
                'human'   => $this->formatDuration((int) $this->duration),
            ],

            'vehicle_type'   => $this->vehicle_type,
            'payment_method' => $this->payment_method,
            'booking_type'   => $this->booking_type,
            'notes'          => $this->notes,

            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    private function formatDuration(int $seconds): string
    {
        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
    }
}
