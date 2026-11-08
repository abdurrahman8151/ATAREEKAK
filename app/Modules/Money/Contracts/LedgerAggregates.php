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
}
