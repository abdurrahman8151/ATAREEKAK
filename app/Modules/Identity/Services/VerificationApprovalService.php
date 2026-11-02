<?php

namespace App\Modules\Identity\Services;

use App\DTOs\Verification\ApprovedVerification;
use App\Events\UserVerified;
use App\Exceptions\Domain\DuplicateNationalIdException;
use App\Models\User;
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

        $user = User::with('photos')->findOrFail($userId);
        $docTypes = $user->photos->pluck('type')->toArray();
        $isDriver = in_array('license', $docTypes) || in_array('mechanic_card', $docTypes);
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
