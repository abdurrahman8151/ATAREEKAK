<?php

namespace App\Services\Verification;

use RuntimeException;

/** Thrown when a national ID is already linked to another verified account. */
final class DuplicateNationalIdException extends RuntimeException
{
    public function __construct(public readonly int $conflictingUserId)
    {
        parent::__construct('National ID is already linked to another account.');
    }
}
