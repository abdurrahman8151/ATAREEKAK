<?php

namespace App\Modules\Identity\Contracts;

/**
 * Port over expired user-refresh-token cleanup (stage 1.5, item 3). Implemented by
 * `App\Modules\Identity\Services\JwtService` and consumed by the `CleanupExpiredTokens` artisan
 * command, which used to type-hint the service directly because no port exposed the sweep.
 */
interface UserTokenCleanupInterface
{
    /** Delete the user refresh tokens that are past their expiry and report how many went. */
    public function cleanupExpiredTokens(): int;
}
