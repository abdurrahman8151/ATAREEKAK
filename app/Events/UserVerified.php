<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a user is verified as a driver or passenger.
 */
class UserVerified implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $verificationType // 'driver' or 'passenger'
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->user->id),
        ];
    }

    /**
     * Get the event name for broadcasting.
     */
    public function broadcastAs(): string
    {
        return 'user.verified';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'verification_type' => $this->verificationType,
            'is_verified_driver' => $this->user->is_verified_driver,
            'is_verified_passenger' => $this->user->is_verified_passenger,
            'verified_at' => now()->toIso8601String(),
            'message' => $this->getVerificationMessage(),
        ];
    }

    /**
     * Get a human-readable verification message.
     */
    private function getVerificationMessage(): string
    {
        return match ($this->verificationType) {
            'driver' => 'You have been verified as a driver. You can now create rides!',
            'passenger' => 'You have been verified as a passenger. You can now book rides!',
            default => 'You have been verified!',
        };
    }
}
