<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\LedgerPropertySeed;

/**
 * The randomized property layer's generator, checked without a database.
 *
 * The value of a randomized test rests entirely on its reproducibility: 200+ cases that cannot be
 * regenerated are a red build with no way back. These tests pin the three properties that make the
 * seed worth printing - enough cases, the same cases for the same seed, different cases for a
 * different seed.
 */
final class LedgerPropertySeedTest extends TestCase
{
    public function test_it_generates_at_least_two_hundred_cases_across_the_four_movements(): void
    {
        $scenarios = LedgerPropertySeed::generate(20261101);

        $this->assertGreaterThanOrEqual(
            200,
            count($scenarios),
            'step 6 requires 200+ seeded cases; got '.count($scenarios)
        );

        $byMovement = [];

        foreach ($scenarios as $scenario) {
            $byMovement[$scenario['movement']] = ($byMovement[$scenario['movement']] ?? 0) + 1;
        }

        $expected = [];

        foreach (LedgerPropertySeed::MOVEMENTS as $movement) {
            $expected[$movement] = LedgerPropertySeed::CASES_PER_MOVEMENT;
        }

        $this->assertSame($expected, $byMovement, 'every movement must contribute the same number of cases');
    }

    public function test_the_same_seed_produces_the_same_cases_and_a_different_seed_does_not(): void
    {
        $first = LedgerPropertySeed::generate(424242);
        $again = LedgerPropertySeed::generate(424242);
        $other = LedgerPropertySeed::generate(424243);

        $this->assertSame($first, $again, 'the printed seed must reproduce the exact scenario set');
        $this->assertNotSame($first, $other, 'a different seed must explore different scenarios');
    }

    public function test_every_case_name_is_unique_and_carries_the_amount_it_will_use(): void
    {
        $scenarios = LedgerPropertySeed::generate(7);

        $this->assertSame(
            count($scenarios),
            count(array_unique(array_keys($scenarios))),
            'case labels must be unique, or a PHPUnit failure names two cases'
        );

        foreach ($scenarios as $label => $scenario) {
            $this->assertStringContainsString(
                number_format((float) $scenario['price'], 2, '.', ''),
                $label,
                'the case name must show the amount, so a failure is readable without the seed'
            );

            $this->assertSame(
                round((float) $scenario['price'], 2),
                (float) $scenario['price'],
                'generated prices must have exactly two decimals - the money is in minor units'
            );

            $this->assertGreaterThanOrEqual(1, $scenario['seats']);
            $this->assertLessThanOrEqual(3, $scenario['seats']);
        }
    }

    public function test_the_context_line_names_the_seed_the_case_and_the_amount(): void
    {
        $case = ['seed' => 99, 'index' => 12, 'price' => 1234.5, 'seats' => 2, 'second_booking' => true, 'second_seats' => 1];

        $this->assertSame(
            'seed=99 case=12 charge price=1234.50 seats=2 +second booking of 1 seat(s)',
            LedgerPropertySeed::describe($case, 'charge')
        );
    }
}
