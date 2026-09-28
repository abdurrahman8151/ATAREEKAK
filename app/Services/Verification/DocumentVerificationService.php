<?php

namespace App\Services\Verification;

use App\Models\Photo;
use App\Models\User;

/**
 * Document Verification Service
 *
 * EXTRACTED: Document validation logic from RideValidationService
 *
 * Responsibilities:
 * - Validate driver documents
 * - Validate passenger documents
 * - Check document completeness
 *
 * Single Responsibility: Document verification only
 */
final class DocumentVerificationService
{
    private const REQUIRED_DRIVER_DOCUMENTS = [
        'face_id',
        'back_id',
        'license',
        'mechanic_card',
    ];

    private const REQUIRED_PASSENGER_DOCUMENTS = [
        'face_id',
        'back_id',
    ];

    private const DOCUMENT_LABELS = [
        'face_id' => 'Face ID Photo',
        'back_id' => 'Back ID Photo',
        'license' => 'Driving License',
        'mechanic_card' => 'Mechanic Card',
    ];

    /**
     * Check if user has all required driver documents
     */
    public function hasRequiredDriverDocuments(User $user): bool
    {
        $missing = $this->getMissingDriverDocuments($user);

        return empty($missing);
    }

    /**
     * Check if user has all required passenger documents
     */
    public function hasRequiredPassengerDocuments(User $user): bool
    {
        $missing = $this->getMissingPassengerDocuments($user);

        return empty($missing);
    }

    /**
     * Get list of missing driver documents
     */
    public function getMissingDriverDocuments(User $user): array
    {
        $existingDocuments = $this->getUserDocumentTypes($user);

        return array_diff(self::REQUIRED_DRIVER_DOCUMENTS, $existingDocuments);
    }

    /**
     * Get list of missing passenger documents
     */
    public function getMissingPassengerDocuments(User $user): array
    {
        $existingDocuments = $this->getUserDocumentTypes($user);

        return array_diff(self::REQUIRED_PASSENGER_DOCUMENTS, $existingDocuments);
    }

    /**
     * Validate driver has all required documents
     *
     * @throws \Exception if documents are missing
     */
    public function validateDriverDocuments(User $user): void
    {
        $missing = $this->getMissingDriverDocuments($user);

        if (! empty($missing)) {
            $missingNames = $this->formatDocumentNames($missing);

            throw new \Exception(
                'Missing required driver verification documents: '.implode(', ', $missingNames)
            );
        }
    }

    /**
     * Validate passenger has all required documents
     *
     * @throws \Exception if documents are missing
     */
    public function validatePassengerDocuments(User $user): void
    {
        $missing = $this->getMissingPassengerDocuments($user);

        if (! empty($missing)) {
            $missingNames = $this->formatDocumentNames($missing);

            throw new \Exception(
                'Missing required passenger verification documents: '.implode(', ', $missingNames)
            );
        }
    }

    /**
     * Get all document types uploaded by user
     */
    private function getUserDocumentTypes(User $user): array
    {
        return Photo::where('user_id', $user->id)
            ->pluck('type')
            ->toArray();
    }

    /**
     * Format document type codes to human-readable names
     */
    private function formatDocumentNames(array $documentTypes): array
    {
        return array_map(
            fn ($type) => self::DOCUMENT_LABELS[$type] ?? $type,
            $documentTypes
        );
    }

    /**
     * Get verification progress percentage
     */
    public function getDriverVerificationProgress(User $user): int
    {
        $total = count(self::REQUIRED_DRIVER_DOCUMENTS);
        $uploaded = count($this->getUserDocumentTypes($user));
        $required = count(self::REQUIRED_DRIVER_DOCUMENTS);
        $existing = $required - count($this->getMissingDriverDocuments($user));

        return (int) round(($existing / $total) * 100);
    }

    /**
     * Get required document types for driver
     */
    public function getRequiredDriverDocumentTypes(): array
    {
        return self::REQUIRED_DRIVER_DOCUMENTS;
    }

    /**
     * Get required document types for passenger
     */
    public function getRequiredPassengerDocumentTypes(): array
    {
        return self::REQUIRED_PASSENGER_DOCUMENTS;
    }
}
