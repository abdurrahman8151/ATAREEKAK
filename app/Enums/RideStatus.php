<?php

namespace App\Enums;

/**
 * Ride Status Enum
 *
 * Life-cycle:
 *   active  →  (departure time passes)  →  launched  →  finished
 *              ↑ lazy flip on first                ↑ when last non-cancelled
 *                passenger confirm                   booking reaches terminal state
 *
 * LAUNCHED replaces the old awaiting_confirmation value.
 * The old DB value is kept in the enum for backward-compat with any
 * existing rows; new rides will only ever use 'launched'.
 */
enum RideStatus: string
{
    case ACTIVE = 'active';
    case FULL = 'full';
    case CANCELLED = 'cancelled';
    case FINISHED = 'finished';

    /** Ride has departed; passengers can now confirm. Replaces awaiting_confirmation. */
    case LAUNCHED = 'launched';

    /** @deprecated – kept so old DB rows still deserialize correctly. Use LAUNCHED. */
    case AWAITING_CONFIRMATION = 'awaiting_confirmation';

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::FULL => 'Full',
            self::CANCELLED => 'Cancelled',
            self::FINISHED => 'Finished',
            self::LAUNCHED => 'Launched',
            self::AWAITING_CONFIRMATION => 'Awaiting Confirmation',
        };
    }

    public function canBeBooked(): bool
    {
        return in_array($this, [self::ACTIVE, self::FULL]);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::CANCELLED, self::FINISHED]);
    }

    /**
     * Is the ride in a state where passengers can confirm completion?
     * Covers both the new 'launched' and the legacy 'awaiting_confirmation'.
     */
    public function isConfirmable(): bool
    {
        return in_array($this, [self::LAUNCHED, self::AWAITING_CONFIRMATION]);
    }

    public static function bookableStatuses(): array
    {
        return [self::ACTIVE->value, self::FULL->value];
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE => 'green',
            self::FULL => 'orange',
            self::CANCELLED => 'red',
            self::FINISHED => 'blue',
            self::LAUNCHED => 'purple',
            self::AWAITING_CONFIRMATION => 'yellow',
        };
    }
}
