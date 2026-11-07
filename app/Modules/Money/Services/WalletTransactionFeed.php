<?php

namespace App\Modules\Money\Services;

use App\Models\WalletTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Stage 2 C1: pages a wallet's transaction feed from inside the module that owns the table.
 *
 * `WalletController::transactions()` used to call `$wallet->transactions()` - a relation on a model it
 * may no longer name. This is the same query, filters and order; the controller keeps formatting the
 * result with the existing `WalletTransactionResource`, so the JSON is unchanged.
 *
 * Deliberately Money-internal rather than a cross-module port: nothing outside Money pages a wallet
 * transaction feed today, and inventing a port for a hypothetical caller would be speculative surface.
 *
 * Takes plain filter values rather than the HTTP `Request`: a service may not depend on the request
 * layer (the `request_in_services` boundary is 0), so the controller extracts `type`/`from`/`to`.
 */
final class WalletTransactionFeed
{
    /**
     * @param  array<string, mixed>  $filters  keys: type, from, to (each may be absent, null or blank)
     */
    public function paginateForWallet(int $walletId, array $filters, int $perPage): LengthAwarePaginator
    {
        return WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->when(filled($filters['type'] ?? null), fn ($q) => $q->where('type', $filters['type']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->latest()
            ->paginate($perPage);
    }
}
