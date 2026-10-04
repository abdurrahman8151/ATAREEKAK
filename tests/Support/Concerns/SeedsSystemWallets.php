<?php

namespace Tests\Support\Concerns;

use App\Enums\WalletKind;
use App\Models\Wallet;

/**
 * RV-34 — shared, idempotent system-wallet seeding.
 *
 * Cause A of the red suite: `seedAdminWallets()` / inline setUp read
 * `config("admin.{$type}")['email'|'password'|'first_name']`, but config/admin.php
 * now only carries `phone` and `wallet_prefix` (credentials moved to the employees
 * table). That raised `Undefined array key "email"` inside setUp, erroring every
 * test in the file before the body ran (~220 tests).
 *
 * The system wallets are system wallets precisely because they have no user and no
 * login: they are addressed by phone. So this seeds them by phone with user_id
 * NULL and no email/password — matching the proven in-repo references
 * (PassengerConfirmCompletionTest::setUp, AdminFinancialReportEscrowTest::setUp,
 * AdminFinancialSurfaceAuthorizationTest::seedSystemWallet).
 *
 * Idempotent: safe to call more than once, so a test that also needs a custom
 * starting balance can call seedSystemWallets(0) then top it up.
 */
trait SeedsSystemWallets
{
    /**
     * Ensure the Primary (system_admin) and SyCash system wallets exist.
     *
     * @return array{primary: Wallet, sycash: Wallet, external: Wallet}
     */
    protected function seedSystemWallets(float $balance = 0.0): array
    {
        return [
            'primary' => $this->seedSystemWallet(config('admin.system_admin.phone'), $balance),
            'sycash' => $this->seedSystemWallet(config('admin.sycash.phone'), $balance),
            // Decision un3 (owner choice (a)): the EXTERNAL capital account. Seeded here because
            // `AdminWalletService::chargeWallet` FAILS LOUDLY without it - a ledger that cannot
            // record money entering the platform is not a closed ledger, so a fixture that omits it
            // would make the money path throw for the wrong reason.
            'external' => $this->seedSystemWallet(config('admin.external.phone'), 0.0),
        ];
    }

    /**
     * Idempotently create (or fetch) a single system wallet by phone.
     */
    protected function seedSystemWallet(string $phone, float $balance = 0.0): Wallet
    {
        // Decision un3: `kind = system` is now the PRIMARY condition in
        // `WalletTransactionService::lockWalletByPhone`, so a fixture that seeded only `user_id =>
        // null` would make every money path fail closed. Both are set.
        $wallet = Wallet::firstOrCreate(
            ['phone_number' => $phone],
            [
                'user_id' => null,
                'wallet_number' => strtoupper(substr(md5($phone), 0, 3)).random_int(1000000000, 9999999999),
                'balance' => $balance,
                'kind' => WalletKind::SYSTEM->value,
            ]
        );

        // `firstOrCreate` does not update an existing row, so a wallet seeded before the `kind`
        // column existed would keep `kind = user` and silently break the escrow lookup.
        if ($wallet->kind !== WalletKind::SYSTEM) {
            $wallet->forceFill(['kind' => WalletKind::SYSTEM->value])->save();
        }

        return $wallet;
    }

    /**
     * Shorthand for the SyCash escrow wallet (the one escrow money flows into).
     */
    protected function syCashWallet(float $balance = 0.0): Wallet
    {
        return $this->seedSystemWallet(config('admin.sycash.phone'), $balance);
    }
}
