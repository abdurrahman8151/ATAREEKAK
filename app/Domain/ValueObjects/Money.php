<?php

namespace App\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Money Value Object
 *
 * FIXED: Uses integer (minor units) to avoid floating point errors
 *
 * Stores amounts as integers (fils) internally:
 * - 100.50 SYP = 10050 fils
 * - All calculations use integers
 * - No precision errors
 *
 * Example:
 *   $price = Money::from(100.50); // Stored as 10050 fils
 *   $total = $price->multiply(3); // 301.50 SYP (30150 fils)
 *
 * SIGNED, since `R2 sec 116` (owner decision, AF-6 criterion 1).
 *
 * This constructor used to reject negatives, and that made `Money` unusable for the one job it most
 * obviously has: describing the ledger. A debit IS a negative amount. `postTwoPartyTransfer` emits
 * `-amount`, `postExternalTransfer` emits `-amount` on the external leg, and `FeeSplit::releaseLegs`
 * emits the negated escrow leg - so a type that cannot hold a negative cannot represent half the
 * system's money movements, and the arithmetic had to stay as raw floats outside this class.
 *
 * THE PROTECTION DID NOT DISAPPEAR; IT MOVED. The old guard was a side effect of construction: any
 * caller who accidentally computed a negative got an exception, and any caller who legitimately needed
 * one was stuck. That is the wrong place for a domain rule - the rule belongs to the caller that has
 * the rule. `assertNotNegative()` now states it explicitly, and the two places that genuinely require a
 * non-negative amount call it: `FeeSplit::driverAndPlatform` (a negative escrow release would split
 * into two negative shares that still sum correctly, which is arithmetic that is right and nonsense
 * money) and `AdminWalletService::chargeWallet` (a "charge" is a credit; a negative one is a debit
 * wearing the wrong label and would post an `admin_credit` for it).
 *
 * Note that `multiply()`, `divide()` and `percentage()` still reject a negative OPERAND. That is a
 * different rule and it survives: a negative multiplier or a 110% divisor is a caller mistake whatever
 * the sign of the receiver, whereas the sign of the RESULT is now data.
 */
class Money
{
    private int $amountInMinorUnits; // Stored as fils (1 SYP = 100 fils)

    private string $currency;

    private const MINOR_UNITS_PER_MAJOR = 100; // 100 fils = 1 SYP

    private function __construct(int $amountInMinorUnits, string $currency = 'SYP')
    {
        $this->amountInMinorUnits = $amountInMinorUnits;
        $this->currency = $currency;
    }

    /**
     * Create from major units (e.g., 100.50 SYP)
     */
    public static function from(float $amount, string $currency = 'SYP'): self
    {
        $minorUnits = (int) round($amount * self::MINOR_UNITS_PER_MAJOR);

        return new self($minorUnits, $currency);
    }

    /**
     * Create from minor units (e.g., 10050 fils = 100.50 SYP)
     */
    public static function fromMinorUnits(int $minorUnits, string $currency = 'SYP'): self
    {
        return new self($minorUnits, $currency);
    }

    /**
     * Create zero money
     */
    public static function zero(string $currency = 'SYP'): self
    {
        return new self(0, $currency);
    }

    /**
     * Get amount in major units (e.g., 100.50)
     */
    public function amount(): float
    {
        return $this->amountInMinorUnits / self::MINOR_UNITS_PER_MAJOR;
    }

    /**
     * Get amount in minor units (e.g., 10050)
     * Useful for database storage
     */
    public function amountInMinorUnits(): int
    {
        return $this->amountInMinorUnits;
    }

    /**
     * Get currency code
     */
    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * Add another money amount
     */
    public function add(Money $other): self
    {
        $this->ensureSameCurrency($other);

        return new self(
            $this->amountInMinorUnits + $other->amountInMinorUnits,
            $this->currency
        );
    }

    /**
     * Subtract another money amount.
     *
     * The result may be negative (`R2 sec 116`). It used to throw instead, which made it impossible to
     * express "50 minus 100" - the shape every ledger debit has. A caller that must not overdraw now
     * says so with `assertNotNegative()` on the result, or checks `isNegative()`, instead of relying on
     * an exception nobody documented.
     */
    public function subtract(Money $other): self
    {
        $this->ensureSameCurrency($other);

        return new self($this->amountInMinorUnits - $other->amountInMinorUnits, $this->currency);
    }

    /**
     * Multiply by a number.
     *
     * A negative MULTIPLIER is still rejected - that is a caller mistake whatever the receiver's sign -
     * but the result now inherits the receiver's sign, so a negative amount stays negative.
     */
    public function multiply(float $multiplier): self
    {
        if ($multiplier < 0) {
            throw new InvalidArgumentException('Multiplier cannot be negative');
        }

        $result = (int) round($this->amountInMinorUnits * $multiplier);

        return new self($result, $this->currency);
    }

