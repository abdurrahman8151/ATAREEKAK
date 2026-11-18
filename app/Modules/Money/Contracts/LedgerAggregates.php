<?php

namespace App\Modules\Money\Contracts;

use Carbon\Carbon;

/**
 * Stage 2 C2 precondition 2: the admin financial report's four ledger totals.
 *
 * `AdminReportService::getFinancialStatistics()` built four `wallet_transactions` queries and summed them. Each
 * one encodes a piece of Money's ledger vocabulary, and two of them encode history that only Money knows:
 *
 *  - escrow received is the SINGULAR 'escrow_received' (it was 'ride_creation_fee' once);
 *  - escrow released counts BOTH 'escrow_release' (the live path, one row per confirmed booking) and
 *    'escrow_released' (the legacy ride-wide release), so historical rows still appear in the total;
 *  - refunds cover three type names, and no-show refunds are one of them;
 *  - platform fees are the primary admin wallet's 'platform_fee' rows.
 *
 * Those spellings are ledger semantics, not a screen's formatting choice, so they live here. The caller passes
 * the window and formats the result; it never names a type. Amounts are returned raw (signed, as stored) - the
 * caller already applies `abs()` where the report presents an outflow.
 */
interface LedgerAggregates
{
    /** Sum of 'escrow_received' rows for the wallet, optionally windowed. */
    public function escrowReceived(int $walletId, ?Carbon $start, ?Carbon $end): float;

    /** Sum of 'escrow_release' + 'escrow_released' rows for the wallet, optionally windowed. */
    public function escrowReleased(int $walletId, ?Carbon $start, ?Carbon $end): float;

    /** Sum of the three refund type rows for the wallet, optionally windowed. */
    public function refundsPaid(int $walletId, ?Carbon $start, ?Carbon $end): float;

    /** Sum of 'platform_fee' rows for the wallet, optionally windowed. */
    public function platformFees(int $walletId, ?Carbon $start, ?Carbon $end): float;

    /**
     * Stage 2 C2: the escrow money actually received, keyed by the booking it was received for.
     *
     * One statement over `wallet_transactions` (`type = 'escrow_received'`, reference `booking:{id}`), summed per
     * booking because a booking can be charged across more than one row. The backfill command used to run this
     * SQL itself, which meant a Console entrypoint reading Money's table directly - the same coupling the
     * `Type`-vocabulary paragraphs above describe, just discovered later.
     *
     * @return array<int, float> booking id => escrow received
     */
    public function escrowTotalsByBooking(): array;

    /**
     * Stage 2 C2: the stuck-escrow report's population - one row per (reference, wallet) whose SyCash wallet net
     * movement is positive and which received at least one escrow leg.
     *
     * `ReportStuckEscrowCommand` used to run this grouped query itself, which made a Console entrypoint read
     * Money's ledger table directly. The grouping is Money's knowledge for the same reason the type vocabulary
     * above is: it correlates escrow by the wallet's OWN recorded balance movement
     * (`new_balance - previous_balance`) rather than by a hand-maintained list of settlement type names, because
     * that list would silently start reporting settled bookings as stuck the first time a settlement route is
     * added. The `escrow_in_legs > 0` filter is the boundary between a wallet that received money and the
     * PASSENGER's own debit under the same reference.
     *
     * Rows come back as plain arrays, ordered by amount held descending.
     *
     * @return array<int, array{reference: string, wallet_id: int, first_seen: string, net: float}>
     */
    public function heldEscrowReferences(): array;
}
