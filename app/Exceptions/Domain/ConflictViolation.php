<?php

namespace App\Exceptions\Domain;

/**
 * RV-13: the request collided with the current state of the world.
 *
 * 409, not 422: the same request might succeed a moment later. Insufficient wallet balance is the
 * canonical case - the client should top up and retry the SAME request, not change it.
 */
class ConflictViolation extends DomainException
{
    public function __construct(
        string $message,
        string $errorCode = 'CONFLICT',
        array $context = [],
    ) {
        parent::__construct($message, $errorCode, 409, $context);
    }
}
