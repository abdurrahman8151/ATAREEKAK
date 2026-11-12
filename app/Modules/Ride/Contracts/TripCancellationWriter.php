<?php

namespace App\Modules\Ride\Contracts;

/**
 * The three writes and one lookup the staff cancellation flow makes to a trip row.
 *
 * WHY WRITES ARE ON A PORT AT ALL (Stage 2 C2). Console's staff-cancellation service is the last place outside
 * this module that names the Ride model, and it does two different things with it: it READS the row (the status
 * guard, the id in logs and notifications) and it WRITES it (cancel the trip; give seats back when a single
 * booking is cancelled). A reader port cannot cover the second half, and simply handing Console the row to
 * mutate would leave another module writing this table - which is what C2 exists to stop. So the row comes out
 * for reading and the mutations come back in as named operations whose SQL lives here.
 *
 * Each method issues exactly the statement the inline code did, so the response and the query count are
 * unchanged - including `updated_at`, which both Eloquent `update()` and `increment()` touch:
 *
 *  - `findForCancellation()`    = the old `findOrFail`, `ModelNotFoundException` and all (the caller's 404).
 *  - `markCancelled()`          = `$ride->update(['status' => 'cancelled'])`.
 *  - `restoreSeats()`           = `$ride->increment('available_seats', $n)` plus the `'full' -> 'active'` write.
 *  - `cancelledBookings()`      = the `bookings()->where('status', 'cancelled')->count()` in the response.
 *
 * NOT here, deliberately: the refund, the passenger notifications, the booking updates and the log lines. They
 * stay in the caller, which owns the HTTP endpoint and the money port it calls. Moving the whole workflow into
 * Ride is a separate, larger decision (the same class as the wallet-request approval workflow parked in
 * sec 293), and it is recorded rather than taken silently here.
 */
interface TripCancellationWriter
{
    /**
     * The trip row to cancel, for the caller's status guard, logs and notifications.
     *
     * Returns the row untyped on purpose - the caller must not have to name the model - and throws
     * `ModelNotFoundException` exactly as `findOrFail` did, so the caller's 404 branch is unchanged.
     */
    public function findForCancellation(int $rideId): mixed;

    /** Flip the trip to `cancelled`. One statement. */
    public function markCancelled(int $rideId): void;

    /**
     * Give seats back to a trip that is still cancellable.
     *
     * `$rideStatus` is the status the CALLER already read, passed in rather than re-read here: that keeps this
     * a single `increment` (plus the `'full' -> 'active'` update) instead of adding a `select`, which would move
     * the endpoint's query count.
     */
    public function restoreSeats(int $rideId, int $seats, string $rideStatus): void;

    /** How many of the trip's bookings are already `cancelled` - the count the response reports. */
    public function cancelledBookings(int $rideId): int;
}
