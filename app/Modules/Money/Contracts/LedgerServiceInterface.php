<?php

namespace App\Modules\Money\Contracts;

use App\Modules\Money\Models\LedgerEntry;
use App\Modules\Money\Models\Wallet;
use App\Modules\Money\Models\WalletTransaction;

/**
 * Port over the EXTERNAL ledger entry point (batch 233).
 *
 * CONSOLE credits and withdraws against the External Capital wallet; MONEY owns the double-entry
 * legs. Before this port `AdminWalletRequestController` and `AdminWalletCharger` type-hinted
 * `App\Modules\Money\Services\LedgerService` directly. Only the external-transfer entry point is on the
 * port, because that is the only one those callers use - the rest of the ledger stays MONEY-internal.
 */
interface LedgerServiceInterface
{
    /**
     * Stage 2 B2: each side names the single-sided row that recorded ITS wallet's movement, so the two
     * legs of one external transfer can point at two different rows (they are two different wallets).
     * Both are optional; a null link is the pre-B2 shape and stays valid for rows written before this
     * change.
     *
     * @return LedgerEntry[] the debit leg and the credit leg, in that order
     */
    public function postExternalTransfer(
        Wallet $external,
        Wallet $target,
        float $amount,
        bool $inbound,
        ?WalletTransaction $externalTransaction = null,
        ?WalletTransaction $targetTransaction = null,
        ?string $description = null,
    ): array;
}
