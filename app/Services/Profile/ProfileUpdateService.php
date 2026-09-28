<?php

namespace App\Services\Profile;

use App\Interfaces\PhotoRepositoryInterface;
use App\Interfaces\ProfileRepositoryInterface;
use App\Models\User;
use App\Services\File\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Profile Update Service
 *
 * EXTRACTED FROM: ProfileController::update() (150+ lines)
 *
 * Single Responsibility: Handle profile updates with validation
 *
 * Handles:
 * - Profile data updates
 * - File uploads (profile photo, car pic, documents)
 * - Verification status management
 * - Critical field protection
 */
final class ProfileUpdateService
{
    private const CRITICAL_FIELDS = [
        'first_name',
        'last_name',
        'type_of_car',
        'color_of_car',
        'number_of_seats',
    ];

    private const VERIFICATION_DOCUMENTS = [
        'face_id_pic',
        'back_id_pic',
        'driving_license_pic',
        'mechanic_card_pic',
    ];

    private const NON_VERIFICATION_FILES = [
        'profile_photo',
        'car_pic',
    ];

    private const DOCUMENT_TYPE_MAP = [
        'face_id_pic' => 'face_id',
        'back_id_pic' => 'back_id',
        'driving_license_pic' => 'license',
        'mechanic_card_pic' => 'mechanic_card',
    ];

    public function __construct(
        private readonly ProfileRepositoryInterface $profileRepo,
        private readonly PhotoRepositoryInterface $photoRepo,
        private readonly FileUploadService $fileUploadService
    ) {}

    // Fields that live on the users table, not the profiles table.
    private const USER_MODEL_FIELDS = ['first_name', 'last_name', 'gender'];

    /**
     * Update user profile
     *
     * @throws \Exception if validation fails
     */
    public function updateProfile(User $user, array $data): array
    {
        // Validate critical fields if user has pending verification
        if ($user->verification_status === 'pending') {
            $this->validateCriticalFieldsNotChanged($data);
        }

        // ── Save User-model fields to the users table ─────────────────────
        // first_name, last_name, and gender live on User, not Profile.
        // Passing them to profileRepo would write to the wrong table.
        $userFields = array_filter(
            array_intersect_key($data, array_flip(self::USER_MODEL_FIELDS)),
            fn ($v) => $v !== null
        );
        if (! empty($userFields)) {
            $user->update($userFields);
        }

        // Strip User-model fields so profileRepo only receives Profile columns.
        $data = array_diff_key($data, array_flip(self::USER_MODEL_FIELDS));

        // Process file uploads
        $fileData = $this->processFileUploads($user, $data);

        // Merge file data with regular data
        $profileData = array_merge($data, $fileData['profile_data']);

        // Update profile
        $this->profileRepo->updateProfile($user->id, $profileData);

        // Reset verification if needed
        if ($fileData['verification_docs_updated']) {
            $this->resetVerificationStatus($user);
        }

        return [
            'profile' => $this->profileRepo->getProfileWithUser($user->id),
            'user' => $user->fresh(),
            'verification_reset' => $fileData['verification_docs_updated'],
        ];
    }

    /**
     * Validate critical fields aren't being changed during pending verification
     *
     * @throws \Exception if critical fields are being changed
     */
    private function validateCriticalFieldsNotChanged(array $data): void
    {
        $criticalBeingChanged = array_intersect(
            array_keys($data),
            self::CRITICAL_FIELDS
        );

        if (! empty($criticalBeingChanged)) {
            throw new \Exception(
                'Cannot change name or vehicle details while verification is pending'
            );
        }
    }

    /**
     * Process all file uploads
     *
     * @return array{profile_data: array, verification_docs_updated: bool}
     */
    private function processFileUploads(User $user, array $data): array
    {
        $profileData = [];
        $verificationDocsUpdated = false;

        foreach (array_merge(self::VERIFICATION_DOCUMENTS, self::NON_VERIFICATION_FILES) as $field) {
            if (! isset($data[$field]) || ! ($data[$field] instanceof UploadedFile)) {
                continue;
            }

            $uploadResult = $this->uploadFile($user, $field, $data[$field]);
            $profileData[$field] = $uploadResult['path'];

            // Track if verification documents were updated
            if (in_array($field, self::VERIFICATION_DOCUMENTS)) {
                $verificationDocsUpdated = true;

                // Update photos table for verification documents
                $type = self::DOCUMENT_TYPE_MAP[$field];
                $this->photoRepo->deleteDocumentsByType($user->id, $type);
                $this->photoRepo->storeDocument($user->id, $type, $uploadResult['path']);
            }
        }

        return [
            'profile_data' => $profileData,
            'verification_docs_updated' => $verificationDocsUpdated,
        ];
    }

    /**
     * Upload a single file
     */
    private function uploadFile(User $user, string $field, UploadedFile $file): array
    {
        return match ($field) {
            'profile_photo' => [
                'path' => $this->fileUploadService->uploadProfilePhoto($file, $user->id),
            ],
            'car_pic' => [
                'path' => $this->fileUploadService->uploadCarPhoto($file, $user->id),
            ],
            default => [
                'path' => $this->fileUploadService->uploadVerificationDocument(
                    $file,
                    $user->id,
                    self::DOCUMENT_TYPE_MAP[$field]
                ),
            ]
        };
    }

    /**
     * Reset user verification status
     */
    private function resetVerificationStatus(User $user): void
    {
        $user->update([
            'verification_status' => 'none',
            'is_verified_driver' => false,
            'is_verified_passenger' => false,
        ]);

        Log::info('Verification status reset due to document update', [
            'user_id' => $user->id,
        ]);
    }
}
