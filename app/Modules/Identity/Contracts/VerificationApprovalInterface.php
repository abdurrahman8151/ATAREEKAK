<?php

namespace App\Modules\Identity\Contracts;

use App\DTOs\Verification\ApprovedVerification;
use App\Exceptions\Domain\DuplicateNationalIdException;

/**
 * Port over the identity-approval step (batch 233).
 *
 * CONSOLE approves a submitted national ID; IDENTITY owns the uniqueness rule, the document-type
 * decision and the `UserVerified` event. Before this port `AdminDashboardController` and
 * `StaffAdminController` type-hinted `App\Modules\Identity\Services\VerificationApprovalService`.
 *
 * `ApprovedVerification` moved to `App\DTOs\Verification` in the same step so this kernel interface
 * does not have to name a class in the IDENTITY context.
 */
interface VerificationApprovalInterface
{
    /**
     * @throws DuplicateNationalIdException when the ID belongs to another account
     */
    public function approve(int $userId, string $nationalId): ApprovedVerification;
}
