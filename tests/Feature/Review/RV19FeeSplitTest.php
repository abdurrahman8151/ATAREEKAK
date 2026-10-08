<?php

namespace Tests\Feature\Review;

use App\Support\FeeSplit;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * RV-19 item 3 - the 95/5 escrow split is defined once, and the two shares always sum to the total.
 *
 * R2 sec 71. The split was written out in four places. Two of them computed each share by an
 * INDEPENDENT rounding:
 *
 *     $driverShare  = round($total * 0.95, 2);
 *     $primaryShare = round($total * 0.05, 2);
 *
 * Those two lines do not always sum to `$total`. At 1000.50 the exact split is driver 950.475 /
 * platform 50.025, and independent rounding yields 950.48 + 50.03 = **1000.51** - one cent more
 * than was debited from escrow.
 *
 * The consequence was not a silent cent leak. `LedgerService::postTransfer` REFUSES legs that do
 * not sum to zero, so the transfer threw - and because `checkAndCompleteRide` wraps the whole
 * completion in one transaction, the ride could never reach FINISHED and every later confirmation
 * threw again. Any total ending in an odd tenth (99.10, 250.70, 1000.50) was affected; whole-cent
 * totals were not, which is why it survived - every fixture uses round numbers.
 *
 * The rule this file pins: **driver gets round(total x 0.95), platform gets the remainder**
 * (the owner's ruling), computed in integer minor units so the subtraction is exact.
 *
 * PHPUnit 10 registers a method as a test only via an explicit `@test`, so every method here
 * carries one; `@dataProvider` on its own does not.
 */
class RV19FeeSplitTest extends TestCase
{
    /**
     * Totals that broke the independent form. Every one ends in an odd tenth.
     *
     * @return array<int, array{0: float}>
     */
    public static function brokenTotals(): array
    {
        return [[99.10], [99.90], [250.70], [500.30], [1000.50], [1234.50], [0.10], [0.30]];
    }

    /** Totals that happened to work, and must keep working identically. */
    public static function goodTotals(): array
    {
        return [[5000.00], [1500.00], [0.00], [1.00], [33.33], [2.22], [777.77]];
    }

    /**
     * THE invariant. The ledger refuses an unbalanced transfer, so this property is what keeps a
     * ride completable at all.
     *
     * @test
     *
     * @dataProvider brokenTotals
     */
    public function the_two_shares_sum_to_the_total_for_totals_that_used_to_break(float $total): void
    {
        $split = FeeSplit::driverAndPlatform($total);

        $this->assertSame(
            0.0,
            round(-$total + $split['driver'] + $split['platform'], 2),
            "the 95/5 split of {$total} must sum back to exactly {$total}, or postTransfer() refuses it"
        );
    }

    /**
     * @test
     *
     * @dataProvider goodTotals
     */
    public function the_two_shares_sum_to_the_total_for_totals_that_already_worked(float $total): void
    {
        $split = FeeSplit::driverAndPlatform($total);

        $this->assertSame(0.0, round(-$total + $split['driver'] + $split['platform'], 2));
    }

    /**
     * The owner's ruling, pinned at the cent: driver 950.48, platform 50.02 (NOT 50.03).
     *
     * @test
     */
    public function the_platform_takes_the_remainder_not_the_rounded_fee(): void
    {
        $split = FeeSplit::driverAndPlatform(1000.50);

        $this->assertSame(950.48, $split['driver']);
        $this->assertSame(50.02, $split['platform'], 'the platform takes what is LEFT, not round(total x 0.05)');
    }

    /**
     * The exact regression values, written out so a future change to the arithmetic cannot quietly
     * reintroduce the independent form and still pass the sum checks at other totals.
     *
     * @test
     *
     * @dataProvider brokenTotals
     */
    public function the_known_affected_totals_produce_the_documented_shares(float $total): void
    {
        $expectedDriver = round($total * 0.95, 2);
        $split = FeeSplit::driverAndPlatform($total);

        $this->assertSame($expectedDriver, $split['driver'], 'driver keeps round(total x 0.95)');
        $this->assertSame(
            round($total - $expectedDriver, 2),
            $split['platform'],
            'platform is the remainder'
        );
    }

    /**
     * The bug in its original form, asserted as still-detectable. If someone reintroduces the
     * independent rounding this must FAIL, proving the tests above are not vacuous.
     *
     * @test
     */
    public function the_independent_form_this_replaces_really_does_break_the_sum(): void
    {
        $total = 1000.50;

        // What the two broken sites used to compute.
        $driver = round($total * 0.95, 2);
        $platform = round($total * 0.05, 2);

        $this->assertSame(0.01, round(-$total + $driver + $platform, 2), 'guard: the old form really did over-credit by a cent');

        // ...and the helper does not.
        $fixed = FeeSplit::driverAndPlatform($total);
        $this->assertSame(0.0, round(-$total + $fixed['driver'] + $fixed['platform'], 2));
    }

    /**
     * `R2 sec 116`: `Money` is signed, so the non-negative precondition this class relied on is no
     * longer a side effect of `Money::from()`. This test is the proof that it MOVED rather than
     * disappeared - and it is the reason this file, not just `MoneyTest`, is touched.
     *
     * Without the explicit assert, a negative escrow release would split into two negative shares that
     * still add up to the total exactly: arithmetic that is correct and nonsense money, which no
     * downstream check would catch.
     *
     * @test
     */
    public function a_negative_escrow_release_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Escrow amount to split cannot be negative');

        FeeSplit::driverAndPlatform(-100.50);
    }

    /**
     * Zero is still a legal split - it is negative that is refused, not "nothing to do".
     *
     * @test
     */
    public function a_zero_escrow_release_splits_to_zero(): void
    {
        $split = FeeSplit::driverAndPlatform(0.0);

        $this->assertSame(0.0, $split['driver']);
        $this->assertSame(0.0, $split['platform']);
    }

    /**
     * A structural guard: the split now lives in config, so no money path may carry its own copy
     * of the rates. This is what stops the four hard-codings from coming back.
     *
     * @test
     */
    public function no_money_path_carries_its_own_copy_of_the_rates(): void
    {
        $offenders = [];

        foreach ([
            app_path('Services/Payment/WalletTransactionService.php'),
            app_path('Services/Admin/AdminDriverService.php'),
        ] as $file) {
            $src = (string) file_get_contents($file);
            // Ignore comments; only executable arithmetic counts.
            foreach (preg_split('/\R/', $src) as $n => $line) {
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (preg_match('/\*\s*0\.(95|05)\b/', $line)) {
                    $offenders[] = basename($file).':'.($n + 1).': '.trimmed;
                }
            }
        }

        $this->assertSame([], $offenders, 'the 95/5 rates must come from config/fees.php, not literals');
    }

    /**
     * The rates are actually wired up, not silently defaulted.
     *
     * @test
     */
    public function the_rates_come_from_config_and_sum_to_one(): void
    {
        $driver = (float) config('fees.driver_share_rate');
        $platform = (float) config('fees.platform_fee_rate');

        $this->assertSame(0.95, $driver);
        $this->assertSame(0.05, $platform);
        $this->assertSame(1.0, round($driver + $platform, 10), 'the two rates must be a pair summing to 1');
    }
}
