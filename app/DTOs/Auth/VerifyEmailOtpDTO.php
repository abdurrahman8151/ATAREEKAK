<?php

namespace App\DTOs\Auth;

use App\Domain\ValueObjects\Email;

final class VerifyEmailOtpDTO
{
    public function __construct(
        public readonly Email $email,
        public readonly string $otpCode,
    ) {}

    public static function fromRequest(array $validated): self
    {
        return new self(
            email: Email::from($validated['email']),
            otpCode: $validated['otp_code'],
        );
    }
}
