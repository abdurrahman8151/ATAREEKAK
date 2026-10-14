<?php

namespace App\Services\Verification;

use App\Models\User;

/** The result of a successful approval: the saved user and whether they were approved as driver or passenger. */
final class ApprovedVerification
{
    public function __construct(
        public readonly User $user,
        public readonly string $type,
    ) {}
}
