<?php

namespace App\Modules\Identity\Contracts;

use App\DTOs\Auth\StaffTokenSubject;

/**
 * Port over the employee token issuer (batch 233).
 *
 * CONSOLE authenticates employees and revokes their tokens; IDENTITY owns the token format, the
 * hashing and the version bump. Before this port `AdminAuthService` and `EmployeeManagementService`
 * type-hinted `App\Modules\Identity\Services\StaffJwtService` directly. Only the three methods those callers
 * use are on the port; `decodeToken`/`validateTokenVersion` are the middleware's business and stay
 * on the concrete service.
 *
 * Stage 2 C2: `generateTokenPair()` takes the kernel `StaffTokenSubject` (id, role, token version) instead of
 * an `Employee` - Identity no longer names the model Console owns. Console builds the subject from the row it
 * holds; the login path builds it from `StaffAccount`.
 */
interface StaffJwtInterface
{
    /** @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int} */
    public function generateTokenPair(StaffTokenSubject $subject): array;

    /** @return array{access_token: string, expires_in: int}|null */
    public function refreshAccessToken(string $refreshToken): ?array;

    public function revokeAllTokens(int $employeeId): void;
}
