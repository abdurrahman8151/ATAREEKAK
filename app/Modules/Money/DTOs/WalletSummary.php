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
        public readonly float $balance,
        public readonly float $cashRideDebt,
    ) {}
}
