<?php

namespace App\Modules\Identity\Contracts;

use App\Models\Employee;

/**
 * Port over the staff-token verification surface (stage 1.5, item 3). Implemented by
 * `App\Modules\Identity\Services\StaffJwtService` and consumed by `StaffJwtMiddleware`.
 *
 * Staff tokens carry their own secret, audience and claims, so this port exists separately from
 * `UserTokenVerifierInterface` even though two of the three user methods have the same shape: the
 * employee type is not the user type and a shared port would have to be generic to say so.
 */
interface StaffTokenVerifierInterface
{
    /** @return array<string, mixed>|null the decoded claims, or null when the token is invalid */
    public function decodeToken(string $token): ?array;

    /**
     * Compare the token's version claim with the employee's current token version.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validateTokenVersion(array $payload, Employee $employee): bool;
}
