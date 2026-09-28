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
 */
class Money
{
    private int $amountInMinorUnits; // Stored as fils (1 SYP = 100 fils)

    private string $currency;

    private const MINOR_UNITS_PER_MAJOR = 100; // 100 fils = 1 SYP

    private function __construct(int $amountInMinorUnits, string $currency = 'SYP')
    {
        if ($amountInMinorUnits < 0) {
            throw new InvalidArgumentException('Amount cannot be negative');
        }

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
     * Subtract another money amount
     */
    public function subtract(Money $other): self
    {
        $this->ensureSameCurrency($other);
        $result = $this->amountInMinorUnits - $other->amountInMinorUnits;

        if ($result < 0) {
            throw new InvalidArgumentException('Subtraction would result in negative amount');
        }

        return new self($result, $this->currency);
    }

    /**
     * Multiply by a number
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
