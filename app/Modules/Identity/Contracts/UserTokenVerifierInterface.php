<?php

namespace App\Modules\Identity\Contracts;

use App\Models\User;

/**
 * Port over the user-token verification surface (stage 1.5, item 3): decode a bearer token, rehydrate
 * its subject, and check the token version against the cached user. Implemented by
 * `App\Modules\Identity\Services\JwtService`.
 *
 * The three methods are one port on purpose - `JwtAuthHandler` calls them in sequence on every
 * authenticated request, and splitting them would turn one auth decision into three ports that must
 * stay in lockstep.
 */
interface UserTokenVerifierInterface
{
    /** @return array<string, mixed>|null the decoded claims, or null when the token is invalid */
    public function decodeToken(string $token): ?array;

    /** The token's subject, cached per request; null when the user is gone. */
    public function findUserCached(int $userId): ?User;

    /**
     * Compare the token's `ver` claim with the user's current token version. A mismatch means the
     * token was revoked by a logout, a ban or a password reset.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validateTokenVersion(array $payload, User $user): bool;
}
