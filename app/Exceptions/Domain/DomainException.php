<?php

namespace App\Exceptions\Domain;

use RuntimeException;

/**
 * RV-13 (owner-ruled, decision-free half): a domain rule violation is a CLIENT error, not a
 * server error.
 *
 * THE DEFECT THIS FIXES, measured in R2 sec 20.1: **61 services throw
 * `\InvalidArgumentException`**, and `Handler`'s catch-all has no branch for it, so every one that
 * reached the handler was answered **500**. A passenger confirming someone else's booking, or a
 * driver creating a ride they are not verified to create, is a rejected REQUEST - the server did not
 * fail. Reporting it as 500 is wrong three times over:
 *   - the client cannot distinguish "you sent something unacceptable" from "the server broke";
 *   - error monitoring counts ordinary rule rejections as server faults, so a real 500 becomes
 *     invisible in the noise;
 *   - retry logic that retries on 5xx will hammer an endpoint that will never succeed.
 *
 * WHY A HIERARCHY RATHER THAN A SINGLE CLASS. The HTTP status a rule violation deserves is not
 * uniform: "you may not touch that booking" is 403, "there is not enough money" is 409, "that field
 * is unacceptable" is 422. Carrying the status on the exception lets each rule state its own answer
 * instead of the handler guessing for all of them - which is exactly the guessing that produced the
 * blanket 500.
 *
 * This is the FOUNDATION half only. Sweeping the 96 `catch` blocks that return raw `getMessage()`
 * (leaking SQL text and table names to clients) depends on this landing first, and the
 * `{success,data,error{...}}` envelope is a PRODUCT decision because it changes the shape the Flutter
 * client parses - neither is done unilaterally.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * @param  string  $errorCode  stable machine-readable code for the client (never the message)
     * @param  int  $httpStatus  the status this rule violation deserves
     * @param  array<string, mixed>  $context  extra machine-readable detail; MUST be safe to show a
     *                                         client - never a raw exception message, a SQL fragment or an internal id
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The JSON body for this failure.
     *
     * Shaped to match what the handler already emits (`status`/`message`/`code`) so this half does
     * not change the response contract - the envelope question is separate and owner-gated.
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => 'error',
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'status_code' => $this->httpStatus,
            'context' => $this->context ?: null,
        ], fn ($v) => $v !== null);
    }
}
