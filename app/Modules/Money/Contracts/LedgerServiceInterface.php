<?php

namespace App\Modules\Money\Contracts;

use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Models\WalletTransaction;

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
     * @return LedgerEntry[] the debit leg and the credit leg, in that order
     */
    public function postExternalTransfer(
        Wallet $external,
        Wallet $target,
        float $amount,
        bool $inbound,
        ?WalletTransaction $transaction = null,
        ?string $description = null,
    ): array;
}
