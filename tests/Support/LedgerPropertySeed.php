<?php

namespace Tests\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The seeded scenario generator behind the randomized ledger property test (stage 0.5, step 6).
 *
 * WHY A SEED AT ALL
 *
 * A property test that picks fresh amounts on every run finds bugs a fixed fixture cannot, and it is
 * worthless the moment it fails: nobody can reproduce the amounts that broke it. Every scenario is
 * therefore generated from ONE integer seed for the whole run. The seed is printed before the first
 * case and carried in every assertion message, so a red run is reproduced exactly with:
 *
 *     $env:LEDGER_SEED=<the printed seed>; php vendor/bin/phpunit tests/Feature/Payment
 *
 * Nothing here touches the database; it is pure generation, so the size and determinism of the
 * scenario set can be asserted without a connection.
 */
final class LedgerPropertySeed
{
    /** The movements the property layer covers. */
    public const MOVEMENTS = ['charge', 'payout', 'refund', 'no_show'];

    /** How many randomized cases each movement contributes. 4 x 55 = 220, over the 200 required. */
    public const CASES_PER_MOVEMENT = 55;

    private static ?int $seed = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $scenarios = null;

    public static function currentSeed(): int
    {
        self::bootstrap();

        return (int) self::$seed;
    }

    public static function hasEnvironmentSeed(): bool
    {
        $value = getenv('LEDGER_SEED');

        return $value !== false && ctype_digit((string) $value);
    }

    /**
     * @return array<string, array<string, mixed>> keyed by the case label, so a failure names itself
     */
    public static function scenarios(): array
    {
        self::bootstrap();

        return self::$scenarios ?? [];
    }

    private static function bootstrap(): void
    {
        if (self::$scenarios !== null) {
            return;
        }

        $fromEnvironment = getenv('LEDGER_SEED');
        self::$seed = ($fromEnvironment !== false && ctype_digit((string) $fromEnvironment))
            ? (int) $fromEnvironment
            : random_int(1, 999_999_999);

        self::$scenarios = self::generate((int) self::$seed);

        // Printed on every run, green or red: a green run is exactly when nobody is looking, and a
        // seed discovered only on failure is a seed that was never recorded.
        fwrite(STDERR, sprintf(
            "\n[ledger-seed] seed=%d cases=%d - re-run a failure with LEDGER_SEED=%d\n",
            self::$seed,
            count(self::$scenarios),
            self::$seed
        ));
    }

    /**
     * One scenario per case. Deterministic for a given seed, which is what makes the seed worth
     * printing.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function generate(int $seed): array
    {
        $rng = new Randomizer(new Mt19937($seed));

        $scenarios = [];
        $index = 0;

        foreach (self::MOVEMENTS as $movement) {
            for ($i = 0; $i < self::CASES_PER_MOVEMENT; $i++) {
                $index++;

                // Amounts are built in MINOR UNITS and divided once, so every generated price has
                // exactly two decimals and no float drift of its own. The range deliberately
                // includes one-fils boundaries: `1.01`, `0.01`, and the odd tenths that made the
                // 95/5 split overflow the escrow debit in RV-19.
                $cents = match ($i % 5) {
                    0 => 1,                                  // the smallest chargeable amount
                    1 => $rng->getInt(1, 99),                // under one unit
                    2 => $rng->getInt(100, 9_999),           // single digits
                    3 => $rng->getInt(10_000, 999_999),      // hundreds and thousands
                    default => $rng->getInt(1_000_000, 50_000_000), // tens of thousands
                };

                $price = $cents / 100;
                $seats = $rng->getInt(1, 3);

                $label = sprintf(
                    '%s-price-%s-seats-%d-%03d',
                    $movement,
                    number_format($price, 2, '.', ''),
                    $seats,
                    $i + 1
                );

                $scenarios[$label] = [
                    'seed' => $seed,
                    'index' => $index,
                    'movement' => $movement,
                    'price' => $price,
                    'seats' => $seats,
                    // A refund settles the whole ride, so some scenarios put a SECOND booking on it:
                    // a collection of one booking cannot catch a bug that only appears when the
                    // refund loop runs twice.
                    'second_booking' => in_array($movement, ['refund', 'payout'], true) && $rng->getInt(0, 1) === 1,
                    'second_seats' => $rng->getInt(1, 2),
                ];
            }
        }

        return $scenarios;
    }

    /** The context string every assertion carries, so a failure is reproducible from its message. */
    public static function describe(array $case, string $movement): string
    {
        return sprintf(
            'seed=%d case=%d %s price=%s seats=%d%s',
            (int) $case['seed'],
            (int) $case['index'],
            $movement,
            number_format((float) $case['price'], 2, '.', ''),
            (int) $case['seats'],
            ($case['second_booking'] ?? false) ? ' +second booking of '.$case['second_seats'].' seat(s)' : ''
        );
    }
}
