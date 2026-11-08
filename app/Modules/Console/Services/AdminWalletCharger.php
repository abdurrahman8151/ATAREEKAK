<?php

namespace App\Modules\Console\Services;

use App\Domain\ValueObjects\Money;
use App\Modules\Money\Contracts\AdminWalletCredit;

/**
 * Stage 2 C2: the admin wallet charge, now performed by Money.
 *
 * This class keeps its signature and its return shape because three callers and four unit tests read it:
 * `previous_balance` and `new_balance` stay `Money` value objects, and `transaction` stays an object with a
 * `transaction_id` property. What changed is that the locks, the balance write and both ledger legs happen
 * inside `Money\Contracts\AdminWalletCredit`, so this file no longer names the Wallet model at all.
 */
class AdminWalletCharger
{
    public function __construct(
        private readonly AdminWalletCredit $credits,
    ) {}

    public function chargeWallet(string $phoneNumber, Money $amount, array $adminConfig): array
    {
        $credit = $this->credits->credit($phoneNumber, $amount, $adminConfig);

        return [
            'wallet' => ['phone_number' => $credit['phone_number']],
            'transaction' => $credit['transaction'],
            'previous_balance' => Money::from($credit['previous_balance']),
            'new_balance' => Money::from($credit['new_balance']),
        ];
    }
}
