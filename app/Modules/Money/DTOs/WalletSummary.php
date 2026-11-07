<?php

namespace App\Modules\Money\DTOs;

/**
 * Stage 2 C1/C2: what another module may know about a wallet.
 *
 * The property names deliberately match the `wallets` columns they come from (`wallet_number`,
 * `balance`, `cash_ride_debt`), because existing response builders read wallet fields by name -
 * including Money's own `PassengerWalletCharger::chargeSuccessResponse()`, which reads `balance`.
 * Keeping the names identical is what makes the switch from the model to this DTO response-neutral.
 *
 * Read-only by construction: a caller that received the model could `save()` it, which is exactly the
 * cross-module write the ownership rules forbid.
 */
final class WalletSummary
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $walletNumber,
        /**
         * NOT cast to float on purpose. The model hands back its own attribute value (a decimal
         * string), and callers put it straight into JSON and into formatted strings; casting here
         * changed `"1500.00"` into `1500` and broke the byte-identical `wallet/balance` snapshot.
         * The type stays `mixed` so the DTO passes through exactly what the column held.
         */
        public readonly mixed $balance,
        /** Same reasoning as `$balance`: passed through, never re-typed. */
        public readonly mixed $cashRideDebt,
    ) {}
}
