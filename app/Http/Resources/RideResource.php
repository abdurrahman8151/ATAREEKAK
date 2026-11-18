<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ride Resource
 *
 * FIXED: Uses pre-loaded data instead of calling relationships
 *
 * Make sure to eager load:
 * - driver
 * - driver.profile
 * - withCount(['bookings as total_booked_seats' => ...])
 *
 * Stage 2 C2: `driver.profile` is Identity's and is being retired. A producer that has already resolved the
 * drivers' stored photo paths (ONE batched query in the Ride module) passes them as the second constructor
 * argument, keyed by user id; the resource then reads no relation at all. Without a map it keeps the previous
 * relation read, so the conversion can proceed one producer at a time. The resource deliberately names NO
 * module contract: the kernel's port-usage ceiling is a hard number, and a plain `array` is what keeps this
 * file out of it.
 */
class RideResource extends JsonResource
{
    /** @var array<int, string|null>|null stored paths keyed by user id */
    private ?array $profilePhotos = null;

    /**
     * Stage 2 C2: batched rating averages keyed by user id, from Identity's
     * `UserRatingReader::averageForUsers()` (ONE grouped query per page). This replaces the
     * `driver.receivedRatings` eager load and the kernel `User::average_rating` accessor: `user_ratings` is
     * Identity's table, so a producer resolves the numbers and this resource only formats them.
     *
     * @var array<int, float>|null
     */
    private ?array $ratings = null;

    /**
     * Supply the batched rating averages for this resource's users.
     *
     * @param  array<int, float>|null  $ratings
     */
    public function withRatings(?array $ratings): static
    {
        $this->ratings = $ratings;

        return $this;
    }

    /**
     * Supply the batched stored photo paths for this resource's users.
     *
     * A fluent setter rather than a constructor argument on purpose: Laravel's `collection()` calls
     * `new static($item, $key)`, so a second constructor parameter would receive the collection KEY.
     *
     * @param  array<int, string|null>|null  $profilePhotos
     */
    public function withProfilePhotos(?array $profilePhotos): static
    {
        $this->profilePhotos = $profilePhotos;

        return $this;
    }

    public function toArray($request): array
    {
        return [
            'id' => $this->id,

            'driver' => $this->driverPayload(),

            'pickup' => $this->endpointPayload($this->pickup_address, $this->pickup_location),
            'destination' => $this->endpointPayload($this->destination_address, $this->destination_location),

            'departure_time' => $this->departure_time->toIso8601String(),
            'departure_time_human' => $this->departure_time->format('M j, Y \a\t g:i A'),

            'seats' => $this->seatsPayload(),

            'price_per_seat' => $this->price_per_seat,
            'status' => $this->status,

            'distance' => $this->distancePayload(),
            'duration' => $this->durationPayload(),

            'vehicle_type' => $this->vehicle_type,
            'payment_method' => $this->payment_method,
            'booking_type' => $this->booking_type,
            // Row 5 (owner decision): the driver's number is shown only to the ride's own driver.
            // Other viewers (ride listings, ride detail) get null; passengers see it on their
            // booking after confirmation (BookingResource).
            'communication_number' => $this->isViewedByDriver($request)
                ? $this->communication_number
                : null,
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
     * The driver block: identity, avatar and the AF-4 rating source.
     */
    private function driverPayload(): array
    {
        // Stage 2 C2: the batched map when the producer supplied one, otherwise the relation (transitional).
        $storedPhoto = $this->profilePhotos !== null
            ? ($this->profilePhotos[$this->driver->id] ?? null)
            : $this->driver->profile?->profile_photo;

        return [
            'id' => $this->driver->id,
            'name' => trim($this->driver->first_name.' '.$this->driver->last_name),
            'avatar' => $storedPhoto
                ? asset('storage/'.$storedPhoto)
                : $this->driver->avatar,
            // AF-4: `driver_rating` is not a column on users; it evaluated to null and `?? 0` silently
            // returned a rating of 0 for EVERY driver - a fake number in the API response. Stage 2 C2: the
            // average now arrives as a batched map (ONE grouped query per page) from Identity's reader, so
            // there is no relation and no per-row query; a driver with no ratings is absent from the map and
            // reads 0, exactly as the removed accessor's `?? 0` did.
            'rating' => round((float) ($this->ratings[$this->driver->id] ?? 0), 2),
        ];
    }

    /**
     * One endpoint block (pickup or destination): the address plus its coordinates, or null.
     */
    private function endpointPayload($address, $location): array
    {
        return [
            'address' => $address,
            'coordinates' => $location ? [
                'lat' => $location['lat'],
                'lng' => $location['lng'],
            ] : null,
        ];
    }

    /**
     * Seat counts, from the pre-loaded booking count.
     */
    private function seatsPayload(): array
    {
        return [
            'available' => $this->available_seats,
            // FIXED: Use pre-loaded count instead of querying
            'booked' => (int) ($this->total_booked_seats ?? 0),
            'total' => $this->available_seats + (int) ($this->total_booked_seats ?? 0),
        ];
    }

    private function distancePayload(): array
    {
        return [
            'meters' => $this->distance,
            'kilometers' => round($this->distance / 1000, 1),
        ];
    }

    private function durationPayload(): array
    {
        return [
            'seconds' => $this->duration,
            'minutes' => round($this->duration / 60),
            'human' => $this->formatDuration($this->duration),
        ];
    }

    /**
     * True only when the authenticated viewer is this ride's driver. Anyone else, including an
     * unauthenticated caller, does not see the driver's communication number (row 5).
     */
    private function isViewedByDriver($request): bool
    {
        $viewer = $request?->user();

        return $viewer !== null && (int) $viewer->id === (int) $this->driver_id;
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
