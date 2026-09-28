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
                    $ext = $request->file($inputName)->getClientOriginalExtension();
                    $filename = $user->id.'_'.time().'.'.$ext;
                    $path = $request->file($inputName)
                        ->storeAs("verifications/{$enumType}", $filename, 'public');

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
                    $ext = $request->file($inputName)->getClientOriginalExtension();
                    $filename = $userId.'_'.time().'.'.$ext;
                    $path = $request->file($inputName)
                        ->storeAs("verifications/{$folder}", $filename, 'public');

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
    public function status(int $userId)
    {
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
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve verification status: '.$e->getMessage(),
            ], 500);
        }
    }
}
