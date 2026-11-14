<?php

namespace App\Modules\Identity\Services;

use App\DTOs\Verification\ApprovedVerification;
use App\Events\UserVerified;
use App\Exceptions\Domain\DuplicateNationalIdException;
use App\Models\User;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use App\Modules\Identity\Contracts\VerificationApprovalInterface;
use App\Modules\Identity\Contracts\VerificationRepositoryInterface;

/**
 * L4 (modular-monolith slice 3): the one place that approves a user's identity verification.
 *
 * Before this, the same approval lived in two controllers and had already drifted:
 *   - `StaffAdminController::approveVerification`   fired the real-time `UserVerified` event
 *   - `AdminDashboardController::approveVerification` did not, so an admin-approved user's app
 *     never received the live update.
 *
 * This service owns the rules: the national ID must not already belong to another account, the
 * driver-or-passenger decision comes from the submitted documents, the national ID is saved, and
 * the event is fired. Controllers keep their own validation, response shape and cache busting.
 */
final class VerificationApprovalService implements VerificationApprovalInterface
{
    public function __construct(
        private readonly VerificationRepositoryInterface $verificationRepo,
        private readonly PhotoRepositoryInterface $photos,
    ) {}

    /**
     * Returns the approved user together with the verification type ('driver' or 'passenger').
     *
     * @throws DuplicateNationalIdException when the ID is already linked to another account.
     */
    public function approve(int $userId, string $nationalId): ApprovedVerification
    {
        $duplicate = User::where('national_id', $nationalId)
            ->where('id', '!=', $userId)
            ->first();

        if ($duplicate) {
            throw new DuplicateNationalIdException($duplicate->id);
        }

        // Kept for its 404: approving a user that does not exist must not proceed, and the thrown
        // ModelNotFoundException is what the two controllers turn into their not-found response.
        User::findOrFail($userId);

        // Stage 2 C2: `photos` is this module's table, and the rows are read through the port's
        // batched lookup instead of the `User::photos()` relation. ONE query in, one query out, so
        // the endpoint's query count does not move - and the relation can go when the model moves.
        $docTypes = array_column($this->photos->documentsFor([$userId])[$userId] ?? [], 'type');

        $isDriver = array_intersect($docTypes, PhotoRepositoryInterface::DRIVER_DOCUMENT_TYPES) !== [];
        $type = $isDriver ? 'driver' : 'passenger';

        $verified = $isDriver
            ? $this->verificationRepo->verifyDriver($userId)
            : $this->verificationRepo->verifyPassenger($userId);

        // Saved once; cannot be changed through normal flows after this point.
        $verified->national_id = $nationalId;
        $verified->save();

        // Real-time update for the user's app. Fired for every approval path.
        event(new UserVerified($verified, $type));

        return new ApprovedVerification($verified, $type);
    }
}
