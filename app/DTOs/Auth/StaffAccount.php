<?php

namespace App\DTOs\Auth;

use App\Enums\StaffRole;
use Carbon\Carbon;

/**
 * Stage 2 C2: the employee fields Identity needs, as a kernel DTO rather than the `Employee` model.
 *
 * WHY IT EXISTS. `employees` is Console's table, and Identity's staff-auth path (login, refresh, `/staff/me`)
 * used to type-hint the `Employee` model, which was the `Identity -> Employee` pair of the ownership ratchet.
 * The DTO lives in the SHARED KERNEL so both sides may name it: Console builds it from the row it owns, and
 * Identity formats/tokenises from it without ever naming the model. It carries no password hash - the
 * login-only shape is {@see StaffCredentials}.
 *
 * It deliberately has NO `fromModel(Employee)` factory: a kernel file naming the moved model is exactly what
 * `ModuleBoundaryTest` rule B forbids once `Employee` leaves `app/Models`, so the CONSUMER constructs it.
 */
final class StaffAccount
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly StaffRole $role,
        public readonly bool $isActive,
        public readonly int $tokenVersion,
        public readonly ?Carbon $lastLoginAt,
        public readonly Carbon $createdAt,
    ) {}

    /** Same expression `Employee::fullName()` uses. */
    public function fullName(): string
    {
        return trim(($this->firstName ?? '').' '.($this->lastName ?? ''));
    }

    /** The three scalars a staff JWT is minted from. */
    public function tokenSubject(): StaffTokenSubject
    {
        return new StaffTokenSubject($this->id, $this->role->value, $this->tokenVersion);
    }

    /**
     * The same account with a new `last_login_at` - used by the login path, which records the login and must
     * report the value it just wrote (the previous code mutated the model in memory before formatting it).
     */
    public function withLastLoginAt(?Carbon $lastLoginAt): self
    {
        return new self(
            id: $this->id,
            username: $this->username,
            email: $this->email,
            firstName: $this->firstName,
            lastName: $this->lastName,
            role: $this->role,
            isActive: $this->isActive,
            tokenVersion: $this->tokenVersion,
            lastLoginAt: $lastLoginAt,
            createdAt: $this->createdAt,
        );
    }
}
