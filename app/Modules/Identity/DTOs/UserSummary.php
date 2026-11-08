<?php

namespace App\Modules\Identity\DTOs;

/**
 * Stage 2 C3: what another module may know about a user.
 *
 * `App\Models\User` stays in the kernel as the authenticated principal (ADR 0007), so a module that
 * merely types a controller against `$request->user()` still imports it. What a module may NOT do is
 * read another user's *data* - a name in an admin list, an email on an invoice. This DTO is the shape
 * that read takes: plain scalars, already cast, and read-only by construction, so a caller cannot
 * `save()` the row it was handed.
 *
 * The property names match the columns they come from, for the same reason `WalletSummary`'s do: the
 * caller is building a response that already reads `first_name` and `email`, and a rename here would
 * turn a refactor into a response-shape change.
 */
final class UserSummary
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $email,
        public readonly ?string $status,
        public readonly bool $isVerifiedPassenger,
        public readonly bool $isVerifiedDriver,
    ) {}

    /**
     * The concatenation the whole codebase performs inline (`trim($first_name.' '.$last_name)`, see
     * RideResource and BookingResource), kept here so a caller rendering a name does not have to
     * reconstruct the rule - and get a subtly different one, with a stray space for a missing part.
     *
     * `User` has no fullName() of its own; `Employee` does. The rule, not the method, is what is shared.
     */
    public function fullName(): string
    {
        return trim(((string) $this->firstName).' '.((string) $this->lastName));
    }
}
