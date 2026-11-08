<?php

namespace App\Modules\Console\Services;

use App\Models\Wallet;
use App\Models\WalletRequest;
use App\Modules\Money\Contracts\WalletTransactionRecorder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The admin side of the wallet-request review queue: the filtered list, the tab counts, the row a
 * decision acts on, the two locked-wallet reads and the wallet_transactions row both decisions write.
 *
 * Moved out of AdminWalletRequestController (batch 227) so the controller holds no Eloquent
 * reference. Nothing about the money path changes here: same eager loads, same ordering, same
 * `selectRaw` GROUP BY instead of three COUNTs, same `lockForUpdate()` reads, and the same attributes
 * in the same order for both `WalletTransaction::create()` calls. The controller keeps the
 * transaction boundaries, the ledger posting and the response shapes.
 */
final class AdminWalletRequestService
{
    /**
     * The review list, filtered exactly as the controller filtered it.
     */
    public function reviewList(string $status, ?string $type, int $perPage, int $page): LengthAwarePaginator
    {
        $query = WalletRequest::with([
            'user:id,first_name,last_name,email',
            'wallet:id,wallet_number,phone_number,balance,cash_ride_debt',
        ])->orderByDesc('created_at');

        $query->where('status', $status);

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 1 GROUP BY query instead of 3 separate COUNTs.
     *
     * @return array{pending: int, approved: int, rejected: int, cancelled: int}
     */
    public function statusCounts(): array
    {
        $countRows = WalletRequest::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($countRows['pending'] ?? 0),
            'approved' => (int) ($countRows['approved'] ?? 0),
            'rejected' => (int) ($countRows['rejected'] ?? 0),
            'cancelled' => (int) ($countRows['cancelled'] ?? 0),
        ];
    }

    /**
     * The row a decision acts on. `findOrFail` is kept, so an unknown id still throws
     * ModelNotFoundException and the controller still answers 404.
     *
     * @param  array<int, string>  $with
     */
    public function findOrFail(int $id, array $with = []): WalletRequest
    {
        return WalletRequest::with($with)->findOrFail($id);
    }

    /** The wallet under the approval transaction's row lock. */
    public function lockWalletOrFail(int $walletId): Wallet
    {
        return Wallet::lockForUpdate()->findOrFail($walletId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTransaction(array $attributes): mixed
    {
        // Stage 2 C2: Money owns `wallet_transactions`; Console records through its port.
        return app(WalletTransactionRecorder::class)->record($attributes);
    }

    /**
     * The External Capital account under its row lock. Null when it is missing, which is the
     * RuntimeException the caller already raises.
     */
    public function lockedExternalWallet(): ?Wallet
    {
        return Wallet::where('phone_number', config('admin.external.phone'))
            ->where('kind', 'system')
            ->whereNull('user_id')
            ->lockForUpdate()
            ->first();
    }
}
