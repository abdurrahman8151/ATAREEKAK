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
 */
class BookingResource extends JsonResource
{
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
                'avatar' => $this->user->profile?->profile_photo
                    ? asset('storage/'.$this->user->profile->profile_photo)
                    : $this->user->avatar,
                'rating' => $this->user->passenger_rating ?? 0,
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
                    'avatar' => $this->ride->driver->profile?->profile_photo
                        ? asset('storage/'.$this->ride->driver->profile->profile_photo)
                        : $this->ride->driver->avatar,
                    'communication_number' => $this->ride->communication_number,
                ],
            ],

            'total_price' => $this->seats * $this->ride->price_per_seat,

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),

            'completed_at' => $this->completed_at?->toIso8601String(),
            'passenger_confirmed_at' => $this->passenger_confirmed_at?->toIso8601String(),
        ];
    }
}
