<?php

namespace App\Modules\Money\Services;

use App\Modules\Identity\Contracts\ProfileDirectory;
use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Money\Contracts\WalletChargeHistory;
use App\Modules\Money\Models\WalletTransaction;
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
        private readonly UserDirectory $users,
    ) {}

    public function recentForWallet(int $walletId, int $limit = 20): array
    {
        $transactions = $this->query($walletId)->limit($limit)->get();

        $userIds = $transactions->pluck('user_id')->unique()->values()->all();

        // BE CAREFUL: the two port reads happen ONCE for the page, never inside the map below - a per-row
        // call is the N+1 this phase's budgets exist to catch (it was, for one run).
        $photos = $this->profiles->photoPathsForUsers($userIds);
        $summaries = $this->users->summariesFor($userIds);

        return $transactions
            ->map(fn (WalletTransaction $tx) => $this->row($tx, $photos, $summaries))
            ->all();
    }

    public function paginateForWallet(int $walletId, int $perPage, int $page): LengthAwarePaginator
    {
        $paginator = $this->query($walletId)->paginate($perPage, ['*'], 'page', $page);

        $userIds = $paginator->getCollection()->pluck('user_id')->unique()->values()->all();

        // Stage 2 C3: the charging admin's name comes from Identity's directory - ONE query for the page,
        // replacing the `user:id,first_name,last_name` eager load 1:1.
        $photos = $this->profiles->photoPathsForUsers($userIds);
        $summaries = $this->users->summariesFor($userIds);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (WalletTransaction $tx) => $this->row($tx, $photos, $summaries))
        );

        return $paginator;
    }

    private function query(int $walletId)
    {
        // Stage 2 C3: no `user` eager load - the administrative actor's name arrives from Identity's
        // directory in the same single query the eager load cost.
        return WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->where('type', 'admin_charge')
            ->orderByDesc('created_at');
    }

    /**
     * @param  array<int, string|null>  $photos  keyed by user id
     * @param  array<int, object>  $summaries  Identity's `UserSummary`, keyed by user id, read by property
     * @return array<string, mixed>
     */
    private function row(WalletTransaction $tx, array $photos, array $summaries): array
    {
        $actor = $tx->user_id ? ($summaries[(int) $tx->user_id] ?? null) : null;
        $storedPhoto = $tx->user_id ? ($photos[(int) $tx->user_id] ?? null) : null;

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
            'processed_by_id' => $actor?->id,
            'processed_by_name' => $actor !== null ? $actor->fullName() : null,
            'processed_by_photo' => $storedPhoto
                ? asset('storage/'.$storedPhoto)
                : null,
        ];
    }
}
