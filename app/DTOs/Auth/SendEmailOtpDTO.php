<?php

namespace App\DTOs\Auth;

use App\Domain\ValueObjects\Email;
use App\Models\User;

final class SendEmailOtpDTO
{
    public function __construct(
        public readonly Email $email,
        public readonly string $userName,
        public readonly string $type = 'EMAIL_VERIFICATION',
    ) {}

    public static function fromRequest(array $validated, string $userName): self
    {
        return new self(
            email: Email::from($validated['email']),
            userName: $userName,
            type: $validated['type'] ?? 'EMAIL_VERIFICATION',
        );
    }

    public static function fromUser(User $user): self
    {
        return new self(
            email: Email::from($user->email),
            userName: $user->first_name,
        );
    }
}
