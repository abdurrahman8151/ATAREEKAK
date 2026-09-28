<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Ride Booked Event
 *
 * FIXED: Explicit data arrays instead of only() to prevent accidental exposure
 */
class RideBooked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Ride $ride,
        public readonly Booking $booking,
        public readonly User $passenger
    ) {}

    /**
     * Get the channels the event should broadcast on
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->ride->driver_id),
            new PrivateChannel('user.'.$this->passenger->id),
        ];
    }

    /**
     * Get the event name for broadcasting
     */
    public function broadcastAs(): string
    {
        return 'ride.booked';
    }

    /**
     * Get the data to broadcast
     *
     * FIXED: Explicit arrays prevent accidental data exposure
     */
    public function broadcastWith(): array
    {
        return [
            'ride' => [
                'id' => $this->ride->id,
                'pickup_address' => $this->ride->pickup_address,
                'destination_address' => $this->ride->destination_address,
                'departure_time' => $this->ride->departure_time->toIso8601String(),
                'available_seats' => $this->ride->available_seats,
            ],
            'booking' => [
                'id' => $this->booking->id,
                'seats' => $this->booking->seats,
                'status' => $this->booking->status,
                'created_at' => $this->booking->created_at->toIso8601String(),
            ],
            'passenger' => [
                'id' => $this->passenger->id,
                'name' => $this->passenger->first_name.' '.$this->passenger->last_name,
            ],
        ];
    }
}
