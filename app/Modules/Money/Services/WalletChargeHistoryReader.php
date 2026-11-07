<?php

namespace App\Modules\Money\Services;

use App\Models\WalletTransaction;
use App\Modules\Money\Contracts\WalletChargeHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Stage 2 C1: builds the admin "wallet charges" rows from the table Money owns.
 *
 * The query is byte-for-byte the one Identity ran before (same eager loads, same filters, same order),
 * and the row body is the one Identity's `formatWalletCharge()` produced - moved, not rewritten. That
 * matters twice over: the response snapshots are frozen, and the eager loads are what keep this from
 * becoming an N+1 over the admin's name and photo.
 *
 * The `WalletTransaction` import is still the kernel path because the model moves later in this phase,
 * in the commit that also retires `User::wallet()`; when it moves, only this line changes.
 */
final class WalletChargeHistoryReader implements WalletChargeHistory
{
    public function recentForWallet(int $walletId, int $limit = 20): array
    {
        return $this->query($walletId)
            ->limit($limit)
            ->get()
            ->map(fn (WalletTransaction $tx) => $this->row($tx))
            ->all();
    }

    public function paginateForWallet(int $walletId, int $perPage, int $page): LengthAwarePaginator
    {
        $paginator = $this->query($walletId)->paginate($perPage, ['*'], 'page', $page);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (WalletTransaction $tx) => $this->row($tx))
        );

        return $paginator;
    }

    private function query(int $walletId)
    {
        return WalletTransaction::with([
            'user:id,first_name,last_name',
            'user.profile:user_id,profile_photo',
        ])
            ->where('wallet_id', $walletId)
            ->where('type', 'admin_charge')
            ->orderByDesc('created_at');
    }

    /** @return array<string, mixed> */
    private function row(WalletTransaction $tx): array
    {
        $admin = $tx->user;

        return [
            'id' => $tx->id,
            'transaction_id' => $tx->transaction_id,
            'amount' => (float) $tx->amount,
            'previous_balance' => (float) $tx->previous_balance,
            'new_balance' => (float) $tx->new_balance,
            'status' => $tx->status,
            'notes' => $tx->description,
            'date' => $tx->created_at->toDateString(),
            'created_at' => $tx->created_at->toIso8601String(),
            'processed_by_id' => $admin?->id,
            'processed_by_name' => $admin
                ? trim("{$admin->first_name} {$admin->last_name}")
                : null,
            'processed_by_photo' => $admin?->profile?->profile_photo
                ? asset('storage/'.$admin->profile->profile_photo)
                : null,
        ];
    }
}
