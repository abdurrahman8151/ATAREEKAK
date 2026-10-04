<?php

namespace App\Exceptions\Domain;

/**
 * RV-13: the rule was understood and refused - "you cannot do this, and here is why".
 *
 * 422 by default: the request was well-formed and the caller is authenticated, but the domain
 * refused it. A subclass may raise a different status where the rule genuinely implies one (see
 * `AuthorizationViolation` for 403, `ConflictViolation` for 409).
 */
class BusinessRuleViolation extends DomainException
{
    public function __construct(
        string $message,
        string $errorCode = 'BUSINESS_RULE_VIOLATION',
        int $httpStatus = 422,
        array $context = [],
    ) {
        parent::__construct($message, $errorCode, $httpStatus, $context);
    }
}
