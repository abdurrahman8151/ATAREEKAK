<?php

namespace App\Enums;

/**
 * Booking Type Enum
 *
 * Defines how bookings are handled:
 * - DIRECT: Instant booking, payment processed immediately
 * - REQUEST: Requires driver approval before confirmation
 */
enum BookingType: string
{
    case DIRECT = 'direct';
    case REQUEST = 'request';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::DIRECT => 'Instant Booking',
            self::REQUEST => 'Request Approval',
        };
    }

    /**
     * Check if this type requires driver approval
     */
    public function requiresApproval(): bool
    {
        return $this === self::REQUEST;
    }

    /**
     * Check if payment should be processed immediately
     */
    public function processPaymentImmediately(): bool
    {
        return $this === self::DIRECT;
    }

    /**
     * Get initial booking status for this type
     */
    public function initialBookingStatus(): BookingStatus
    {
        return match ($this) {
            self::DIRECT => BookingStatus::CONFIRMED,
            self::REQUEST => BookingStatus::PENDING,
        };
    }
}
