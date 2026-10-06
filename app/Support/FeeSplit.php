<?php

namespace App\Support;

use App\Domain\ValueObjects\Money;

/**
 * The one way to divide a ride's escrow between the driver and the platform.
 *
 * Every money path in the app splits escrow 95/5. Those sites used to each carry their own copy of
 * the arithmetic, and two of them wrote it as two independent roundings:
 *
 *     $driverShare  = round($total * 0.95, 2);
 *     $primaryShare = round($total * 0.05, 2);
 *
 * **Those two lines do not always sum to `$total`, and when they do not the ledger refuses the
 * transfer.** `LedgerService::postTransfer` throws on legs that do not sum to zero, by design - it
 * would rather fail the ride than record money that does not exist. At a total of 1000.50 the exact
 * split is driver 950.475 / platform 50.025; independent rounding produces 950.48 + 50.03 = 1000.51,
 * one cent more than was debited from escrow. The result is that such a ride can never reach
 * FINISHED, and every later confirmation attempt throws again. Every total whose last digit is an
 * odd tenth (99.10, 250.70, 1000.50) is affected; whole-cent totals are not, which is exactly why
 * this survived: the fixtures use round numbers.
 *
 * The fix is not a different rounding mode, it is that the platform takes **what is left** after
 * the driver has been paid. That is the reading a 95/5 split is supposed to have, it is the one
 * `releaseEscrowToDriver` already used (with the comment "subtract to avoid float drift"), and it
 * is the owner's ruling: driver gets `round(total x 0.95)`, platform gets the remainder.
 *
 * The subtraction happens in integer minor units through `Money`, so it is exact by construction.
 * The caller gets floats only because that is what the wallet columns and the existing arithmetic
 * already used; the split itself never touches a fraction of a fils.
 *
 * @see config/fees.php for the rates.
 */
final class FeeSplit
{
    /**
     * Divide an escrowed amount between the driver and the platform.
     *
     * The two returned values are guaranteed to sum to `$total` to the cent.
     *
     * @param  float  $total  The amount taken from escrow, already rounded to 2dp by the caller.
     * @return array{driver: float, platform: float}
     */
    public static function driverAndPlatform(float $total): array
    {
        $money = Money::from($total);

        // Integer minor units (fils): 1000.50 -> 100050 fils.
        $driver = $money->multiply((float) config('fees.driver_share_rate', 0.95));

        // The platform takes the remainder. Deriving it instead of multiplying by the fee rate is
        // what guarantees the two shares add back up to the total exactly.
        $platform = $money->subtract($driver);

        return [
            'driver' => $driver->amount(),
            'platform' => $platform->amount(),
        ];
    }

    /**
     * The ledger legs for releasing one amount of escrow, in the order `postTransfer` expects.
     *
     * Exposed so callers that post a transfer cannot accidentally rebuild the arithmetic and lose
     * the balance guarantee.
     *
     * @param  array{driver: float, platform: float}  $split  From {@see self::driverAndPlatform()}.
     * @return array<int, array{wallet_id: int, amount: float, description: string}>
     */
    public static function releaseLegs(array $split, int $escrowWalletId, int $driverWalletId, int $platformWalletId, string $description): array
    {
        return [
            ['wallet_id' => $escrowWalletId, 'amount' => -$split['platform'] - $split['driver'], 'description' => $description],
            ['wallet_id' => $driverWalletId, 'amount' => $split['driver'], 'description' => $description.' driver share'],
            ['wallet_id' => $platformWalletId, 'amount' => $split['platform'], 'description' => $description.' platform fee'],
        ];
    }
}