    /**
     * Divide by a number
     */
    public function divide(float $divisor): self
    {
        if ($divisor <= 0) {
            throw new InvalidArgumentException('Divisor must be positive');
        }

        $result = (int) round($this->amountInMinorUnits / $divisor);

        return new self($result, $this->currency);
    }

    /**
     * Calculate percentage of this amount
     */
    public function percentage(float $percentage): self
    {
        if ($percentage < 0) {
            throw new InvalidArgumentException('Percentage cannot be negative');
        }

        $result = (int) round(($this->amountInMinorUnits * $percentage) / 100);

        return new self($result, $this->currency);
    }

    /**
     * Check if greater than another amount
     */
    public function isGreaterThan(Money $other): bool
    {
        $this->ensureSameCurrency($other);

        return $this->amountInMinorUnits > $other->amountInMinorUnits;
    }

    /**
     * Check if less than another amount
     */
    public function isLessThan(Money $other): bool
    {
        $this->ensureSameCurrency($other);

        return $this->amountInMinorUnits < $other->amountInMinorUnits;
    }

    /**
     * Check if greater than or equal to another amount
     */
    public function isGreaterThanOrEqual(Money $other): bool
    {
        $this->ensureSameCurrency($other);

        return $this->amountInMinorUnits >= $other->amountInMinorUnits;
    }

    /**
     * Check if less than or equal to another amount
     */
    public function isLessThanOrEqual(Money $other): bool
    {
        $this->ensureSameCurrency($other);

        return $this->amountInMinorUnits <= $other->amountInMinorUnits;
    }

    /**
     * Check if equal to another amount
     */
    public function equals(Money $other): bool
    {
        return $this->amountInMinorUnits === $other->amountInMinorUnits
            && $this->currency === $other->currency;
    }

    /**
     * Check if amount is zero
     */
    public function isZero(): bool
    {
        return $this->amountInMinorUnits === 0;
    }

    /**
     * Check if amount is positive
     */
    public function isPositive(): bool
    {
        return $this->amountInMinorUnits > 0;
    }

    /**
     * Check if amount is negative (`R2 sec 116`).
     *
     * The ledger needs this constantly: a leg is negative or it is not, and there is no third answer.
     */
    public function isNegative(): bool
    {
        return $this->amountInMinorUnits < 0;
    }

    /**
     * This amount with its sign flipped (`R2 sec 116`).
     *
     * This is the operation the ledger paths were missing and worked around with raw unary minus on
     * floats. `FeeSplit::releaseLegs` builds its negated escrow leg with `-$split['platform'] -
     * $split['driver']`; expressed in `Money` it is exact in integer minor units like the rest.
     */
    public function negated(): self
    {
        return new self(-$this->amountInMinorUnits, $this->currency);
    }

    /**
     * This amount with any sign removed (`R2 sec 116`).
     *
     * `AdminReportService` already calls `abs()` on two ledger sums purely because `Money` could not
     * hold the value it was formatting; with a signed type the caller decides whether the sign is
     * meaningful rather than hiding it.
     */
    public function absolute(): self
    {
        return new self(abs($this->amountInMinorUnits), $this->currency);
    }

    /**
     * Assert this amount is not negative (`R2 sec 116`).
     *
     * THIS is the rule that used to live in the constructor. It is now stated by the caller that
     * actually has the rule, which is both testable on its own and impossible to trip accidentally from
     * arithmetic that legitimately needs to go negative.
     *
     * @param  string  $what  what is being asserted, used verbatim in the message
     *
     * @throws InvalidArgumentException
     */
    public function assertNotNegative(string $what = 'Amount'): void
    {
        if ($this->amountInMinorUnits < 0) {
            throw new InvalidArgumentException($what.' cannot be negative, got '.$this->formatted());
        }
    }

    /**
     * Assert this amount is strictly positive (`R2 sec 116`).
     *
     * @throws InvalidArgumentException
     */
    public function assertPositive(string $what = 'Amount'): void
    {
        if ($this->amountInMinorUnits <= 0) {
            throw new InvalidArgumentException($what.' must be positive, got '.$this->formatted());
        }
    }

    /**
     * Get formatted string representation
     */
    public function formatted(): string
    {
        return number_format($this->amount(), 2).' '.$this->currency;
    }

    /**
     * Get formatted string without currency
     */
    public function formattedWithoutCurrency(): string
    {
        return number_format($this->amount(), 2);
    }

    /**
     * Ensure same currency for operations
     */
    private function ensureSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot operate on different currencies: {$this->currency} vs {$other->currency}"
            );
        }
    }

    /**
     * Convert to array representation
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount(),
            'amount_in_minor_units' => $this->amountInMinorUnits,
            'currency' => $this->currency,
            'formatted' => $this->formatted(),
        ];
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return $this->formatted();
    }
}
