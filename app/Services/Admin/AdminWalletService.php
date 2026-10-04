<?php

namespace App\Services\Admin;

use App\Domain\ValueObjects\Money;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        return DB::transaction(function () use ($phoneNumber, $amount, $adminConfig) {
            $wallet = Wallet::where('phone_number', $phoneNumber)
                ->lockForUpdate()
                ->firstOrFail();

            $previousBalance = (float) $wallet->balance;
            $newBalance = $previousBalance + $amount->amount();

            $wallet->balance = $newBalance;
            $wallet->save();

            $transactionId = strtoupper($adminConfig['type']).'_CHARGE_'.time().'_'.Str::random(8);

            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,   // null for system wallets
                'type' => 'admin_credit',
                'amount' => $amount->amount(),
                'previous_balance' => $previousBalance,
                'new_balance' => $newBalance,
                'description' => 'Admin wallet charge by '.$adminConfig['type'],
                'transaction_id' => $transactionId,
                'status' => 'completed',
                // NOTE: no 'metadata' column in wallet_transactions
            ]);

            // ── Decision un3 (owner choice (a)): this is an EXTERNAL flow ────────────
            // Money arriving in the platform from the outside world has no internal counterparty,
            // so it is recorded against the External Capital account. That closes the ledger:
            // without it, an admin top-up was a balance change with no ledger entry, and
            // reconciliation had a permanent hole exactly where money enters the system.
            //
            // The account is NOT Primary Escrow - that one is platform revenue.
            $external = Wallet::where('phone_number', config('admin.external.phone'))
                ->where('kind', 'system')
                ->whereNull('user_id')
                ->lockForUpdate()
                ->first();

            if ($external !== null) {
                $externalPrev = (float) $external->balance;
                $external->balance -= $amount->amount();   // money leaves the outside account
                $external->save();

                WalletTransaction::create([
                    'wallet_id' => $external->id,
                    'user_id' => null,
                    'type' => 'external_inbound',
                    'amount' => -$amount->amount(),
                    'previous_balance' => $externalPrev,
                    'new_balance' => (float) $external->balance,
                    'description' => 'Funds injected into wallet #'.$wallet->id.' by '.$adminConfig['type'],
                    'transaction_id' => 'EXT_'.$transactionId,
                    'status' => 'completed',
                ]);

                app(LedgerService::class)->postExternalTransfer(
                    $external,
                    $wallet,
                    (float) $amount->amount(),
                    inbound: true,
                    transaction: $transaction,
                    description: "admin credit to wallet #{$wallet->id}",
                );
            } else {
                // The external account must exist for the ledger to close. Failing loudly beats
                // silently recording money that the ledger cannot explain.
                throw new RuntimeException(
                    'External Capital wallet not found for phone: '.config('admin.external.phone')
                    .'. Run: php artisan db:seed --class=SystemWalletSeeder'
                );
            }

            Log::info('Wallet charged by admin', [
                'wallet_id' => $wallet->id,
                'amount' => $amount->amount(),
                'admin_type' => $adminConfig['type'],
            ]);

            return [
                'wallet' => $wallet,
                'transaction' => $transaction,
                'previous_balance' => Money::from($previousBalance),
                'new_balance' => Money::from($newBalance),
            ];
        });
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
        $transactions = WalletTransaction::where('wallet_id', $walletId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return [
            'wallet' => $wallet,
            'transactions' => $transactions,
        ];
    }
}
