<?php

namespace App\Modules\Money\Contracts;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;

/**
 * Port over the admin passenger-wallet charge mechanics (batch 233).
 *
 * IDENTITY's passenger profile controller performs the admin "charge wallet" action; the mechanics
 * are a MONEY operation. This class used to live in `app/Modules/Identity/Services` (IDENTITY) and reach into
 * `App\Modules\Money\Services\LedgerService`; step (c) moved it to `App\Modules\Money\Services` and put this port
 * in front of it, so the move does not simply turn one IDENTITY -> MONEY edge into another.
 *
 * Implemented by `App\Modules\Money\Services\PassengerWalletCharger`; bound to the same auto-resolved
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

    /**
     * Record the inbound leg against the External Capital system wallet and post the ledger transfer.
     *
     * Stage 2 B2: `$targetTransaction` is the row that recorded the receiving wallet's own movement, so the
     * inbound leg can name it. It is untyped on purpose: this is Money's own model, but callers in Identity
     * may not import it (`BoundaryDependencyTest` rule `controllers_to_models` is a 0 baseline), and the value
     * only travels from the caller back into the Money service.
     */
    public function injectExternalCapital($wallet, User $user, float $amount, $targetTransaction = null): void;

    /** Bust every cached admin view that embeds the wallet balance or its charges. */
    public function bustWalletChargeCaches(int $userId): void;

    /** The POST charge-wallet success envelope, read from the refreshed wallet row. */
    public function chargeSuccessResponse($wallet): JsonResponse;
}
