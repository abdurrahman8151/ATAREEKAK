<?php

namespace App\Modules\Money\Contracts;

use App\Modules\Money\DTOs\WalletSummary;

/**
 * Stage 2 C1: the read door to a wallet, so other modules stop asking `App\Models\User` for
 * `$user->wallet` (a reverse relation Money owns, not the principal).
 *
 * Shape matters as much as behaviour here: the callers that matter - Identity's passenger profile
 * panel and its charge-history endpoint - read the wallet *inside* a request that already has a
 * query budget, so `forUser()` must be one query and `summariesFor()` must be one query for many
 * users. A per-row lookup would trade a relation for an N+1.
 */
interface WalletDirectory
{
    /** The wallet of one user, or null when they have none. One query. */
    public function forUser(int $userId): ?WalletSummary;

    /**
     * Summaries for many users, keyed by user id. One query for the whole set, which is why callers
     * rendering a list must use this rather than calling forUser() in a loop.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, WalletSummary> keyed by user id; users without a wallet are absent
     */
    public function summariesFor(array $userIds): array;
}
