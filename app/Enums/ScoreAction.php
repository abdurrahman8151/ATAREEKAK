<?php

namespace App\Enums;

/**
 * Score Action Enum
 *
 * Every event that triggers a score change.
 * Keeping actions explicit eliminates magic strings throughout the system.
 */
enum ScoreAction: string
{
    // ── Positive ────────────────────────────────────────────────────────────
    case RIDE_COMPLETED = 'ride_completed';              // +10 all parties
    case RATING_POSITIVE = 'rating_positive';             // +3  rating of 4 or 5 stars
    case RATING_FIVE_STAR_STREAK = 'rating_five_star_streak'; // +2 bonus, 3 consecutive 5-star ratings
    case DRIVER_COMMITMENT_STREAK_7  = 'driver_commitment_streak_7';  // +2  7 days without cancellation
    case DRIVER_COMMITMENT_STREAK_14 = 'driver_commitment_streak_14'; // +4  14 days without cancellation
    case DRIVER_COMMITMENT_STREAK_30 = 'driver_commitment_streak_30'; // +6  30 days without cancellation

    // ── Passenger negative ───────────────────────────────────────────────────
    case PASSENGER_CANCEL_EARLY  = 'passenger_cancel_early';  // 0-30 % elapsed
    case PASSENGER_CANCEL_MID    = 'passenger_cancel_mid';    // 30-50 % elapsed
    case PASSENGER_CANCEL_LATE   = 'passenger_cancel_late';   // 50-100 % elapsed
    case PASSENGER_NO_SHOW       = 'passenger_no_show';       // -15 fixed

    // ── Driver negative ───────────────────────────────────────────────────────
    case DRIVER_CANCEL_SEAT      = 'driver_cancel_seat';      // -5 fixed
    case DRIVER_CANCEL_RIDE_EARLY = 'driver_cancel_ride_early'; // 0-30 %
    case DRIVER_CANCEL_RIDE_MID   = 'driver_cancel_ride_mid';   // 30-50 %
    case DRIVER_CANCEL_RIDE_LATE  = 'driver_cancel_ride_late';  // 50-100 %
    case DRIVER_NO_SHOW           = 'driver_no_show';           // -15 fixed

    // ── Admin manual adjustments ─────────────────────────────────────────────
    case ADMIN_INCREASE          = 'admin_increase';
    case ADMIN_DECREASE          = 'admin_decrease';

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Human-readable label for logs / admin UI */
    public function label(): string
    {
        return match ($this) {
            self::RIDE_COMPLETED          => 'Ride completed',
            self::RATING_POSITIVE         => 'Positive rating received',
            self::RATING_FIVE_STAR_STREAK => '3 consecutive 5-star ratings',
            self::DRIVER_COMMITMENT_STREAK_7  => '7-day cancellation-free streak',
            self::DRIVER_COMMITMENT_STREAK_14 => '14-day cancellation-free streak',
            self::DRIVER_COMMITMENT_STREAK_30 => '30-day cancellation-free streak',
            self::PASSENGER_CANCEL_EARLY  => 'Passenger cancelled (early)',
            self::PASSENGER_CANCEL_MID    => 'Passenger cancelled (mid)',
            self::PASSENGER_CANCEL_LATE   => 'Passenger cancelled (late)',
            self::PASSENGER_NO_SHOW       => 'Passenger no-show',
            self::DRIVER_CANCEL_SEAT      => 'Driver cancelled seat',
            self::DRIVER_CANCEL_RIDE_EARLY => 'Driver cancelled ride (early)',
            self::DRIVER_CANCEL_RIDE_MID   => 'Driver cancelled ride (mid)',
            self::DRIVER_CANCEL_RIDE_LATE  => 'Driver cancelled ride (late)',
            self::DRIVER_NO_SHOW           => 'Driver no-show',
            self::ADMIN_INCREASE          => 'Admin point increase',
            self::ADMIN_DECREASE          => 'Admin point decrease',
        };
    }

    /** Resolve the appropriate action from time-elapsed percentage for a passenger cancellation */
    public static function passengerCancelAction(float $elapsedPct): self
    {
        return match (true) {
            $elapsedPct <= 30 => self::PASSENGER_CANCEL_EARLY,
            $elapsedPct <= 50 => self::PASSENGER_CANCEL_MID,
            default           => self::PASSENGER_CANCEL_LATE,
        };
    }

    /** Resolve the appropriate action from time-elapsed percentage for a driver ride cancellation */
    public static function driverCancelRideAction(float $elapsedPct): self
    {
        return match (true) {
            $elapsedPct <= 30 => self::DRIVER_CANCEL_RIDE_EARLY,
            $elapsedPct <= 50 => self::DRIVER_CANCEL_RIDE_MID,
            default           => self::DRIVER_CANCEL_RIDE_LATE,
        };
    }

    /** Whether this action is a positive event */
    public function isPositive(): bool
    {
        return in_array($this, [
            self::RIDE_COMPLETED,
            self::RATING_POSITIVE,
            self::RATING_FIVE_STAR_STREAK,
            self::DRIVER_COMMITMENT_STREAK_7,
            self::DRIVER_COMMITMENT_STREAK_14,
            self::DRIVER_COMMITMENT_STREAK_30,
        ], true);
    }

    /** Whether this action is driver-specific */
    public function isDriverAction(): bool
    {
        return in_array($this, [
            self::DRIVER_CANCEL_SEAT,
            self::DRIVER_CANCEL_RIDE_EARLY,
            self::DRIVER_CANCEL_RIDE_MID,
            self::DRIVER_CANCEL_RIDE_LATE,
            self::DRIVER_NO_SHOW,
        ]);
    }
}
