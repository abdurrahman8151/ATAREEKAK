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

    // Decision 2 (owner, 2026-10-02): the owner ruled that an unconfirmed booking is EXPIRED, not
    // auto-confirmed. This is deliberately its own state rather than reusing `cancelled`: a driver
    // who never answered a request is a different event from a passenger who changed their mind,
    // and collapsing the two makes "how often are requests ignored?" unanswerable.
    //
    // It carries no money and no seats. A PENDING booking is charged only when the driver accepts
    // (see `BookingService::acceptBooking`) and holds seats only from that moment, so there is no
    // escrow to release and no seat to return when a booking expires.
    case EXPIRED = 'expired';

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
            self::EXPIRED => 'Expired — driver did not respond',
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
     *
     * An EXPIRED booking is terminal, so it is deliberately NOT cancellable: re-cancelling it would
     * send a second notification for an event that already ended, and would try to restore seats
     * the booking never held.
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
            self::EXPIRED => 'grey',
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
