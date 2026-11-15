<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Booking Resource
 *
 * FIXED: Uses pre-loaded relationships
 *
 * Make sure to eager load:
 * - user
 * - user.profile
 * - ride
 * - ride.driver
 * - ride.driver.profile
 *
 * Stage 2 C2: `user.profile` / `ride.driver.profile` are Identity's and are being retired. A producer that has
 * already resolved the stored photo paths (ONE batched query) passes them as the second constructor argument,
 * keyed by user id; the resource then reads no profile relation. Without a map it keeps the previous relation
 * read, so producers convert one at a time. It deliberately names NO module contract (kernel port ceiling).
 */
class BookingResource extends JsonResource
{
    /** @var array<int, string|null>|null stored paths keyed by user id */
    private ?array $profilePhotos = null;

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
            'ride_id' => $this->ride_id,
            'status' => $this->status,
            'seats' => $this->seats,
            'communication_number' => $this->communication_number,

            'passenger' => [
                'id' => $this->user->id,
                'name' => trim($this->user->first_name.' '.$this->user->last_name),
                'avatar' => $this->avatarFor($this->user),
                // AF-4: same defect as RideResource — `passenger_rating` is not
                // a column on users, so every passenger was shown with rating 0.
                // average_rating is the single-source accessor; batch-safe.
                'rating' => round((float) $this->user->average_rating, 2),
            ],

            'ride' => [
                'id' => $this->ride->id,
                'pickup_address' => $this->ride->pickup_address,
                'destination_address' => $this->ride->destination_address,
                'departure_time' => $this->ride->departure_time->toIso8601String(),
                'price_per_seat' => $this->ride->price_per_seat,
                'payment_method' => $this->ride->payment_method,
                'vehicle_type' => $this->ride->vehicle_type,

                'driver' => [
                    'id' => $this->ride->driver->id,
                    'name' => trim($this->ride->driver->first_name.' '.$this->ride->driver->last_name),
                    'avatar' => $this->avatarFor($this->ride->driver),
                    // Row 5 (owner decision): the driver's number is shown to a passenger only
                    // after they have confirmed the booking (passenger_confirmed_at set).
                    'communication_number' => $this->passenger_confirmed_at
                        ? $this->ride->communication_number
                        : null,
                ],
            ],

            'total_price' => $this->seats * $this->ride->price_per_seat,

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),

            'completed_at' => $this->completed_at?->toIso8601String(),
            'passenger_confirmed_at' => $this->passenger_confirmed_at?->toIso8601String(),
        ];
    }

    /**
     * The public avatar for a user: the batched map when the producer supplied one, otherwise the profile
     * relation (transitional), falling back to the `users.avatar` column exactly as before.
     */
    private function avatarFor($user): ?string
    {
        $storedPhoto = $this->profilePhotos !== null
            ? ($this->profilePhotos[$user->id] ?? null)
            : $user->profile?->profile_photo;

        return $storedPhoto ? asset('storage/'.$storedPhoto) : $user->avatar;
    }
}
