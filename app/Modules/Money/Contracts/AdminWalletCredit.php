<?php

namespace App\Modules\Money\Contracts;

use App\Domain\ValueObjects\Money;

/**
 * Stage 2 C2: an admin credit to a wallet, performed by the module that owns the balance.
 *
 * `Console\Services\AdminWalletCharger::chargeWallet()` used to run this itself - row lock, balance write,
 * the admin_credit leg, the external_inbound leg against External Capital - which made Console a writer of
 * Money's table. The body moves here verbatim, so the lock order, the ledger types, the missing-External
 * exception and the transaction boundary are all unchanged.
 *
 * Returns scalars plus one opaque handle. `transaction` is whatever the recorder produced; the only surface
 * any caller may touch is `transaction_id`, which is also returned separately, and that is the same
 * convention `WalletTransactionRecorder::record(): mixed` already uses.
 */
interface AdminWalletCredit
{
    /**
     * @param  array{type: string}  $adminConfig  which admin kind is crediting (drives the type prefix)
     * @return array{
     *     wallet_id: int,
     *     phone_number: string,
     *     previous_balance: float,
     *     new_balance: float,
     *     transaction_id: string,
     *     transaction: mixed
     * }
     */
    public function credit(string $phoneNumber, Money $amount, array $adminConfig): array;
}
