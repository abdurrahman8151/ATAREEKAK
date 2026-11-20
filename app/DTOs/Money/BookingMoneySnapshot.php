<?php

namespace App\DTOs\Money;

/**
 * Stage 4 A2: the money snapshot Money computes for a booking, as DATA.
 *
 * Money used to force-fill these four values onto the Ride-owned booking row
 * (`BookingCharge::snapshotBookingCharge()`), which no ownership ratchet could see because `BookingView` is a
 * contract Money is allowed to name. The owner of a row is the only writer of it, so the write moved: Money
 * computes the values and returns them here, and Ride - which owns `bookings` - persists them through its own
 * service.
 *
 * The DTO lives in the KERNEL (`app/DTOs/`, like the other cross-module DTOs) because both sides must name it:
 * Money produces it and Ride persists it. The property names are the COLUMN names, deliberately, so the persist
 * site is a literal copy and the values cannot drift.
 *
 * Types stay `mixed` for the money values for the same reason `WalletSummary` does: the callers pass through what
 * the ride held (`price_per_seat` may be a decimal string) and the row stores it verbatim.
 */
final class BookingMoneySnapshot
{
    public function __construct(
        public readonly mixed $unitPrice,
        public readonly mixed $amountPaid,
        public readonly ?string $paymentMethod,
        public readonly mixed $escrowHeld,
    ) {}
}
