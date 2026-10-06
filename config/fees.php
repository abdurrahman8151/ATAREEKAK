<?php

/**
 * Money that the platform takes, and how a ride's escrow is divided.
 *
 * THE 95/5 SPLIT USED TO BE WRITTEN OUT IN FOUR PLACES - `WalletTransactionService` (twice),
 * `AdminDriverService` and the seeder - each with its own copy of the literals `0.95` and `0.05`.
 * Changing the platform's cut meant finding all four, and one of them had already drifted (see
 * `App\Support\FeeSplit`). There is exactly one definition now, and `FeeSplit` is the only
 * supported way to apply it.
 *
 * ARITHMETIC, NOT POLICY, IS THE INTERESTING PART. The two shares must SUM to the total exactly,
 * because the ledger refuses an unbalanced transfer (`LedgerService::postTransfer` throws rather
 * than record one). Two ways of writing the split look identical and are not:
 *
 *   independent: round($total * 0.95, 2) and round($total * 0.05, 2)  <- what two sites used
 *   subtractive: round($total * 0.95, 2) and round($total - $driver, 2)
 *
 * At a total of 1000.50 the exact split is driver 950.475 / platform 50.025. Independent rounding
 * gives 950.48 + 50.03 = 1000.51, which is one cent MORE than was debited from escrow, so the
 * transfer is refused and the ride can never complete. Any total whose last digit is an odd tenth
 * (99.10, 250.70, 1000.50, ...) does this; whole-cent totals do not, which is why it went unnoticed.
 *
 * The split is expressed in minor units via `App\Domain\ValueObjects\Money` (integer fils), so the
 * subtraction is exact by construction and cannot drift.
 */
return [

    /*
    | The share of a completed ride's escrow that reaches the platform (the "Primary" admin wallet).
    | Charged on e-pay ride completion and on passenger no-show compensation.
    */
    'platform_fee_rate' => 0.05,

    /*
    | What is left for the driver. Stated explicitly rather than derived as (1 - platform_fee_rate)
    | so that the two are visibly a pair that must sum to 1, and so a change to one is a deliberate
    | edit rather than a silent consequence.
    */
    'driver_share_rate' => 0.95,

];
