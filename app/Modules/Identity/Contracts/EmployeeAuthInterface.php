<?php

namespace App\Modules\Identity\Contracts;

use App\DTOs\Auth\StaffAccount;

/**
 * Port over the employee (staff) authentication surface.
 *
 * The staff door is a Console controller (`StaffAuthController`) and staff management lives in the
 * Console context, while the authentication logic belongs to Identity. Stage 1 phase 2 moved
 * `EmployeeAuthService` into `Modules/Identity`, which made the Console controller a kernel file that
 * named a module implementation - forbidden by the boundary rule and, once Console moves, a
 * cross-context import. This port is the boundary that keeps both sides honest: Identity owns the
 * implementation, Console injects the contract.
 *
 * Stage 2 C2: `formatEmployee()` takes the kernel `StaffAccount` DTO, not the `Employee` model - Identity does
 * not name the row Console owns. Console constructs the account from the row it already holds.
 */
interface EmployeeAuthInterface
{
    /** @return array<string, mixed>|null */
    public function authenticate(string $identifier, string $password): ?array;

    /** @return array<string, mixed>|null */
    public function refresh(string $refreshToken): ?array;

    public function logout(int $employeeId): void;

    /** @return array<string, mixed> */
    public function formatEmployee(StaffAccount $account): array;
}
