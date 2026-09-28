<?php

namespace App\Enums;

/**
 * Booking Status Enum
 *
 * Eliminates magic strings and provides type safety
 */
enum BookingStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Approval',
            self::CONFIRMED => 'Confirmed',
            self::CANCELLED => 'Cancelled',
            self::COMPLETED => 'Completed',
        };
    }

    /**
     * Check if booking is active
     */
    public function isActive(): bool
    {
        return in_array($this, [self::PENDING, self::CONFIRMED]);
    }

    /**
     * Check if booking can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::PENDING, self::CONFIRMED]);
    }

    /**
     * Get color for UI display
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'yellow',
            self::CONFIRMED => 'green',
            self::CANCELLED => 'red',
            self::COMPLETED => 'blue',
        };
    }

    /**
     * Get active booking statuses
     */
    public static function activeStatuses(): array
    {
        return [
            self::PENDING->value,
            self::CONFIRMED->value,
        ];
    }
}
