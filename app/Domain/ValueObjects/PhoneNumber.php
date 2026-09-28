<?php

namespace App\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Value Object for Syrian phone numbers
 * Eliminates phone validation duplication
 */
class PhoneNumber
{
    private string $number;

    private function __construct(string $number)
    {
        $this->number = $this->normalize($number);
        $this->validate();
    }

    public static function from(string $number): self
    {
        return new self($number);
    }

    public function number(): string
    {
        return $this->number;
    }

    public function formatted(): string
    {
        // Format as +963 9XX XXX XXX
        $cleaned = str_replace('+963', '', $this->number);

        return '+963 '.substr($cleaned, 0, 3).' '.
            substr($cleaned, 3, 3).' '.
            substr($cleaned, 6, 3);
    }

    public function forCallMeBot(): string
    {
        // CallMeBot expects 9639XXXXXXXX format
        return str_replace('+', '', $this->number);
    }

    public function forTextMeBot(): string
    {
        // TextMeBot expects +9639XXXXXXXX format
        return $this->number;
    }

    public function equals(PhoneNumber $other): bool
    {
        return $this->number === $other->number;
    }

    private function normalize(string $number): string
    {
        // Remove all non-numeric characters except +
        $clean = preg_replace('/[^0-9+]/', '', $number);

        // Remove leading zeros
        $clean = ltrim($clean, '0');

        // Handle different formats
        if (str_starts_with($clean, '+963')) {
            return $clean; // Already normalized
        }

        if (str_starts_with($clean, '963')) {
            return '+'.$clean;
        }

        if (str_starts_with($clean, '9') && strlen($clean) === 9) {
            return '+963'.$clean;
        }

        if (strlen($clean) === 10 && str_starts_with($clean, '09')) {
            return '+963'.substr($clean, 1);
        }

        throw new InvalidArgumentException("Invalid Syrian phone number format: {$number}");
    }

    private function validate(): void
    {
        // Must be +963 9XX XXX XXX (12 characters total)
        if (! preg_match('/^\+9639\d{8}$/', $this->number)) {
            throw new InvalidArgumentException(
                "Invalid Syrian phone number: {$this->number}. Expected format: +9639XXXXXXXX"
            );
        }
    }

    public function __toString(): string
    {
        return $this->number;
    }
}
