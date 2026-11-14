<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use App\Modules\Identity\Contracts\ProfileRepositoryInterface;
use App\Modules\Identity\Models\Photo;
use App\Support\Logging\SafeExceptionLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Identity-verification submissions (passenger and driver).
 *
 * The service takes the raw input array and the already-extracted uploaded files, never
 * the HTTP Request: the HTTP layer owns the route and the status codes, this class owns
 * the rules. RV-01 (content-derived file name, private disk) and RV-56 (auth-cache bust)
 * live here because they are part of the submission contract, not of the transport.
 */
final class VerificationSubmissionService
{
    private const PASSENGER_RULES = [
        'face_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'back_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
    ];

    private const DRIVER_RULES = [
        'face_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'back_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'driving_license_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'mechanic_card_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'car_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        'type_of_car' => 'nullable|string|max:255',
        'color_of_car' => 'nullable|string|max:50',
        'number_of_seats' => 'nullable|integer|min:1|max:12',
    ];

    /**
     * Row 11 (owner ruling: reject until every required document is attached). The
     * required set is the one ride-create already enforces (RV-53). A document counts if
     * it is on file already OR uploaded in this request.
     */
    private const DRIVER_REQUIRED = [
        'face_id' => 'face_id_pic',
        'back_id' => 'back_id_pic',
        'license' => 'driving_license_pic',
        'mechanic_card' => 'mechanic_card_pic',
    ];

    /** Where each driver input is stored, and the document type it is filed under. */
    private const DRIVER_DOCUMENT_MAP = [
        'face_id_pic' => 'face_id',
        'back_id_pic' => 'back_id',
        'driving_license_pic' => 'license',
        'mechanic_card_pic' => 'mechanic_card',
        'car_pic' => 'car_pic',
    ];

    /** Inputs that also have to be re-filed in the photo repository (car_pic has none). */
    private const DRIVER_PHOTO_INPUTS = [
        'face_id_pic', 'back_id_pic', 'driving_license_pic', 'mechanic_card_pic',
    ];

    public function __construct(
        private readonly PhotoRepositoryInterface $photoRepo,
        private readonly ProfileRepositoryInterface $profileRepo,
        private readonly NotificationPort $notificationService,
    ) {}

    /**
     * Submit passenger identity documents.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, UploadedFile>  $uploads
     * @return array{status: int, body: array<string, mixed>}
     */
    public function submitPassenger(int $userId, array $input, array $uploads): array
    {
        return DB::transaction(function () use ($userId, $input, $uploads) {
            $user = User::lockForUpdate()->findOrFail($userId);

            if ($refusal = $this->pendingRefusal($user)) {
                return $refusal;
            }

            if ($invalid = $this->validate($input, self::PASSENGER_RULES)) {
                return $invalid;
            }

            // Ensure a profile row exists before the document paths are written onto it.
            $this->profileRepo->getProfileByUserId($user->id)
                ?: $this->profileRepo->updateProfile($user->id, []);

            $profileData = $this->storeDocuments($user->id, $uploads, [
                'face_id_pic' => 'face_id',
                'back_id_pic' => 'back_id',
            ], ['face_id_pic', 'back_id_pic']);

            if (! empty($profileData)) {
                $this->profileRepo->updateProfile($user->id, $profileData);
            }

            $user->update([
                'verification_status' => 'pending',
                'is_verified_driver' => false,
                'is_verified_passenger' => false,
            ]);

            $this->notifySubmitted($user);

            Cache::forget("verification.status.{$user->id}");

            return ['status' => 201, 'body' => [
                'success' => true,
                'message' => 'Verification request submitted',
                'status' => $user->fresh()->verification_status,
            ]];
        });
    }

    /**
     * Submit driver identity documents plus the vehicle details.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, UploadedFile>  $uploads
     * @return array{status: int, body: array<string, mixed>}
     */
    public function submitDriver(User $user, array $input, array $uploads): array
    {
        if ($refusal = $this->pendingRefusal($user)) {
            return $refusal;
        }

        if ($invalid = $this->validate($input, self::DRIVER_RULES)) {
            return $invalid;
        }

        $missing = $this->missingRequiredDocuments($user->id, $uploads);
        if (! empty($missing)) {
            return ['status' => 422, 'body' => [
                'success' => false,
                'message' => 'Missing required driver verification documents: '.implode(', ', $missing),
                'missing' => $missing,
            ]];
        }

        return DB::transaction(function () use ($user, $input, $uploads) {
            $userId = $user->id;

            // Ensure a profile row exists before the document paths are written onto it.
            $this->profileRepo->getProfileByUserId($userId)
                ?: $this->profileRepo->updateProfile($userId, []);

            $profileData = $this->storeDocuments(
                $userId,
                $uploads,
                self::DRIVER_DOCUMENT_MAP,
                self::DRIVER_PHOTO_INPUTS
            );

            $merged = array_merge($profileData, $this->vehicleAttributes($input));
            if (! empty($merged)) {
                $this->profileRepo->updateProfile($userId, $merged);
            }

            $user->update([
                'verification_status' => 'pending',
                'is_verified_driver' => false,
                'is_verified_passenger' => false,
            ]);

            // RV-56: the 409 guard reads the user through the auth cache (findUserCached,
            // key auth.user.{id}, 5 min). Clear it so a re-submit sees 'pending' at once.
            Cache::forget("auth.user.{$user->id}");

            $this->notifySubmitted($user);

            Cache::forget("verification.status.{$user->id}");

            return ['status' => 201, 'body' => [
                'success' => true,
                'message' => 'Driver verification request submitted for review',
            ]];
        });
    }

