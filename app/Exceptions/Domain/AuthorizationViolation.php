<?php

namespace App\Exceptions\Domain;

/**
 * RV-13: the caller is authenticated but is not allowed to touch this thing.
 *
 * 403, not 422. The distinction matters to the client: 422 says "fix your request", 403 says "do not
 * retry this". Collapsing them is how a client ends up retrying a forbidden action forever.
 */
class AuthorizationViolation extends DomainException
{
    public function __construct(
        string $message,
        string $errorCode = 'FORBIDDEN',
        array $context = [],
    ) {
        parent::__construct($message, $errorCode, 403, $context);
    }
}
