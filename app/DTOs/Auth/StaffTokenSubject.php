<?php

namespace App\DTOs\Auth;

/**
 * Stage 2 C2: the three scalars a staff access token is minted from - `sub`, `role` and `ver`.
 *
 * Identity's `StaffJwtInterface::generateTokenPair()` used to take an `Employee` and read exactly these three
 * fields off it; the subject keeps the token contract free of the model (the `Identity -> Employee` pair).
 * Console builds it from the row it owns, Identity's login path builds it from {@see StaffAccount}.
 */
final class StaffTokenSubject
{
    public function __construct(
        public readonly int $id,
        public readonly string $role,
        public readonly int $tokenVersion,
    ) {}
}
