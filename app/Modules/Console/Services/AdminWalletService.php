<?php

namespace App\Modules\Console\Services;

use App\Domain\ValueObjects\Money;
use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Money\Contracts\WalletDirectory;
use App\Modules\Money\Contracts\WalletTransactionPager;
use RuntimeException;

/**
 * AdminWalletService
 *
 * System wallets (Primary Escrow + SyCash) are seeded by SystemWalletSeeder and are
 * NOT tied to any user account. This service never creates User rows - it only reads wallets by phone
 * number.
 *
 * Stage 2 C2: every `wallets` read in this file moved behind Money's `WalletDirectory` (owner ruling
 * 2026-11-17: narrow the admin wallet payloads and let Money own its rows). The response builders below are
 * unchanged - they now read a `WalletSummary` by property - and the ONE payload that used to be the raw row
 * (`getWalletTransactions()`'s `wallet` key) is built here as an EXPLICIT documented shape with exactly the
 * keys the previous serialization emitted, so the endpoint's JSON is byte-identical while Console stops
 * handing a Money model to `response()->json()`.
 */
class AdminWalletService
{
    public function __construct(
        private readonly AdminWalletCharger $charger,
        private readonly WalletDirectory $wallets,
        private readonly UserDirectory $users,
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
    public function getOrCreateWallet(array $adminConfig)
    {
        $wallet = $this->wallets->byPhone($adminConfig['phone']);

        if (! $wallet) {
            throw new RuntimeException(
                "System wallet for phone [{$adminConfig['phone']}] not found. ".
                'Run: php artisan db:seed --class=SystemWalletSeeder'
            );
        }

        return $wallet;
    }

    // =========================================================================
    // CHARGE USER WALLET  (admin -> user, e.g. top-up)
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

        return array_map(function ($wallet) use ($adminConfigs) {
            $type = null;
            foreach (['system_admin', 'sycash'] as $key) {
                if ($adminConfigs[$key]['phone'] === $wallet->phoneNumber) {
                    $type = $key;
                    break;
                }
            }

            return [
                'id' => $wallet->id,
                'name' => $wallet->name,
                'wallet_number' => $wallet->walletNumber,
                'phone_number' => $wallet->phoneNumber,
                'balance' => Money::from($wallet->balance)->formatted(),
                'admin_type' => $type,
                'created_at' => $wallet->createdAt,
                'updated_at' => $wallet->updatedAt,
            ];
        }, $this->wallets->systemWalletsForPhones($phones));
    }

    /**
     * Return all wallets (system + user) for the admin panel.
     */
    public function getAllWallets(): array
    {
        $wallets = $this->wallets->allSummaries();

        // Stage 2 C2: the owners' names/emails are Identity's data - ONE batched directory read, the same
        // single query the retired `with('user:id,first_name,last_name,email')` eager load cost.
        $owners = $this->users->summariesFor(
            array_values(array_filter(array_map(fn ($wallet) => $wallet->userId, $wallets)))
        );

        return array_map(function ($wallet) use ($owners) {
            $owner = $wallet->userId !== null ? ($owners[$wallet->userId] ?? null) : null;

            return [
                'id' => $wallet->id,
                'name' => $wallet->name ?? null,
                'is_system' => $wallet->userId === null,
                'wallet_number' => $wallet->walletNumber,
                'phone_number' => $wallet->phoneNumber,
                'balance' => Money::from($wallet->balance)->formatted(),
                'owner' => $owner
                    ? $owner->fullName()
                    : ($wallet->name ?? 'System'),
                'owner_email' => $owner?->email,
                'created_at' => $wallet->createdAt->toDateTimeString(),
            ];
        }, $wallets);
    }

    /**
     * Wallet transactions with pagination.
     *
     * The `wallet` key is an EXPLICIT documented shape, not the row: `walletOrFail()` raises the same
     * ModelNotFoundException the old `findOrFail` did (the controller still answers 404), and the keys below
     * are exactly the ones the raw-row serialization emitted, so the JSON does not move.
     */
    public function getWalletTransactions(int $walletId, int $perPage = 10): array
    {
        $wallet = $this->wallets->walletOrFail($walletId);
        // Stage 2 C2: Money owns `wallet_transactions`; the page comes from its pager.
        $transactions = app(WalletTransactionPager::class)->paginateForWallet($walletId, $perPage);

        return [
            // Key order follows the column order the raw row serialized in: the snapshot normalizer labels
            // ids in first-seen order, so an explicit shape must keep it (and the JSON keys sort anyway).
            'wallet' => [
                'id' => $wallet->id,
                'user_id' => $wallet->userId,
                'wallet_number' => $wallet->walletNumber,
                'phone_number' => $wallet->phoneNumber,
                'name' => $wallet->name,
                'kind' => $wallet->kind,
                'balance' => $wallet->balance,
                'cash_ride_debt' => $wallet->cashRideDebt,
                'created_at' => $wallet->createdAt,
                'updated_at' => $wallet->updatedAt,
            ],
            'transactions' => $transactions,
        ];
    }
}
