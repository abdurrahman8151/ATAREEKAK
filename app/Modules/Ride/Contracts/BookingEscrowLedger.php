<?php

namespace App\Modules\Ride\Contracts;

/**
 * Stage 2 C2: the guarded escrow decrement, owned by the module that owns the `bookings` table.
 *
 * `escrow_held` lives on `bookings`. Ride owns that table, so Ride owns the SQL that writes it - Money used
 * to run `DB::table('bookings')` directly, which is a cross-module table write no model reference and no
 * import makes visible (`ModelOwnershipTest` ratchet 3 found exactly these two call sites).
 *
 * The guard is the whole point of this port, so the contract is deliberately narrow: the atomic conditional
 * decrement stays atomic inside the owner, and the caller cannot restate it as a read-then-write. A port that
 * returned a booking row and let Money save it would move the coupling, not remove it, and would lose the
 * concurrency guarantee that `escrow_held >= :amount` in the WHERE clause provides.
 *
 * Amounts cross this boundary as floats and are formatted by the owner. Passing a pre-formatted SQL string in
 * would mean money semantics were decided by the caller and interpolated by the callee - the caller's string
 * would reach `DB::raw()`, which is exactly the shape to avoid even when the caller is trusted today.
 */
interface BookingEscrowLedger
{
    /**
     * Take `amount` out of one booking's escrow, conditionally and atomically.
     *
     * @return bool true when exactly one row changed; false when the booking holds less than `amount`
     *              (already settled, or never charged - both are reasons not to pay)
     */
    public function conditionallyDebit(int $bookingId, float $amount): bool;

    /** What the booking currently holds, for the caller's refusal message. */
    public function heldFor(int $bookingId): float;
}
