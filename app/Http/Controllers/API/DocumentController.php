<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\PhotoRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class DocumentController extends Controller
{
    private PhotoRepositoryInterface $photoRepo;

    public function __construct(PhotoRepositoryInterface $photoRepo)
    {
        $this->photoRepo = $photoRepo;
    }

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

        $path = $request->file('file')->store('documents', 'public');

        $photo = $this->photoRepo->storeDocument($user->id, $request->type, $path);

        // ── Revocation logic ──────────────────────────────────────────────────
        //
        // Identity documents (face_id, back_id): the admin approved this account
        // by matching the submitted ID photo to the person's name and face.
        // Replacing either ID document means that check is no longer valid for
        // BOTH passenger and driver verification.
        //
        // Driver documents (license, mechanic_card): these only relate to the
        // driver review. Replacing them does not invalidate passenger verification
        // because the person is still the same individual.

        $isIdentityDoc = in_array($request->type, ['face_id', 'back_id']);
        $isDriverDoc = in_array($request->type, ['license', 'mechanic_card']);

        $updates = ['verification_status' => 'none'];

        if ($isIdentityDoc) {
            // Identity changed → both verifications are invalid
            $updates['is_verified_passenger'] = false;
            $updates['is_verified_driver'] = false;
        } elseif ($isDriverDoc) {
            // Driver docs changed → only driver verification is invalid
            $updates['is_verified_driver'] = false;

            // If the user is a verified passenger, their passenger status is
            // still valid — don't touch verification_status in that case so the
            // admin panel doesn't lose track of their passenger approval.
            if ($user->is_verified_passenger) {
                unset($updates['verification_status']);
            }
        }

        $user->update($updates);

        // ── Cache busting ─────────────────────────────────────────────────────
        // The verification status endpoint caches document URLs and verified
        // flags. Bust it so the next call reflects the new document and the
        // updated verification flags.
        Cache::forget("verification.status.{$user->id}");

        // Admin-facing caches embed verification status and document lists.
        Cache::forget("admin.driver.profile.{$user->id}");
        Cache::forget("admin.passenger.full-profile.{$user->id}");
        Cache::forget("admin.passenger.stats.{$user->id}");
        Cache::forget('staff.pending-verifications');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $photo->id,
                'url' => asset("storage/{$path}"),
                'type' => $photo->type,
            ],
        ]);
    }
}
