<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ride Resource
 *
 * FIXED: Uses pre-loaded data instead of calling relationships
 *
 * Make sure to eager load:
 * - driver, ideally with withAvg('receivedRatings as driver_rating', 'rating')
 * - driver.profile
 * - withCount(['bookings as total_booked_seats' => ...])
 */
class RideResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,

            'driver' => [
                'id' => $this->driver->id,
                'name' => trim($this->driver->first_name . ' ' . $this->driver->last_name),
                'avatar' => $this->driver->profile?->profile_photo
                    ? asset('storage/' . $this->driver->profile->profile_photo)
                    : $this->driver->avatar,
                'rating' => $this->driverRating(),
                'score' => $this->driver->userScore->score ?? null,
            ],

            'pickup' => [
                'address' => $this->pickup_address,
                'coordinates' => $this->pickup_location ? [
                    'lat' => $this->pickup_location['lat'],
                    'lng' => $this->pickup_location['lng'],
                ] : null,
            ],

            'destination' => [
                'address' => $this->destination_address,
                'coordinates' => $this->destination_location ? [
                    'lat' => $this->destination_location['lat'],
                    'lng' => $this->destination_location['lng'],
                ] : null,
            ],

            'departure_time' => $this->departure_time->toIso8601String(),
            'departure_time_human' => $this->departure_time->format('M j, Y \a\t g:i A'),

            'seats' => [
                'available' => $this->available_seats,
                // FIXED: Use pre-loaded count instead of querying
                'booked' => (int) ($this->total_booked_seats ?? 0),
                'total' => $this->available_seats + (int) ($this->total_booked_seats ?? 0),
            ],

            'price_per_seat' => $this->price_per_seat,
            'status' => $this->status,
            'match_score' => $this->when(isset($this->match_score), fn () => $this->match_score),

            'distance' => [
                'meters' => $this->distance,
                'kilometers' => round($this->distance / 1000, 1),
            ],

            'duration' => [
                'seconds' => $this->duration,
                'minutes' => round($this->duration / 60),
                'human' => $this->formatDuration($this->duration),
            ],

            'vehicle_type' => $this->vehicle_type,
            'payment_method' => $this->payment_method,
            'booking_type' => $this->booking_type,
            'communication_number' => $this->communication_number,
            'notes' => $this->notes,

            'route' => [
                'index' => $this->chosen_route_index,
                'geometry' => $this->route_geometry,
            ],

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    /**
     * Average rating of the driver, out of 5.
     *
     * Ratings live in user_ratings, never on the users table. List endpoints
     * pre-aggregate them into the driver_rating alias; single-ride endpoints
     * that skip that fall back to the accessor, which costs one AVG query.
     */
    private function driverRating(): float
    {
        $rating = $this->driver->driver_rating ?? $this->driver->average_rating;

        return round((float) $rating, 1);
    }

    /**
     * Format duration in human-readable format
     */
    private function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }
}
