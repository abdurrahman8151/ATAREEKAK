<?php

namespace App\Modules\Console\Services;

use App\Domain\ValueObjects\Money;
use App\Models\Wallet;
use App\Modules\Money\Contracts\WalletTransactionPager;
use RuntimeException;

/**
 * AdminWalletService
 *
 * Manages system wallet operations for the admin panel.
 *
 * System wallets (Primary Escrow + SyCash) are seeded once via
 * SystemWalletSeeder and are NOT tied to any user account.
 * This service never creates User rows — it only reads wallets by phone number.
 */
class AdminWalletService
{
    public function __construct(
        private readonly AdminWalletCharger $charger,
    ) {}

    // =========================================================================
    // LOOKUP
    // =========================================================================

    /**
     * Fetch a system wallet by its config entry.
     * Throws a clear error if the seeder has not been run yet.
     *
     * @param  array  $adminConfig  e.g. config('admin.primary') or config('admin.sycash')
     */
    public function getOrCreateWallet(array $adminConfig): Wallet
    {
        $wallet = Wallet::where('phone_number', $adminConfig['phone'])->first();

        if (! $wallet) {
            throw new RuntimeException(
                "System wallet for phone [{$adminConfig['phone']}] not found. ".
                'Run: php artisan db:seed --class=SystemWalletSeeder'
            );
        }

        return $wallet;
    }

    // =========================================================================
    // CHARGE USER WALLET  (admin → user, e.g. top-up)
    // =========================================================================

    /**
     * Credit a user's wallet from the Primary Escrow.
     * Accessible only to the primary admin (enforced via route middleware).
     *
     * @param  string  $phoneNumber  Target user wallet phone
     * @param  array  $adminConfig  config('admin.primary')
     */
    public function chargeWallet(string $phoneNumber, Money $amount, array $adminConfig): array
    {
        return $this->charger->chargeWallet($phoneNumber, $amount, $adminConfig);
    }

    // =========================================================================
    // READ-ONLY VIEWS
    // =========================================================================

    /**
     * Return both system wallets (Primary Escrow + SyCash) for the admin overview.
     */
    public function getAdminWallets(): array
    {
        $adminConfigs = config('admin');
        $phones = [
            $adminConfigs['system_admin']['phone'],
            $adminConfigs['sycash']['phone'],
        ];

        return Wallet::whereIn('phone_number', $phones)
            ->get()
            ->map(function (Wallet $wallet) use ($adminConfigs) {
                $type = null;
                foreach (['system_admin', 'sycash'] as $key) {
                    if ($adminConfigs[$key]['phone'] === $wallet->phone_number) {
                        $type = $key;
                        break;
                    }
                }

                return [
                    'id' => $wallet->id,
                    'name' => $wallet->name,
                    'wallet_number' => $wallet->wallet_number,
                    'phone_number' => $wallet->phone_number,
                    'balance' => Money::from($wallet->balance)->formatted(),
                    'admin_type' => $type,
                    'created_at' => $wallet->created_at,
                    'updated_at' => $wallet->updated_at,
                ];
            })
            ->toArray();
    }

    /**
     * Return all wallets (system + user) for the admin panel.
     */
    public function getAllWallets(): array
    {
        return Wallet::with('user:id,first_name,last_name,email')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Wallet $wallet) {
                return [
                    'id' => $wallet->id,
                    'name' => $wallet->name ?? null,
                    'is_system' => $wallet->isSystemWallet(),
                    'wallet_number' => $wallet->wallet_number,
                    'phone_number' => $wallet->phone_number,
                    'balance' => Money::from($wallet->balance)->formatted(),
                    'owner' => $wallet->user
                        ? $wallet->user->first_name.' '.$wallet->user->last_name
                        : ($wallet->name ?? 'System'),
                    'owner_email' => $wallet->user?->email,
                    'created_at' => $wallet->created_at->toDateTimeString(),
                ];
            })
            ->toArray();
    }

    /**
     * Wallet transactions with pagination.
     */
    public function getWalletTransactions(int $walletId, int $perPage = 10): array
    {
        $wallet = Wallet::findOrFail($walletId);
        // Stage 2 C2: Money owns `wallet_transactions`; the page comes from its pager.
        $transactions = app(WalletTransactionPager::class)->paginateForWallet($walletId, $perPage);

        return [
            'wallet' => $wallet,
            'transactions' => $transactions,
        ];
    }
}
