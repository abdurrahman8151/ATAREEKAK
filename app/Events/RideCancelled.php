<?php

namespace App\Events;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Ride Cancelled Event
 *
 * FIXED: Explicit data arrays
 */
class RideCancelled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Ride $ride,
        public readonly array $bookings,
        public readonly User $driver
    ) {}

    /**
     * Get the channels the event should broadcast on
     */
    public function broadcastOn(): array
    {
        $channels = [
            // T2-7: was a public Channel('rides'); private so the client must
            // authorize through /broadcasting/auth (jwt) before subscribing.
            new PrivateChannel('rides'),
            new PrivateChannel('user.'.$this->driver->id),
        ];

        // Add private channels for each affected passenger
        foreach ($this->bookings as $booking) {
            $channels[] = new PrivateChannel('user.'.$booking['user_id']);
        }

        return $channels;
    }

    /**
     * Get the event name for broadcasting
     */
    public function broadcastAs(): string
    {
        return 'ride.cancelled';
    }

    /**
     * Get the data to broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'ride' => [
                'id' => $this->ride->id,
                'pickup_address' => $this->ride->pickup_address,
                'destination_address' => $this->ride->destination_address,
                'departure_time' => $this->ride->departure_time->toIso8601String(),
            ],
            'driver' => [
                'id' => $this->driver->id,
                'name' => $this->driver->first_name.' '.$this->driver->last_name,
            ],
            'affected_bookings_count' => count($this->bookings),
            'cancellation_time' => now()->toIso8601String(),
        ];
    }
}
