<?php

namespace App\Modules\Money\Services;

use App\Modules\Money\Contracts\WalletTransactionPager;
use App\Modules\Money\Models\WalletTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Stage 2 C2: the admin transaction page, moved verbatim from the Console service.
 *
 * Same table, same `wallet_id` filter, same `created_at desc` order, same `paginate($perPage)` - so the rows,
 * their order and the pagination envelope are identical. The import stays a kernel path until the model moves
 * into this module later in the phase.
 */
final class AdminWalletTransactionPager implements WalletTransactionPager
{
    public function paginateForWallet(int $walletId, int $perPage): LengthAwarePaginator
    {
        return WalletTransaction::where('wallet_id', $walletId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }
}
