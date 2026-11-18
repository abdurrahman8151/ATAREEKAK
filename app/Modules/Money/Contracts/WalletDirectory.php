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
    /**
     * The wallet of one user, or null when they have none. One query.
     *
     * $fromWritePdo exists for the balance read, which must reflect the authoritative ledger rather
     * than a lagging replica (RV-28). It used to be spelled \->load(['wallet' => ...useWritePdo()]).
     */
    public function forUser(int $userId, bool $fromWritePdo = false): ?WalletSummary;

    /**
     * A system wallet by its phone number - the admin report looks up SyCash and the primary
     * admin wallet this way, and `phone_number` is how operators identify them in config.
     * One query; null when the wallet has not been provisioned yet.
     */
    public function byPhone(string $phoneNumber): ?WalletSummary;

    /**
     * A platform-owned (system) wallet by phone number. The `user_id IS NULL` guard is the RV-21
     * boundary: the admin report must never read a user wallet that happens to share the configured
     * phone, so this lookup is deliberately narrower than byPhone() and cannot be merged into it.
     */
    public function systemWalletByPhone(string $phoneNumber): ?WalletSummary;

    /**
     * Summaries for many users, keyed by user id. One query for the whole set, which is why callers
     * rendering a list must use this rather than calling forUser() in a loop.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, WalletSummary> keyed by user id; users without a wallet are absent
     */
    public function summariesFor(array $userIds): array;

    /**
     * Stage 2 C2: one wallet by id, or ModelNotFoundException - the read the admin wallet-transactions
     * screen performs with `findOrFail`, kept INSIDE Money so the caller neither names the model nor
     * re-implements the "missing means 404" decision with a null check.
     */
    public function walletOrFail(int $id): WalletSummary;

    /**
     * The platform wallets identified by their configured phone numbers, in the order the phones are given.
     *
     * @param  string[]  $phoneNumbers
     * @return array<int, WalletSummary> a list
     */
    public function systemWalletsForPhones(array $phoneNumbers): array;

    /**
     * Every wallet, newest first - the admin "all wallets" table. One query; the owner's name and email are
     * Identity's data and are joined by the caller from `UserDirectory`.
     *
     * @return array<int, WalletSummary> a list
     */
    public function allSummaries(): array;
}
