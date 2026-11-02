<?php

namespace App\Modules\Identity\Http\Handlers;

use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * Identity and licence document upload for DocumentController, moved out verbatim.
 *
 * DocumentController keeps store() with the same signature and delegates here, so the route binding and
 * the response shape are unchanged. The body is the original one: the 403 refusal while pending, the 422
 * validation, the documents-disk write with its 500 on a failed store, the identity revocation (both
 * verifications cleared), the driver-document revocation (driver cleared, passenger kept when verified),
 * and the cache busts are all kept exactly.
 */
class DocumentUploadHandler
{
    public function __construct(private readonly PhotoRepositoryInterface $photoRepo) {}

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->verification_status === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot modify documents while verification is pending',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:face_id,back_id,license,mechanic_card',
            'file' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $this->photoRepo->deleteDocumentsByType($user->id, $request->type);

        $path = $this->storeUploadedFile($request);

        if ($path === false) {
            // Storage::put returning false with throw=false means a disk misconfiguration (bucket
            // missing, endpoint wrong, credentials rejected). Returning 500 here beats storing
            // nothing and reporting success, which would leave a user with a "submitted" document
            // that does not exist.
            return response()->json([
                'success' => false,
                'message' => 'Could not store the document. Please try again.',
            ], 500);
        }

        $photo = $this->photoRepo->storeDocument($user->id, $request->type, $path);

        $this->revokeStaleVerification($user, $request->type);
        $this->bustVerificationCaches($user->id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $photo->id,
                'url' => asset("storage/{$path}"),
                'type' => $photo->type,
            ],
        ]);
    }

    /**
     * Write the upload to the configured documents disk.
     *
     * Decision un1 (owner, 2026-10-02): which disk KYC documents land on is a deploy decision.
     *
     * RV-01 (owner instruction 2026-10-08): `documents_disk` no longer defaults to `public`.
     * It defaults to the PRIVATE `local` disk, so a fresh deploy writes identity documents
     * somewhere only the staff-authenticated route can reach, instead of leaving them readable
     * at /storage/documents/... by anyone who guesses the path. The hard-coded `'public'` fallback
     * in this config() call is therefore a dead default and is kept as 'local' to match, so a
     * missing config key can never silently re-open the exposure.
     *
     * Set `DOCUMENTS_DISK=minio` when the app runs on more than one host and the files need to be
     * shared. That is a scaling choice; the privacy fix does not depend on it.
     *
     * NOTE the store() return value is stored verbatim in photos.path. On a private S3 disk
     * Laravel returns "documents/xxx.jpg" (the key), NOT a disk-prefixed path - so the value
     * already stored by the public disk keeps working unchanged when the disk is switched. That
     * is why DOCUMENTS_PREFIX below deliberately stays empty: a prefix would break every
     * existing row.
     */
    private function storeUploadedFile(Request $request): string|false
    {
        $disk = config('filesystems.documents_disk', 'local');

        return $request->file('file')->store('documents', $disk);
    }

    /**
     * Revoke the verification the replaced document invalidates.
     *
     * Identity documents (face_id, back_id): the admin approved this account by matching the
     * submitted ID photo to the person's name and face. Replacing either ID document means that
     * check is no longer valid for BOTH passenger and driver verification.
     *
     * Driver documents (license, mechanic_card): these only relate to the driver review.
     * Replacing them does not invalidate passenger verification because the person is still the
     * same individual - and when the user is a verified passenger, verification_status is left
     * alone so the admin panel does not lose track of that approval.
     *
     * @param  object  $user  the authenticated user model (untyped on purpose to keep this
     *                        handler free of model-class imports, per the controller budget)
     */
    private function revokeStaleVerification($user, string $type): void
    {
        $isIdentityDoc = in_array($type, ['face_id', 'back_id']);
        $isDriverDoc = in_array($type, ['license', 'mechanic_card']);

        $updates = ['verification_status' => 'none'];

        if ($isIdentityDoc) {
            $updates['is_verified_passenger'] = false;
            $updates['is_verified_driver'] = false;
        } elseif ($isDriverDoc) {
            $updates['is_verified_driver'] = false;

            if ($user->is_verified_passenger) {
                unset($updates['verification_status']);
            }
        }

        $user->update($updates);
    }

    /**
     * Bust every cache that embeds verification status or document lists: the verification
     * status endpoint, the admin driver profile, both admin passenger views, and the staff
     * pending-verifications list.
     */
    private function bustVerificationCaches(int $userId): void
    {
        Cache::forget("verification.status.{$userId}");

        Cache::forget("admin.driver.profile.{$userId}");
        Cache::forget("admin.passenger.full-profile.{$userId}");
        Cache::forget("admin.passenger.stats.{$userId}");
        Cache::forget('staff.pending-verifications');
    }
}
