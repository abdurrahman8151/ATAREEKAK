<?php

namespace App\Modules\Identity\Services;

use App\Models\Complaint;
use App\Models\User;
use App\Modules\Money\Contracts\WalletChargeHistory;
use App\Modules\Money\Contracts\WalletTransactionRecorder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The admin passenger-profile reads: the two cached bundles, the complaint list, the admin-charge
 * history and the wallet_transactions row a charge writes.
 *
 * Moved out of PassengerProfileController (batch 227) so the controller holds no Eloquent reference.
 * Every eager load, filter, ordering and page size is copied character for character; the controller
 * keeps the caching, the response shapes, the transaction boundary and the formatting it shares with
 * the BuildsPassengerProfilePanels / ChargesPassengerWallet traits.
 */
final class PassengerProfileQueryService
{
    /**
     * Stage 2 C1: the admin wallet-charge rows are Money's, so they are read through its port
     * instead of this service paging Money's model itself.
     */
    public function __construct(
        private readonly WalletChargeHistory $chargeHistory,
        private readonly WalletTransactionRecorder $transactionRecorder,
    ) {}

    /** The full-profile bundle: the user with its profile, in one query. */
    public function profileBundle(int $userId): User
    {
        // Stage 2 C2: the `photos` eager load was DEAD WEIGHT - the full-profile screen never reads a
        // document, which the byte-identical snapshot proves. Identity owns the table, so the relation
        // went with the model instead of leaving a kernel edge to it.
        return User::with(['profile'])->findOrFail($userId);
    }

    /** The stats bundle. `findOrFail` is kept, so the controller still answers 404. */
    public function withWalletOrFail(int $userId): User
    {
        return User::findOrFail($userId);
    }

    /** Null when the user does not exist; the wallet may still be missing. */
    public function withWallet(int $userId): ?User
    {
        return User::find($userId);
    }

    /**
     * This passenger's complaints, filtered by status/type ("all" means no filter).
     */
    public function complaintsFor(int $userId, string $status, string $type, int $perPage, int $page): LengthAwarePaginator
    {
        $query = Complaint::with(['assignedAgent:id,first_name,last_name', 'attachments'])
            ->where('user_id', $userId);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($type !== 'all') {
            $query->where('type', $type);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * The wallet's admin_charge rows, newest first.
     */
    public function adminChargesFor(int $walletId, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->chargeHistory->paginateForWallet($walletId, $perPage, $page);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTransaction(array $attributes): mixed
    {
        // Stage 2 C1: the insert belongs to Money, which owns the table.
        return $this->transactionRecorder->record($attributes);
    }
}
