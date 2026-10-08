<?php

namespace Tests\Unit\Domain;

use App\Domain\ValueObjects\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_can_create_from_float(): void
    {
        $money = Money::from(100.50);
        $this->assertEquals(100.50, $money->amount());
    }

    public function test_can_create_zero(): void
    {
        $money = Money::zero();
        $this->assertTrue($money->isZero());
        $this->assertEquals(0.0, $money->amount());
    }

    /**
     * CONTRACT CHANGE (`R2 sec 116`, owner decision, AF-6 criterion 1).
     *
     * This test used to be `test_cannot_create_negative_amount` and asserted that `Money::from(-1)`
     * throws. That was the contract until the owner widened the type, because a money type that cannot
     * hold a negative cannot describe a ledger debit. The spec changed deliberately, so the test was
     * replaced rather than deleted - it now pins the NEW contract, which is the thing that must not
     * silently drift back.
     */
    public function test_can_create_a_negative_amount(): void
    {
        $money = Money::from(-1);

        $this->assertEquals(-1.0, $money->amount());
        $this->assertEquals(-100, $money->amountInMinorUnits());
        $this->assertTrue($money->isNegative());
        $this->assertFalse($money->isPositive());
        $this->assertFalse($money->isZero());
    }

    public function test_can_create_a_negative_amount_from_minor_units(): void
    {
        $this->assertEquals(-250.0, Money::fromMinorUnits(-25_000)->amount());
    }

    public function test_stores_currency(): void
    {
        $money = Money::from(100, 'SYP');
        $this->assertEquals('SYP', $money->currency());
    }

    public function test_add_two_amounts(): void
    {
        $a = Money::from(100);
        $b = Money::from(50);
        $this->assertEquals(150.0, $a->add($b)->amount());
    }

    public function test_subtract_smaller_from_larger(): void
    {
        $a = Money::from(200);
        $b = Money::from(50);
        $this->assertEquals(150.0, $a->subtract($b)->amount());
    }

    /**
     * CONTRACT CHANGE (`R2 sec 116`) - see `test_can_create_a_negative_amount`. `subtract()` used to
     * throw rather than produce a negative, which made "50 minus 100" inexpressible.
     */
    public function test_subtract_can_go_negative(): void
    {
        $result = Money::from(50)->subtract(Money::from(100));

        $this->assertEquals(-50.0, $result->amount());
        $this->assertTrue($result->isNegative());
    }

    /**
     * THE GUARD THAT REPLACED IT. The constructor used to reject negatives as a side effect of
     * building the object, so every caller inherited a rule none of them had asked for and no caller
     * could test on its own. `assertNotNegative()` is the same rule, stated by the caller that has it.
     */
    public function test_assert_not_negative_rejects_a_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Escrow amount cannot be negative');

        Money::from(-0.01)->assertNotNegative('Escrow amount');
    }

    public function test_assert_not_negative_accepts_zero_and_positive(): void
    {
        Money::zero()->assertNotNegative('Escrow amount');
        Money::from(0.01)->assertNotNegative('Escrow amount');

        // No exception is the assertion; reaching here is the pass.
        $this->assertTrue(true);
    }

    public function test_assert_positive_rejects_zero_where_only_a_credit_is_meaningful(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Wallet charge amount must be positive');

        Money::zero()->assertPositive('Wallet charge amount');
    }

    public function test_negated_flips_the_sign_exactly(): void
    {
        $this->assertEquals(-777.0, Money::from(777.0)->negated()->amount());
        $this->assertEquals(777.0, Money::from(-777.0)->negated()->amount());
        $this->assertTrue(Money::from(777.0)->negated()->negated()->equals(Money::from(777.0)),
            'negation must be exactly reversible');
    }

    public function test_absolute_drops_the_sign(): void
    {
        $this->assertEquals(777.0, Money::from(-777.0)->absolute()->amount());
        $this->assertTrue(Money::from(-777.0)->absolute()->isPositive());
    }

    /**
     * The operand guards are a DIFFERENT rule from the sign rule and must survive the widening: a
     * negative multiplier is a caller mistake whatever this amount's sign is.
     */
    public function test_multiply_rejects_a_negative_multiplier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiplier cannot be negative');

        Money::from(-100)->multiply(-2);
    }

    public function test_multiply_keeps_the_receivers_sign(): void
    {
        $this->assertEquals(-200.0, Money::from(-100)->multiply(2)->amount());
        $this->assertEquals(200.0, Money::from(100)->multiply(2)->amount());
    }

    public function test_add_keeps_the_signs(): void
    {
        $this->assertEquals(-50.0, Money::from(-100)->add(Money::from(50))->amount());
    }

    public function test_comparisons_work_across_zero(): void
    {
        $this->assertTrue(Money::from(-100)->isLessThan(Money::from(0)));
        $this->assertTrue(Money::from(-100)->isLessThan(Money::from(100)));
        $this->assertTrue(Money::from(-100)->isGreaterThan(Money::from(-200)));
    }

    public function test_formatted_keeps_the_minus_sign(): void
    {
        $this->assertEquals('-777.00 SYP', Money::from(-777.0)->formatted());
        $this->assertEquals('-777.00', Money::from(-777.0)->formattedWithoutCurrency());
    }

    public function test_multiply(): void
    {
        $this->assertEquals(300.0, Money::from(100)->multiply(3)->amount());
    }

    public function test_multiply_by_zero_gives_zero(): void
    {
        $this->assertTrue(Money::from(500)->multiply(0)->isZero());
    }

    public function test_divide(): void
    {
        $this->assertEquals(100.0, Money::from(300)->divide(3)->amount());
    }

    public function test_divide_by_zero_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::from(100)->divide(0);
    }

    public function test_five_percent_of_one_million_syp(): void
    {
        $fee = Money::from(1_000_000)->percentage(5);
        $this->assertEquals(50_000.0, $fee->amount());
    }

    public function test_percentage_of_zero_is_zero(): void
    {
        $this->assertTrue(Money::from(0)->percentage(5)->isZero());
    }

    public function test_100_percent_returns_full_amount(): void
    {
        $this->assertEquals(500.0, Money::from(500)->percentage(100)->amount());
    }

    public function test_70_percent_refund_calculation(): void
    {
        $paid = Money::from(200_000);
        $refund = $paid->percentage(70);
        $driverKeeps = $paid->subtract($refund);
        $this->assertEquals(140_000.0, $refund->amount());
        $this->assertEquals(60_000.0, $driverKeeps->amount());
    }

    public function test_50_percent_refund_calculation(): void
    {
        $this->assertEquals(50_000.0, Money::from(100_000)->percentage(50)->amount());
    }

    public function test_zero_percent_refund(): void
    {
        $this->assertTrue(Money::from(100_000)->percentage(0)->isZero());
    }

    public function test_greater_than(): void
    {
        $this->assertTrue(Money::from(200)->isGreaterThan(Money::from(100)));
        $this->assertFalse(Money::from(100)->isGreaterThan(Money::from(200)));
    }

    public function test_less_than(): void
    {
        $this->assertTrue(Money::from(50)->isLessThan(Money::from(100)));
    }

    public function test_equals(): void
    {
        $this->assertTrue(Money::from(100)->equals(Money::from(100)));
        $this->assertFalse(Money::from(100)->equals(Money::from(101)));
    }

    public function test_cannot_compare_different_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::from(100, 'SYP')->isGreaterThan(Money::from(100, 'USD'));
    }

    public function test_formatted_output(): void
    {
        $money = Money::from(50000);
        $this->assertStringContainsString('SYP', $money->formatted());
    }

    public function test_no_floating_point_precision_errors(): void
    {
        $result = Money::from(0.1)->add(Money::from(0.2));
        $this->assertEquals(0.30, $result->amount());
    }

    public function test_is_positive(): void
    {
        $this->assertTrue(Money::from(1)->isPositive());
        $this->assertFalse(Money::zero()->isPositive());
    }

    public function test_to_array(): void
    {
        $arr = Money::from(100)->toArray();
        $this->assertArrayHasKey('amount', $arr);
        $this->assertArrayHasKey('currency', $arr);
        $this->assertArrayHasKey('formatted', $arr);
    }
}