    /** @return array{status: int, body: array<string, mixed>}|null */
    private function pendingRefusal(User $user): ?array
    {
        if ($user->verification_status !== 'pending') {
            return null;
        }

        return ['status' => 409, 'body' => [
            'success' => false,
            'message' => 'You already have a pending verification request',
        ]];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $rules
     * @return array{status: int, body: array<string, mixed>}|null
     */
    private function validate(array $input, array $rules): ?array
    {
        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            return ['status' => 422, 'body' => [
                'success' => false,
                'errors' => $validator->errors(),
            ]];
        }

        return null;
    }

    /**
     * Store every uploaded document and return the profile attributes to persist.
     *
     * RV-01: the stored name used the CLIENT-supplied extension and {userId}_{time()}
     * (both guessable). guessExtension() is derived from the file's actual content, and a
     * UUID removes the guessable prefix.
     *
     * AF-5: these are identity documents, so they share the `documents_disk` key that
     * DocumentController writes to and StaffDocumentController reads from. RV-01: that key
     * now defaults to the PRIVATE `local` disk, and the fallback here is 'local' to match
     * so a missing config key cannot re-open the public exposure.
     *
     * @param  array<string, UploadedFile>  $uploads
     * @param  array<string, string>  $folderMap  input name => storage folder / document type
     * @param  array<int, string>  $photoInputs  inputs that also refresh the photo repository
     * @return array<string, string>
     */
    private function storeDocuments(int $userId, array $uploads, array $folderMap, array $photoInputs): array
    {
        $profileData = [];

        foreach ($folderMap as $inputName => $folder) {
            if (! isset($uploads[$inputName])) {
                continue;
            }

            $upload = $uploads[$inputName];
            $filename = Str::uuid().'.'.$upload->guessExtension();
            $path = $upload->storeAs(
                "verifications/{$folder}",
                $filename,
                config('filesystems.documents_disk', 'local')
            );

            if (in_array($inputName, $photoInputs, true)) {
                $this->photoRepo->deleteDocumentsByType($userId, $folder);
                $this->photoRepo->storeDocument($userId, $folder, $path);
            }

            $profileData[$inputName] = $path;
        }

        return $profileData;
    }

    /**
     * Document types already on file for a user. Same source as the ride-create rule
     * (DocumentVerificationService::getUserDocumentTypes), so both agree on what
     * "on file" means.
     *
     * @param  array<string, UploadedFile>  $uploads
     * @return array<int, string>
     */
    private function missingRequiredDocuments(int $userId, array $uploads): array
    {
        $storedTypes = Photo::where('user_id', $userId)->pluck('type')->toArray();
        $missing = [];

        foreach (self::DRIVER_REQUIRED as $type => $inputName) {
            $uploadedNow = isset($uploads[$inputName]);
            $storedAlready = in_array($type, $storedTypes, true);

            if (! $uploadedNow && ! $storedAlready) {
                $missing[] = $type;
            }
        }

        return $missing;
    }

    /**
     * The vehicle attributes the client actually sent (Request::filled() semantics).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function vehicleAttributes(array $input): array
    {
        $vehicle = [];

        foreach (['type_of_car', 'color_of_car', 'number_of_seats'] as $key) {
            $value = $input[$key] ?? null;

            // Mirrors Request::filled(): a missing key and a blank string are not filled,
            // while an array or boolean value always is.
            if (is_bool($value) || is_array($value) || trim((string) $value) !== '') {
                $vehicle[$key] = $value;
            }
        }

        return $vehicle;
    }

    private function notifySubmitted(User $user): void
    {
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
            Log::warning('verification notification failed (non-fatal): '.SafeExceptionLog::message($e));
        }
    }
    // =========================================================================
    // PUBLIC — CONTROLLER LOOKUPS (batch 227)
    // =========================================================================

    /**
     * Does this user row exist? Used by the controller's 404 branch.
     *
     * Moved here from VerificationController so the controller holds no Eloquent reference.
     * Same query, same answer.
     */
    public function userExists(int $userId): bool
    {
        return User::whereKey($userId)->exists();
    }

    /**
     * The three account flags the verification status payload reports.
     *
     * Moved here from VerificationController. `findOrFail` is kept, so a missing row still
     * surfaces exactly as it did before.
     *
     * @return array{verification_status: string|null, is_verified_passenger: bool, is_verified_driver: bool}
     */
    public function statusFlags(int $userId): array
    {
        $user = User::findOrFail($userId);

        return [
            'verification_status' => $user->verification_status,
            'is_verified_passenger' => (bool) $user->is_verified_passenger,
            'is_verified_driver' => (bool) $user->is_verified_driver,
        ];
    }
}
