<?php

namespace App\Modules\Identity\Contracts;

/**
 * Port over the staff-token verification surface (stage 1.5, item 3). Implemented by
 * `App\Modules\Identity\Services\StaffJwtService` and consumed by `StaffJwtMiddleware`.
 *
 * Staff tokens carry their own secret, audience and claims, so this port exists separately from
 * `UserTokenVerifierInterface` even though the methods have the same shape: a staff token and a user
 * token are different credentials, and a shared port would have to be generic to say so. Both methods
 * are now scalar-shaped (a token string, a version number), so this contract names no model at all.
 */
interface StaffTokenVerifierInterface
{
    /** @return array<string, mixed>|null the decoded claims, or null when the token is invalid */
    public function decodeToken(string $token): ?array;

    /**
     * Compare the token's version claim with the employee's current token version.
     *
     * Stage 2 C2: the second parameter is the version NUMBER, not the `Employee` row. This port never
     * needed the row - it compared one integer - and taking the model meant the kernel middleware that
     * calls it (and this contract) both named a Console table. The caller already has the row and reads
     * the column, which is the owner's job.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validateTokenVersion(array $payload, int $tokenVersion): bool;
}
