<?php

namespace App\Modules\Money\Services;

use App\Enums\WalletKind;
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
    public function forUser(int $userId, bool $fromWritePdo = false): ?WalletSummary
    {
        $wallet = Wallet::forUser($userId, $fromWritePdo);

        return $wallet === null ? null : $this->toSummary($wallet);
    }

    public function byPhone(string $phoneNumber): ?WalletSummary
    {
        $wallet = Wallet::where('phone_number', $phoneNumber)->first();

        return $wallet === null ? null : $this->toSummary($wallet);
    }

    public function systemWalletByPhone(string $phoneNumber): ?WalletSummary
    {
        $wallet = Wallet::where('phone_number', $phoneNumber)
            ->whereNull('user_id')   // RV-21 boundary: only the platform-owned wallet
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

    public function walletOrFail(int $id): WalletSummary
    {
        return $this->toSummary(Wallet::findOrFail($id));
    }

    public function systemWalletsForPhones(array $phoneNumbers): array
    {
        if ($phoneNumbers === []) {
            return [];
        }

        $summaries = Wallet::query()
            ->whereIn('phone_number', $phoneNumbers)
            ->get()
            ->mapWithKeys(fn (Wallet $wallet) => [$wallet->phone_number => $this->toSummary($wallet)])
            ->all();

        // The caller's order, and only the phones that exist - the admin card relies on both.
        return array_values(array_filter(array_map(
            fn (string $phone) => $summaries[$phone] ?? null,
            $phoneNumbers,
        )));
    }

    public function allSummaries(): array
    {
        return Wallet::query()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Wallet $wallet) => $this->toSummary($wallet))
            ->values()
            ->all();
    }

    private function toSummary(Wallet $wallet): WalletSummary
    {
        $kind = $wallet->kind;

        return new WalletSummary(
            id: (int) $wallet->id,
            userId: $wallet->user_id === null ? null : (int) $wallet->user_id,
            walletNumber: (string) $wallet->wallet_number,
            balance: $wallet->balance,
            cashRideDebt: $wallet->cash_ride_debt,
            name: $wallet->name,
            phoneNumber: $wallet->phone_number,
            kind: $kind instanceof WalletKind ? $kind->value : ($kind === null ? null : (string) $kind),
            createdAt: $wallet->created_at,
            updatedAt: $wallet->updated_at,
        );
    }
}
