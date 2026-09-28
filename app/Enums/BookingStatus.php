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
    // AF-4: this state existed in the DB enum (migration 2025_07_21_181158) and
    // Noshowservice writes it in two places, but the PHP enum never declared it
    // — so BookingStatus::tryFrom('no_show') returned null for a live status,
    // and the exhaustive match() arms below could never name it. Enum and
    // database now agree.
    case NO_SHOW = 'no_show';

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
            self::NO_SHOW => 'No Show',
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
            self::NO_SHOW => 'darkred',
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
