<?php

namespace App\Services\File;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * File Upload Service
 *
 * ELIMINATES FILE UPLOAD DUPLICATION across:
 * - ProfileController
 * - DocumentController
 * - VerificationController
 * - ChatController
 *
 * Single Responsibility: Handle file uploads with validation
 */
final class FileUploadService
{
    private const MAX_IMAGE_SIZE = 2048; // KB

    private const ALLOWED_IMAGE_MIMES = ['jpeg', 'png', 'jpg', 'gif', 'webp'];

    /**
     * Upload profile photo
     */
    public function uploadProfilePhoto(UploadedFile $file, int $userId): string
    {
        $this->validateImage($file);

        $filename = $userId.'_'.now()->timestamp.'.'.$file->getClientOriginalExtension();

        return $file->storeAs('profiles/profile_photo', $filename, 'public');
    }

    /**
     * Upload verification document
     */
    public function uploadVerificationDocument(
        UploadedFile $file,
        int $userId,
        string $documentType
    ): string {
        $this->validateImage($file);

        // RV-01: was `{userId}_{timestamp}.{clientExtension}` — a guessable name
        // carrying a client-controlled extension (an .html payload with GIF magic
        // passes image|mimes and is then served as HTML). Content-derived
        // extension + UUID name.
        $filename = Str::uuid().'.'.$file->guessExtension();
        $folder = "verifications/{$documentType}";

        return $file->storeAs($folder, $filename, 'public');
    }

    /**
     * Upload car photo
     */
    public function uploadCarPhoto(UploadedFile $file, int $userId): string
    {
        $this->validateImage($file);

        $filename = $userId.'_'.now()->timestamp.'.'.$file->getClientOriginalExtension();

        return $file->storeAs('verifications/car_pic', $filename, 'public');
    }

    /**
     * Upload chat image
     */
    public function uploadChatImage(UploadedFile $file, int $senderId, int $receiverId): array
    {
        $this->validateImage($file);

        $filename = "{$senderId}_{$receiverId}_".now()->timestamp.'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs('chat-images', $filename, 'public');

        return [
            'path' => $path,
            'url' => asset("storage/{$path}"),
            'metadata' => [
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ],
        ];
    }

    /**
     * Upload generic file to custom folder
     */
    public function upload(
        UploadedFile $file,
        string $folder,
        ?string $customFilename = null
    ): string {
        $this->validateImage($file);

        $filename = $customFilename ?? Str::random(40).'.'.$file->getClientOriginalExtension();

        return $file->storeAs($folder, $filename, 'public');
    }

    /**
     * Delete file
     */
    public function delete(string $path): bool
    {
        if (empty($path)) {
            return false;
        }

        return Storage::disk('public')->delete($path);
    }

    /**
     * Delete old file and upload new one (atomic replacement)
     */
    public function replace(UploadedFile $newFile, ?string $oldPath, string $folder): string
    {
        $newPath = $this->upload($newFile, $folder);

        if ($oldPath) {
            $this->delete($oldPath);
        }

        return $newPath;
    }

    /**
     * Check if file exists
     */
    public function exists(string $path): bool
    {
        return Storage::disk('public')->exists($path);
    }

    /**
     * Get file URL
     */
    public function url(string $path): string
    {
        return asset("storage/{$path}");
    }

    /**
     * Validate image file
     */
    private function validateImage(UploadedFile $file): void
    {
        // Check if it's an image
        if (! $file->isValid()) {
            throw new \InvalidArgumentException('Invalid file upload');
        }

        // Check file size
        if ($file->getSize() > self::MAX_IMAGE_SIZE * 1024) {
            throw new \InvalidArgumentException(
                'File size exceeds maximum allowed size of '.self::MAX_IMAGE_SIZE.'KB'
            );
        }

        // Check MIME type
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, self::ALLOWED_IMAGE_MIMES)) {
            throw new \InvalidArgumentException(
                'Invalid file type. Allowed types: '.implode(', ', self::ALLOWED_IMAGE_MIMES)
            );
        }

        // Check if it's actually an image (not just extension)
        $imageInfo = @getimagesize($file->getRealPath());
        if ($imageInfo === false) {
            throw new \InvalidArgumentException('File is not a valid image');
        }
    }

    /**
     * Batch upload multiple files
     */
    public function uploadMultiple(array $files, string $folder): array
    {
        $uploadedPaths = [];

        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFile) {
                $uploadedPaths[$key] = $this->upload($file, $folder);
            }
        }

        return $uploadedPaths;
    }

    /**
     * Get file size in human-readable format
     */
    public function getReadableSize(string $path): string
    {
        $bytes = Storage::disk('public')->size($path);

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
