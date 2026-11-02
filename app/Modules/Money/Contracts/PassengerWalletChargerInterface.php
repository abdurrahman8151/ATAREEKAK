<?php

namespace App\Modules\Money\Contracts;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;

/**
 * Port over the admin passenger-wallet charge mechanics (batch 233).
 *
 * IDENTITY's passenger profile controller performs the admin "charge wallet" action; the mechanics
 * are a MONEY operation. This class used to live in `App\Services\Profile` (IDENTITY) and reach into
 * `App\Services\Payment\LedgerService`; step (c) moved it to `App\Services\Payment` and put this port
 * in front of it, so the move does not simply turn one IDENTITY -> MONEY edge into another.
 *
 * Implemented by `App\Services\Payment\PassengerWalletCharger`; bound to the same auto-resolved
 * instance the controller used to receive. Bodies unchanged.
 */
interface PassengerWalletChargerInterface
{
    /** The 422 for a passenger who has no wallet row yet. */
    public function noWalletResponse(): JsonResponse;

    /**
     * Lock the passenger wallet and add the amount.
     *
     * @return array{wallet: Wallet, previous: float, current: float}
     */
    public function creditWalletBalance(User $user, float $amount): array;

    /** Record the inbound leg against the External Capital system wallet and post the ledger transfer. */
    public function injectExternalCapital($wallet, User $user, float $amount): void;

    /** Bust every cached admin view that embeds the wallet balance or its charges. */
    public function bustWalletChargeCaches(int $userId): void;

    /** The POST charge-wallet success envelope, read from the refreshed wallet row. */
    public function chargeSuccessResponse($wallet): JsonResponse;
}
