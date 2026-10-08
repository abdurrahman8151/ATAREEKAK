<?php

namespace Tests\Feature\Review;

use App\Domain\ValueObjects\Money;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\Payment\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * `R2 sec 117`, AF-6 criterion 1 step 2: "rounding decided in one place - no float, no per-call
 * `round()`" was the criterion's own wording, and until now nothing held it.
 *
 * Three parts:
 *
 * 1. A STRUCTURAL RATCHET over the files that were converted. `Money::from()` rounds once on the way
 *    in, so after this task a converted file must not round a money value itself. Rounding something
 *    that is NOT money is still fine and is deliberately still happening - a percentage
 *    (`elapsed_pct`, `cancel_rate`) and a rating (`avg_rating`) are different quantities at different
 *    scales, and a test below asserts they survived.
 *
 * 2. THE NEGATIVE CONTROL. A ratchet that passes because a file deleted all its arithmetic would
 *    prove nothing, so every converted file must still use the value object.
 *
 * 3. The behavioural claim the conversion rests on. Worth being precise about what that claim is: a
 *    controlled comparison against the old float implementation showed **0 of 7** realistic leg sets
 *    are classified differently by the two forms. This task is a SUBSTITUTION of the rounding
 *    mechanism, not a fix of a live defect, and these tests hold the mechanism rather than
 *    retroactively claim a bug that was not there.
 */
class MoneyRoundingIsDecidedInOnePlaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Files whose money arithmetic was converted at `R2 sec 117`.
     *
     * @var array<int, string>
     */
    private const CONVERTED = [
        'app/Services/Payment/LedgerService.php',
        'app/Services/Payment/CashRideFeeService.php',
        'app/Services/Payment/WalletTransactionService.php',
        'app/Services/Admin/AdminDriverService.php',
        'app/Http/Controllers/API/PassengerProfileController.php',
        'app/Console/Commands/BackfillBookingMoneySnapshot.php',
        'app/Console/Commands/Testfullrideflow.php',
    ];

    /**
     * Tokens that mean money in this schema. A `round()` over any of them is a per-call rounding
     * decision on money, which is what the criterion forbids.
     *
     * @var array<int, string>
     */
    private const MONEY_TOKENS = [
        'amount', 'paid', 'fee', 'price', 'balance', 'earning', 'gross',
        'spending', 'escrow', 'total_cost', 'unit_price', 'debt', 'refund',
    ];

    /**
     * @test
     */
    public function no_converted_file_carries_its_own_rounding_of_a_money_value(): void
    {
        $offenders = [];

        foreach (self::CONVERTED as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path, 'a converted file moved or was deleted');

            foreach ($this->strippedSource($path) as $lineNumber => $line) {
                if (! str_contains($line, 'round(')) {
                    continue;
                }

                foreach (self::MONEY_TOKENS as $token) {
                    if (str_contains($line, $token)) {
                        $offenders[] = $relative.':'.($lineNumber + 1).'  '.$line;
                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Money is being rounded in more than one place again. Use Money::from() / multiply() /\n"
            ."subtract() so the rounding decision stays inside the value object:\n  "
            .implode("\n  ", $offenders)
        );
    }

    /**
     * The negative control for the ratchet above.
     *
     * @test
     */
    public function every_converted_file_still_uses_the_money_value_object(): void
    {
        foreach (self::CONVERTED as $relative) {
            $this->assertStringContainsString(
                'Money::',
                file_get_contents(base_path($relative)),
                $relative.' rounds nothing and uses nothing either - it was not converted at all.'
            );
        }
    }

    /**
     * The ratchet must not read as "delete the round() calls": a percentage and a rating are not
     * money, are on different scales, and should still be rounded where they are.
     *
     * @test
     */
    public function non_money_rounding_survived_the_conversion(): void
    {
        $this->assertStringContainsString(
            'round($elapsedPct, 2)',
            file_get_contents(base_path('app/Services/Payment/CashRideFeeService.php')),
            'a PERCENTAGE is not money and is still rounded for the log payload'
        );

        $this->assertStringContainsString(
            'round((float) $avgRating',
            file_get_contents(base_path('app/Services/Admin/AdminDriverService.php')),
            'a RATING is not money and stays at its own scale'
        );

        $this->assertStringContainsString(
            "'avg_rating' => round((float) \$avgRating, 1)",
            file_get_contents(base_path('app/Http/Controllers/API/PassengerProfileController.php')),
            'the 1dp average rating is deliberately NOT money - recorded at R2 sec 117 so it is not "fixed"'
        );
    }

    /**
     * Behavioural half, leg 1: legs that balance exactly in minor units are accepted.
     *
     * 0.1 + 0.2 - 0.3 is the classic float trap - as a running float sum it is 5.55e-17, not zero.
     * In integer fils it is exactly 10 + 20 - 30. This is the property the conversion buys: exactness
     * that does not depend on a trailing `round()` coming to the rescue.
     *
     * @test
     */
    public function legs_that_balance_exactly_in_minor_units_are_accepted_and_stored_exactly(): void
    {
        $service = app(LedgerService::class);
        $wallets = $this->twoWallets();

        $service->postTransfer([
            ['wallet_id' => $wallets[0]->id, 'amount' => 0.1, 'description' => 'leg a'],
            ['wallet_id' => $wallets[1]->id, 'amount' => 0.2, 'description' => 'leg b'],
            ['wallet_id' => $wallets[0]->id, 'amount' => -0.3, 'description' => 'leg c'],
        ]);

        $this->assertSame(3, LedgerEntry::count());

        $stored = LedgerEntry::orderBy('id')->pluck('amount')->map(fn ($v) => (float) $v)->all();
        $this->assertSame([0.1, 0.2, -0.3], $stored, 'what is stored is what was checked');

        $this->assertTrue(
            Money::zero()->equals(
                collect($stored)->reduce(fn (Money $carry, float $leg) => $carry->add(Money::from($leg)), Money::zero())
            ),
            'the recorded legs balance exactly in minor units'
        );
    }

    /**
     * Behavioural half, leg 2: the refusal survives, and it names the size of the imbalance.
     *
     * `DoubleEntryLedgerTest::an_unbalanced_transfer_is_refused_rather_than_recorded` already owns
     * the refusal itself. What this adds is the MESSAGE, because the conversion changed it: it is now
     * built from `Money::formatted()` rather than by string-casting a float, so "0.01 SYP" is
     * asserted deliberately instead of incidentally.
     *
     * @test
     */
    public function a_one_cent_imbalance_is_refused_records_nothing_and_names_the_amount(): void
    {
        $service = app(LedgerService::class);
        $wallets = $this->twoWallets();

        try {
            $service->postTransfer([
                ['wallet_id' => $wallets[0]->id, 'amount' => 100.00, 'description' => 'debit'],
                ['wallet_id' => $wallets[1]->id, 'amount' => -99.99, 'description' => 'credit'],
            ]);
            $this->fail('an unbalanced transfer must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to post an unbalanced ledger transfer', $e->getMessage());
            $this->assertStringContainsString('0.01 SYP', $e->getMessage(),
                'the imbalance is reported in minor units, formatted by the value object');
        }

        $this->assertSame(0, LedgerEntry::count(), 'nothing is recorded for a refused transfer');
    }

    /**
     * @return array{0: Wallet, 1: Wallet}
     */
    private function twoWallets(): array
    {
        return [
            Wallet::create([
                'phone_number' => '09'.rand(10000000, 99999999),
                'wallet_number' => 'WLT-'.substr(bin2hex(random_bytes(5)), 0, 12),
                'balance' => 0,
            ]),
            Wallet::create([
                'phone_number' => '09'.rand(10000000, 99999999),
                'wallet_number' => 'WLT-'.substr(bin2hex(random_bytes(5)), 0, 12),
                'balance' => 0,
            ]),
        ];
    }

    /**
     * Source with comments removed, keyed by original line number.
     *
     * This matters: the conversion left comments that NAME the old `round()` calls, so a ratchet
     * matching inside a comment would forbid documenting the change it is enforcing. A single-quote
     * and double-quote aware strip, because `//` also appears inside strings such as URLs.
     *
     * @return array<int, string>
     */
    private function strippedSource(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $out = [];
        $inBlockComment = false;

        foreach ($lines as $i => $line) {
            $result = '';
            $quote = null;
            $len = strlen($line);

            for ($c = 0; $c < $len; $c++) {
                $two = substr($line, $c, 2);

                if ($inBlockComment) {
                    if ($two === '*/') {
                        $inBlockComment = false;
                        $c++;
                    }

                    continue;
                }

                if ($quote !== null) {
                    $result .= $line[$c];

                    if ($line[$c] === '\\') {
                        $c++;
                        $result .= $line[$c] ?? '';
                    } elseif ($line[$c] === $quote) {
                        $quote = null;
                    }

                    continue;
                }

                if ($two === '/*') {
                    $inBlockComment = true;
                    $c++;

                    continue;
                }

                if ($two === '//') {
                    break; // rest of the line is a comment
                }

                if ($line[$c] === "'" || $line[$c] === '"') {
                    $quote = $line[$c];
                }

                $result .= $line[$c];
            }

            $out[$i] = $result;
        }

        return $out;
    }
}
