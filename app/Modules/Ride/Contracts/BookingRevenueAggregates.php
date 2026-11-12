<?php

namespace App\Modules\Ride\Contracts;

/**
 * The three booking-based money projections the admin screens publish.
 *
 * WHY THESE ARE RIDE'S (Stage 2 C2). All three are arithmetic over `bookings` and `rides` - seats x the ride's
 * current price per seat - and none of them is a ledger total: `Money\Contracts\LedgerAggregates` already owns
 * the four sums that read `ledger_entries`, and these are explicitly the OTHER kind of number (a projection off
 * today's prices, which the report itself labels `estimated`). Console used to build them from the Booking model,
 * which it may not name once bookings move here.
 *
 * Each method is the statement it replaces, character for character, including the fee rate:
 * `estimatedGrossForDriver()` applies `config('fees.driver_share_rate')` inside the SQL exactly as the inline
 * expression did, so the platform's cut is still defined in one place.
 */
interface BookingRevenueAggregates
{
    /**
     * The driver's gross projection: completed bookings' `seats x price_per_seat`, times the platform's driver
     * share. A projection, NOT settled money - the settled figure comes from Money's `DriverEarningsReader`.
     */
    public function estimatedGrossForDriver(int $driverId): float;

    /**
     * Revenue across every COMPLETED booking: `SUM(seats x price_per_seat)`, with no share applied.
     */
    public function completedBookingsRevenue(): float;

    /**
     * Money locked in rides that have not finished - confirmed bookings on `active`, `full` or
     * `awaiting_confirmation` trips. The `where` on the ride's status is part of the definition, not a filter
     * the caller may vary.
     */
    public function lockedFundsInActiveRides(): float;
}
