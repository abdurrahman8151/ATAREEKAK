<?php

namespace App\Services\Payment;

use App\Models\Wallet;

/**
 * The one definition of "lock the Primary Admin (platform revenue) wallet".
 *
 * CashRideFeeService and CashRideCreationFeeCharger each carried a byte-identical private
 * lockPrimaryWallet(). The SELECT ... FOR UPDATE and the SystemWalletSeeder refusal now live
 * here, so a change to the platform wallet lookup cannot drift between the two fee paths.
 */
class PrimaryAdminWallet
{
    /**
     * Lock and return the platform wallet row.
     *
     * The caller is responsible for already being inside a transaction: the lock is only
     * meaningful there, and every call site opens one before resolving this wallet.
     *
     * @throws \RuntimeException when the platform wallet has not been seeded
     */
    public function lock(): Wallet
    {
        $wallet = Wallet::where('phone_number', config('admin.system_admin.phone'))
            ->lockForUpdate()
            ->first();

        if (! $wallet) {
            throw new \RuntimeException(
                'Primary Admin wallet not found. Run: php artisan db:seed --class=SystemWalletSeeder'
            );
        }

        return $wallet;
    }
}
