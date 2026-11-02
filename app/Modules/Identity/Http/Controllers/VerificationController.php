<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use App\Modules\Identity\Contracts\ProfileRepositoryInterface;
use App\Modules\Identity\Services\VerificationSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VerificationController extends Controller
{
    private PhotoRepositoryInterface $photoRepo;

    private ProfileRepositoryInterface $profileRepo;

    public function __construct(
        PhotoRepositoryInterface $photoRepo,
        ProfileRepositoryInterface $profileRepo,
        private readonly VerificationSubmissionService $submissions,
    ) {
        $this->photoRepo = $photoRepo;
        $this->profileRepo = $profileRepo;
    }

    /* ------------------------------------------
     * Passenger verification
     * POST /api/profile/verify/passenger
     * ------------------------------------------ */
    public function verifyPassenger(Request $request)
    {
        $result = $this->submissions->submitPassenger(
            $request->user()->id,
            $request->all(),
            $this->uploadedDocuments($request, ['face_id_pic', 'back_id_pic'])
        );

        return response()->json($result['body'], $result['status']);
    }

    /* ------------------------------------------
     * Driver verification
     * POST /api/profile/verify/driver
     * ------------------------------------------ */
    public function verifyDriver(Request $request)
    {
        $result = $this->submissions->submitDriver(
            $request->user(),
            $request->all(),
            $this->uploadedDocuments($request, [
                'face_id_pic', 'back_id_pic', 'driving_license_pic', 'mechanic_card_pic', 'car_pic',
            ])
        );

        return response()->json($result['body'], $result['status']);
    }

    /* ------------------------------------------
     * Status check
     * GET /api/profile/verify/status/{userId}
     * ------------------------------------------ */
    public function status(int $userId, Request $request)
    {
        if ($refusal = $this->ownershipRefusal($userId, $request)) {
            return $refusal;
        }

        try {
            return response()->json($this->buildStatusPayload($userId));
        } catch (\Exception $e) {
            // RV-13: message to the log, not the client - a QueryException here would carry the SQL
            // and the table names of the verification tables.
            Log::error('Verification: status retrieval failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve verification status. Please try again.',
            ], 500);
        }
    }

    /**
     * Owner-only access.
     *
     * RV-01: this endpoint returns another user's KYC document URLs and vehicle data and had
     * NO ownership check, so any authenticated caller could read any user's ID scans.
     * Owner-only here; staff review runs through the admin/staff verification surface, which
     * is authenticated separately.
     *
     * Unknown user id => 404 (R2 acceptance item: this used to be a 500 from findOrFail, and
     * a test pinned that); known user, not the caller => 403.
     */
    private function ownershipRefusal(int $userId, Request $request): ?JsonResponse
    {
        if (! $this->submissions->userExists($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        if ($request->user()->id !== $userId) {
            return response()->json([
                'success' => false,
                'message' => 'You can only view your own verification status',
            ], 403);
        }

        return null;
    }

    /** The cached verification status payload (120 seconds). */
    private function buildStatusPayload(int $userId): array
    {
        return Cache::remember("verification.status.{$userId}", 120, function () use ($userId) {
            $flags = $this->submissions->statusFlags($userId);

            $statusLabels = [
                'none' => 'not_verified',
                'pending' => 'pending',
                'rejected' => 'rejected',
                'approved' => 'approved',
            ];

            $documents = $this->photoRepo
                ->getUserDocumentsByType($userId, ['face_id', 'back_id', 'license', 'mechanic_card'])
                ->mapWithKeys(fn ($doc) => [$doc->type => asset("storage/{$doc->path}")])
                ->toArray();

            $profile = $this->profileRepo->getProfileByUserId($userId);

            return [
                'success' => true,
                'status' => $statusLabels[$flags['verification_status']] ?? 'unknown',
                'documents' => $documents,
                'vehicle' => $profile ? [
                    'type' => $profile->type_of_car,
                    'color' => $profile->color_of_car,
                    'seats' => $profile->number_of_seats,
                    'photo' => $profile->car_pic ? asset("storage/{$profile->car_pic}") : null,
                ] : null,
                'verified' => [
                    'passenger' => $flags['is_verified_passenger'],
                    'driver' => $flags['is_verified_driver'],
                ],
            ];
        });
    }

    /**
     * The uploaded files named by the caller, keyed by their input name.
     *
     * The service never sees the Request; the HTTP layer hands it only the files that
     * were actually uploaded.
     *
     * @param  array<int, string>  $inputNames
     * @return array<string, UploadedFile>
     */
    private function uploadedDocuments(Request $request, array $inputNames): array
    {
        $uploads = [];

        foreach ($inputNames as $inputName) {
            if ($request->hasFile($inputName)) {
                $uploads[$inputName] = $request->file($inputName);
            }
        }

        return $uploads;
    }
}
