<?php

namespace App\Modules\Money\Services;

use App\Models\Wallet;
use App\Modules\Money\Contracts\WalletDirectory;
use App\Modules\Money\DTOs\WalletSummary;

/**
 * Stage 2 C1: reads `wallets` from inside the module that owns the table.
 *
 * Both methods issue exactly one query; the batched one is the reason this contract exists, because
 * the profile panel renders a wallet balance next to other rows and must not add a query per user.
 *
 * The `Wallet` import is still the kernel path because the model moves later in this phase, in the
 * commit that also retires `User::wallet()`; when it moves, only this line changes.
 */
final class WalletDirectoryReader implements WalletDirectory
{
    public function forUser(int $userId): ?WalletSummary
    {
        $wallet = Wallet::query()
            ->where('user_id', $userId)
            ->first();

        return $wallet === null ? null : $this->toSummary($wallet);
    }

    public function summariesFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return Wallet::query()
            ->whereIn('user_id', $userIds)
            ->get()
            ->mapWithKeys(fn (Wallet $wallet) => [$wallet->user_id => $this->toSummary($wallet)])
            ->all();
    }

    private function toSummary(Wallet $wallet): WalletSummary
    {
        return new WalletSummary(
            id: (int) $wallet->id,
            userId: (int) $wallet->user_id,
            walletNumber: (string) $wallet->wallet_number,
            balance: (float) $wallet->balance,
            cashRideDebt: (float) $wallet->cash_ride_debt,
        );
    }
}
