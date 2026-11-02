<?php

namespace App\Modules\Identity\Contracts;

use App\Exceptions\Domain\DomainException;
use App\Models\User;

/**
 * Port over the document-verification gate (batch 233).
 *
 * RIDE asks "is this driver allowed to create this ride?"; IDENTITY owns what a verified driver's
 * document set means. Before this port `RideValidationService` type-hinted
 * `App\Services\Verification\DocumentVerificationService` directly. Implemented by that same
 * service and bound to its singleton.
 */
interface DocumentVerificationInterface
{
    /**
     * @throws DomainException when a required document is missing
     */
    public function validateDriverDocuments(User $user): void;
}
