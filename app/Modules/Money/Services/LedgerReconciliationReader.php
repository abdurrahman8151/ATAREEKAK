<?php

namespace App\Modules\Money\Services;

use App\Modules\Money\Contracts\LedgerReconciliation;
use App\Modules\Money\Models\LedgerEntry;
use App\Modules\Money\Models\Wallet;

/**
 * Stage 2 C2: the reconciliation reads, moved verbatim from the `ledger:reconcile` command.
 *
 * Same queries, same order (`Wallet::orderBy('id')`), same rounding to 2dp, same two sides compared - what
 * `wallet_transactions` says the wallet moved versus what `ledger_entries` posted - so the report and its exit
 * code are unchanged. The model imports stay kernel paths until the models move into this module later in the
 * phase; when they do, only these lines change.
 */
final class LedgerReconciliationReader implements LedgerReconciliation
{
    public function walletCount(): int
    {
        return Wallet::count();
    }

    public function ledgerEntryCount(): int
    {
        return LedgerEntry::count();
    }

    public function systemImbalance(): float
    {
        return round((float) LedgerEntry::sum('amount'), 2);
    }

    public function perWalletDrift(float $threshold): array
    {
        $explained = 0;
        $unexplained = [];

        foreach (Wallet::orderBy('id')->get() as $wallet) {
            $recorded = round((float) $wallet->transactions()->sum('amount'), 2);
            $ledgered = round((float) LedgerEntry::where('wallet_id', $wallet->id)->sum('amount'), 2);
            $drift = round($ledgered - $recorded, 2);

            if (abs($drift) <= $threshold) {
                $explained++;

                continue;
            }

            $unexplained[] = [
                'id' => (int) $wallet->id,
                'number' => (string) $wallet->wallet_number,
                'recorded' => $recorded,
                'ledgered' => $ledgered,
                'drift' => $drift,
            ];
        }

        return [$explained, $unexplained];
    }
}
