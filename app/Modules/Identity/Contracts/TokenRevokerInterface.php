<?php

namespace App\Modules\Identity\Contracts;

/**
 * Port over token revocation (batch 227). Implemented by `App\Services\JwtService`, which
 * `PasswordResetRepository` used to type-hint directly - the one `App\Services` reference that kept
 * `repos_to_services` above zero.
 */
interface TokenRevokerInterface
{
    /**
     * Revoke every refresh token for the user and bump the token version, which invalidates every
     * outstanding access token immediately.
     */
    public function revokeAllTokens(int $userId): void;
}
