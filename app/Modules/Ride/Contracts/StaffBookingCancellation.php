<?php

namespace App\Modules\Ride\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The booking reads and the one booking write the staff cancellation flow needs.
 *
 * WHY THIS IS A SEPARATE CONTRACT FROM {@see TripCancellationWriter} (Stage 2 C2). Same reason the trip half was
 * split out in sec 318: the staff endpoints cancel a TRIP and a BOOKING, and Ride owns both tables. This one has
 * its own three surfaces - the locked lookup with its eager loads, the status flip, and the collection the refund
 * is handed - and folding them into the trip port would make one name describe two workflows.
 *
 * Writes go through the owner, not through a row handed to the caller: `markCancelled()` is the statement the
 * inline `$booking->update(['status' => 'cancelled'])` ran, and it keeps the same `updated_at` semantics. The
 * lookup returns the row untyped so the caller can read `status`, `seats` and `user` for its own guard,
 * notifications and log lines, and `ModelNotFoundException` propagates exactly as `findOrFail`'s did, so the
 * caller's 404 branch is untouched.
 */
interface StaffBookingCancellation
{
    /**
     * The booking to cancel, with its ride and its passenger eager-loaded - the caller's own
     * `with(['ride', 'user'])`, so the query count is unchanged.
     *
     * The parameter is deliberately untyped: naming the model from Console is the cross-module reference the
     * ownership ratchets forbid.
     *
     * @throws ModelNotFoundException
     */
    public function findForCancellation(int $bookingId): mixed;

    /** Flip the booking to `cancelled`. One statement. */
    public function markCancelled(int $bookingId): void;

    /**
     * The ELOQUENT collection the refund port is handed - the caller's
     * `whereIn('id', [$id])->get()`, one query, and deliberately a fresh fetch rather than a wrapper around the
     * already-loaded row (wrapping it would drop a query and move the endpoint's recorded budget).
     */
    public function forRefund(int $bookingId): Collection;
}
