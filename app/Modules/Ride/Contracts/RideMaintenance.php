<?php

namespace App\Modules\Ride\Contracts;

/**
 * Stage 2 C2: the small row-access surface Ride's three maintenance commands need.
 *
 * `BackfillBookingMoneySnapshot`, `ExpireStaleBookingsCommand` and `ReportStuckEscrowCommand` are production
 * artisan entrypoints, and since the Ride cluster moved into the module they were the last kernel files naming
 * Ride's implementation - which `ModuleBoundaryTest`'s entrypoint ratchet counted (they were listed deliberately
 * for exactly one release, with the port that would replace each one named in the record).
 *
 * Every method here is deliberately narrow: the command keeps its console I/O, its counts, its logging and its
 * notifications; Ride keeps the rows, the row LOCK and the write. Nothing here returns a Booking model - callers
 * get ids and scalars, so a command cannot start mutating a row it was handed.
 */
interface RideMaintenance
{
    /**
     * IDs of PENDING bookings whose ride has already departed - the expire job's work list. Ids, not rows,
     * because the write happens under a lock in `expireIfStillStale()`.
     *
     * @return array<int, int>
     */
    public function stalePendingBookingIds(): array;

    /**
     * Lock the booking and its ride, re-check that it is STILL pending and the ride has departed, then expire
     * it. Returns the facts the command logs and addresses its notification with, or null when the booking
     * must be left alone (the driver accepted between the list query and this write).
     *
     * @return array{booking_id: int, ride_id: int|null, passenger_id: int|null, departure_time: string|null}|null
     */
    public function expireIfStillStale(int $bookingId): ?array;

    /**
     * IDs of bookings whose money snapshot is still unfilled (`amount_paid = 0`).
     *
     * @return array<int, int>
     */
    public function unfilledSnapshotBookingIds(): array;

    /**
     * Fill one booking's money snapshot from the escrow amount the caller resolved, or mark its payment method
     * for the cash case. `$escrowedAmount` is null when no escrow row proved money moved.
     *
     * @return 'e-pay'|'cash'|'skipped'
     */
    public function backfillSnapshot(int $bookingId, ?float $escrowedAmount, bool $dry): string;

    /**
     * The facts the stuck-escrow report prints per booking: which ride, how many seats, and to whom.
     *
     * @param  array<int, int>  $bookingIds
     * @return array<int, array{ride_id: int|null, seats: int|null, departure_time: string|null, user_id: int|null}>
     */
    public function bookingContexts(array $bookingIds): array;
}
