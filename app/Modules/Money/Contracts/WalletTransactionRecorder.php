<?php

namespace App\Modules\Money\Contracts;

/**
 * Stage 2 C1: Identity's admin passenger profile used to write Money's table directly -
 * `PassengerProfileQueryService::createTransaction()` called `WalletTransaction::create()`. That is a
 * cross-module *write*, which the ownership rules forbid once the model moves into the Money namespace.
 *
 * Scope is deliberately one insert and nothing else. It does **not** fold in the external-capital
 * injection that follows it: whether that injection should happen once or twice is an open owner
 * question (audit sec 264, the measured double debit), so this port must not quietly decide it. Same
 * rows, same order, same ledger linkage as before - only the writer moved.
 *
 * The return value is `mixed` on purpose: callers pass the created row straight into
 * `PassengerWalletChargerInterface::injectExternalCapital()` to link the ledger leg, so the row has to
 * come back, but naming its type here would recreate the import this port removes.
 */
interface WalletTransactionRecorder
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return mixed the created transaction row
     */
    public function record(array $attributes): mixed;
}
