<?php

namespace App\Domain\ValueObjects;

use InvalidArgumentException;

final class Email
{
    private string $address;

    private function __construct(string $address)
    {
        $this->address = $this->normalize($address);
        $this->validate();
    }

    public static function from(string $address): self
    {
        return new self($address);
    }

    public function address(): string
    {
        return $this->address;
    }

    public function equals(Email $other): bool
    {
        return $this->address === $other->address;
    }

    private function normalize(string $address): string
    {
        return strtolower(trim($address));
    }

    private function validate(): void
    {
        if (! filter_var($this->address, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(
                "Invalid email address: {$this->address}"
            );
        }
    }

    public function __toString(): string
    {
        return $this->address;
    }
}
