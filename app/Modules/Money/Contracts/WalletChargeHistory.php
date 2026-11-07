<?php

namespace App\Modules\Money\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Stage 2 C1: the admin "wallet charges" panel used to build these rows itself, from Money's
 * `WalletTransaction` model, in two places (`PassengerProfileQueryService::adminChargesFor` and
 * `PassengerProfilePanelBuilder::buildWalletCharges`). Once that model leaves `App\Models`, both are
 * Identity -> Money implementation edges, so the rows are produced here instead.
 *
 * The row shape is part of the contract, not an internal detail: it is the JSON the admin passenger
 * profile already returned, key for key, and the frozen response snapshots hold it to that. Money owns
 * it now because Money owns the table it comes from.
 *
 * @return array<string, mixed> keys: id, transaction_id, amount, previous_balance, new_balance,
 *                              status, notes, date, created_at, processed_by_id,
 *                              processed_by_name, processed_by_photo
 */
interface WalletChargeHistory
{
    /**
     * The most recent admin charges on one wallet, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentForWallet(int $walletId, int $limit = 20): array;

    /**
     * The same rows, paginated, with the paginator's own meta intact - the endpoint emits
     * current_page/last_page/per_page/total from it.
     */
    public function paginateForWallet(int $walletId, int $perPage, int $page): LengthAwarePaginator;
}
