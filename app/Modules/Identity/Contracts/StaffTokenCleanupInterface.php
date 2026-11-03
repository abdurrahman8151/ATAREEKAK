<?php

namespace App\Modules\Identity\Contracts;

/**
 * Port over expired staff-refresh-token cleanup (stage 1.5, item 3). Implemented by
 * `App\Modules\Identity\Services\StaffJwtService` and consumed by the
 * `CleanupExpiredStaffTokens` artisan command.
 *
 * Deliberately separate from `UserTokenCleanupInterface`: the two tables and the two token secrets
 * are different surfaces, and one command must never clean the other's rows.
 */
interface StaffTokenCleanupInterface
{
    /** Delete the staff refresh tokens that are past their expiry and report how many went. */
    public function cleanupExpiredTokens(): int;
}
