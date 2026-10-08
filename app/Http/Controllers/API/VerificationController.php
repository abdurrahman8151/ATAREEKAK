<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\PhotoRepositoryInterface;
use App\Interfaces\ProfileRepositoryInterface;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class VerificationController extends Controller
{
    private PhotoRepositoryInterface $photoRepo;

    private ProfileRepositoryInterface $profileRepo;

    private NotificationService $notificationService;

    public function __construct(
        PhotoRepositoryInterface $photoRepo,
        ProfileRepositoryInterface $profileRepo,
        NotificationService $notificationService,
    ) {
        $this->photoRepo = $photoRepo;
        $this->profileRepo = $profileRepo;
        $this->notificationService = $notificationService;
    }

    /* ------------------------------------------
     * Passenger verification
     * POST /api/profile/verify/passenger
     * ------------------------------------------ */
    public function verifyPassenger(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);

            if ($user->verification_status === 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a pending verification request',
                ], 409);
            }

            $validator = Validator::make($request->all(), [
                'face_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'back_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $profile = $this->profileRepo->getProfileByUserId($user->id)
                ?: $this->profileRepo->updateProfile($user->id, []);

            $map = [
                'face_id_pic' => 'face_id',
                'back_id_pic' => 'back_id',
            ];

            $profileData = [];
            foreach ($map as $inputName => $enumType) {
                if ($request->hasFile($inputName)) {
                    // RV-01: the stored name used the CLIENT-supplied extension
                    // and {userId}_{time()} (both guessable). guessExtension() is
                    // derived from the file's actual content, and a UUID removes
                    // the guessable prefix.
                    $upload = $request->file($inputName);
                    $filename = Str::uuid().'.'.$upload->guessExtension();
                    // AF-5: these are identity documents, so they share the `documents_disk` key that
                    // `DocumentController` writes to and `StaffDocumentController` reads from. RV-01:
                    // that key now defaults to the PRIVATE `local` disk, and the fallback here is
                    // 'local' to match so a missing config key cannot re-open the public exposure.
                    $path = $upload->storeAs("verifications/{$enumType}", $filename, config('filesystems.documents_disk', 'local'));

                    $this->photoRepo->deleteDocumentsByType($user->id, $enumType);
                    $this->photoRepo->storeDocument($user->id, $enumType, $path);
                    $profileData[$inputName] = $path;
                }
            }

            if (! empty($profileData)) {
                $this->profileRepo->updateProfile($user->id, $profileData);
            }

            $user->update([
                'verification_status' => 'pending',
                'is_verified_driver' => false,
                'is_verified_passenger' => false,
            ]);

            try {
                $this->notificationService->createNotification(
                    $user,
                    'verification_submitted',
                    'تم استلام طلب التحقق',
                    'تم استلام مستنداتك وستتم مراجعتها من قِبل الفريق قريباً.',
                    [],
                    'normal',
                    'system'
                );
            } catch (\Throwable $e) {
                Log::warning('verification notification failed (non-fatal): '.$e->getMessage());
            }

            Cache::forget("verification.status.{$user->id}");

            return response()->json([
                'success' => true,
                'message' => 'Verification request submitted',
                'status' => $user->fresh()->verification_status,
            ], 201);
        });
    }

    /* ------------------------------------------
     * Driver verification
     * POST /api/profile/verify/driver
     * ------------------------------------------ */
    public function verifyDriver(Request $request)
    {
        $user = $request->user();

        if ($user->verification_status === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending verification request',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'face_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'back_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'driving_license_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'mechanic_card_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'car_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'type_of_car' => 'nullable|string|max:255',
            'color_of_car' => 'nullable|string|max:50',
            'number_of_seats' => 'nullable|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        return DB::transaction(function () use ($request, $user) {
            $userId = $user->id;
            $profile = $this->profileRepo->getProfileByUserId($userId)
                ?: $this->profileRepo->updateProfile($userId, []);

            $map = [
                'face_id_pic' => 'face_id',
                'back_id_pic' => 'back_id',
                'driving_license_pic' => 'license',
                'mechanic_card_pic' => 'mechanic_card',
                'car_pic' => 'car_pic',
            ];

            $profileData = [];
            foreach ($map as $inputName => $folder) {
                if ($request->hasFile($inputName)) {
                    // RV-01: content-derived extension + UUID name (see the
                    // passenger branch above for the rationale).
                    $upload = $request->file($inputName);
                    $filename = Str::uuid().'.'.$upload->guessExtension();
                    $path = $upload->storeAs("verifications/{$folder}", $filename, config('filesystems.documents_disk', 'local'));

                    if (in_array($inputName, ['face_id_pic', 'back_id_pic', 'driving_license_pic', 'mechanic_card_pic'], true)) {
                        $this->photoRepo->deleteDocumentsByType($userId, $map[$inputName]);
                        $this->photoRepo->storeDocument($userId, $map[$inputName], $path);
                    }

                    $profileData[$inputName] = $path;
                }
            }

            $vehicleData = [];
            foreach (['type_of_car', 'color_of_car', 'number_of_seats'] as $key) {
                if ($request->filled($key)) {
                    $vehicleData[$key] = $request->input($key);
                }
            }

            $merged = array_merge($profileData, $vehicleData);
            if (! empty($merged)) {
                $this->profileRepo->updateProfile($user->id, $merged);
            }

            $user->update([
                'verification_status' => 'pending',
                'is_verified_driver' => false,
                'is_verified_passenger' => false,
            ]);

            try {
                $this->notificationService->createNotification(
                    $user,
                    'verification_submitted',
                    'تم استلام طلب التحقق',
                    'تم استلام مستنداتك وستتم مراجعتها من قِبل الفريق قريباً.',
                    [],
                    'normal',
                    'system'
                );
            } catch (\Throwable $e) {
                Log::warning('verification notification failed (non-fatal): '.$e->getMessage());
            }

            Cache::forget("verification.status.{$user->id}");

            return response()->json([
                'success' => true,
                'message' => 'Driver verification request submitted for review',
            ], 201);
        });
    }

    /* ------------------------------------------
     * Status check
     * GET /api/profile/verify/status/{userId}
     * ------------------------------------------ */
    public function status(int $userId, Request $request)
    {
        // RV-01: this endpoint returns another user's KYC document URLs and
        // vehicle data and had NO ownership check, so any authenticated caller
        // could read any user's ID scans. Owner-only here; staff review runs
        // through the admin/staff verification surface, which is authenticated
        // separately.
        // Unknown user id => 404 (R2 acceptance item: this used to be a 500
        // from findOrFail, and a test pinned that); known user, not the caller
        // => 403.
        if (! User::where('id', $userId)->exists()) {
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

        try {
            $data = Cache::remember("verification.status.{$userId}", 120, function () use ($userId) {
                $user = User::findOrFail($userId);

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
                    'status' => $statusLabels[$user->verification_status] ?? 'unknown',
                    'documents' => $documents,
                    'vehicle' => $profile ? [
                        'type' => $profile->type_of_car,
                        'color' => $profile->color_of_car,
                        'seats' => $profile->number_of_seats,
                        'photo' => $profile->car_pic ? asset("storage/{$profile->car_pic}") : null,
                    ] : null,
                    'verified' => [
                        'passenger' => (bool) $user->is_verified_passenger,
                        'driver' => (bool) $user->is_verified_driver,
                    ],
                ];
            });

            return response()->json($data);
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
}
