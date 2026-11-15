<?php

namespace App\DTOs\Auth;

/**
 * Stage 2 C2: the login-only employee shape - a {@see StaffAccount} plus the stored password hash.
 *
 * The hash is deliberately kept out of `StaffAccount`: that DTO is also what `/staff/me` and the login response
 * are formatted from, and a credential has no business travelling with a response shape. This one is produced
 * by Console's repository for Identity's `authenticate()` and never leaves the auth path.
 */
final class StaffCredentials
{
    public function __construct(
        public readonly StaffAccount $account,
        public readonly string $passwordHash,
    ) {}
}
