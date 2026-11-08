<?php

namespace App\Modules\Money\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Stage 2 C2 precondition 2: a wallet's transaction history, paginated for the admin screens.
 *
 * `AdminWalletService::getWalletTransactions()` ran the query itself. Money owns `wallet_transactions`, so the
 * query moves; the pager returns the SAME Eloquent paginator the caller built before, not a DTO page, because
 * the admin response serialises those rows as-is. Re-shaping them here would change a response the snapshots
 * pin, and this port exists to move the query, not the JSON.
 *
 * The default page size is the caller's, passed in explicitly, so the port does not hold a screen's opinion.
 */
interface WalletTransactionPager
{
    /** Wallet transactions, newest first, paginated. */
    public function paginateForWallet(int $walletId, int $perPage): LengthAwarePaginator;
}
