<?php

namespace App\Modules\Money\Services;

use App\Models\WalletTransaction;
use App\Modules\Identity\Contracts\ProfileDirectory;
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
 *
 * Stage 2 C2: the `user.profile` eager load is gone. It existed only to read one column, and Identity's
 * `ProfileDirectory` supplies that column for the whole page in ONE query - one query out for the one
 * query in, so the frozen budgets do not move.
 */
final class WalletChargeHistoryReader implements WalletChargeHistory
{
    public function __construct(
        private readonly ProfileDirectory $profiles,
    ) {}

    public function recentForWallet(int $walletId, int $limit = 20): array
    {
        $transactions = $this->query($walletId)->limit($limit)->get();

        $photos = $this->photosFor($transactions->pluck('user_id')->unique()->values()->all());

        return $transactions
            ->map(fn (WalletTransaction $tx) => $this->row($tx, $photos))
            ->all();
    }

    public function paginateForWallet(int $walletId, int $perPage, int $page): LengthAwarePaginator
    {
        $paginator = $this->query($walletId)->paginate($perPage, ['*'], 'page', $page);

        $photos = $this->photosFor(
            $paginator->getCollection()->pluck('user_id')->unique()->values()->all()
        );

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (WalletTransaction $tx) => $this->row($tx, $photos))
        );

        return $paginator;
    }

    private function query(int $walletId)
    {
        return WalletTransaction::with([
            'user:id,first_name,last_name',
        ])
            ->where('wallet_id', $walletId)
            ->where('type', 'admin_charge')
            ->orderByDesc('created_at');
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, string|null>
     */
    private function photosFor(array $userIds): array
    {
        return $this->profiles->photoPathsForUsers($userIds);
    }

    /**
     * @param  array<int, string|null>  $photos  keyed by user id
     * @return array<string, mixed>
     */
    private function row(WalletTransaction $tx, array $photos): array
    {
        $admin = $tx->user;
        $storedPhoto = $admin ? ($photos[$admin->id] ?? null) : null;

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
            'processed_by_photo' => $storedPhoto
                ? asset('storage/'.$storedPhoto)
                : null,
        ];
    }
}
