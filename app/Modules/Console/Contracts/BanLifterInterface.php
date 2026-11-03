<?php

namespace App\Modules\Console\Contracts;

/**
 * Port over lifting an expired ban (stage 1.5, item 3). Implemented by
 * `App\Modules\Console\Services\BanService` and consumed by `JwtAuthHandler`, which runs the check on
 * every authenticated request.
 *
 * One method, because that is the whole of what the request path needs: it asks "is this ban over?"
 * and the module answers by restoring the account. The rest of the ban surface stays inside the
 * Console module.
 */
interface BanLifterInterface
{
    /** Lift the ban when it has expired; true when it was lifted, false when it is still active. */
    public function liftExpiredBan(int $userId): bool;
}
