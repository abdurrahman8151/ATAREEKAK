<?php

namespace App\Http\Controllers\API\Staff;

use App\Http\Controllers\Controller;
use App\Interfaces\PhotoRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Decision 1b (owner, 2026-10-02): KYC identity documents leave the public disk.
 *
 * **The IDOR this closes.** Every document was uploaded to the `public` disk
 * (`DocumentController::store` -> `$file->store('documents', 'public')`) and every staff surface
 * rendered it as `asset('storage/'.$p->path)`. That is a PUBLIC URL to a face ID photo, a back ID
 * photo, a driving licence and a mechanic card: anyone holding the URL (it is handed out in API
 * responses; storage keys are guessable too) can download another person's identity documents with
 * no authentication at all. This route serves them ONLY to an authenticated staff member, behind
 * the existing `staff` middleware (StaffJwtMiddleware) - the same gate every other staff read uses.
 *
 * The bytes are read and returned through a normal `Response` rather than Symfony's
 * `response()->file()`/`BinaryFileResponse`, deliberately: the `X-Cache-Status` middleware calls
 * `$response->header()`, which does not exist on a BinaryFileResponse and turned this endpoint into
 * a 500. A plain Response keeps the middleware contract intact.
 *
 * **What deliberately did NOT change.** The files still physically live on the public disk, so an
 * old, already-shared URL keeps resolving. This route makes NEW consumption authenticated; it does
 * not revoke old links. That is a storage-move + object-ACL task, recorded as the open follow-up in
 * R2 section 46 — claiming the leak is fully "closed" here would be wrong.
 */
class StaffDocumentController extends Controller
{
    public function __construct(private readonly PhotoRepositoryInterface $photos) {}

    /** The document types that are identity documents (face/back ID). */
    private const IDENTITY_TYPES = ['face_id', 'back_id'];

    /** All KYC document types this endpoint will serve. */
    private const DOCUMENT_TYPES = ['face_id', 'back_id', 'license', 'mechanic_card'];

    /**
     * GET /api/staff/documents/{photoId}
     *
     * Serves one KYC document to an authenticated staff member.
     */
    public function show(Request $request, int $photoId): JsonResponse|Response
    {
        return $this->serve($photoId, self::DOCUMENT_TYPES);
    }

    /**
     * GET /api/staff/documents/{photoId}/identity
     *
     * Narrower alias: identity documents only (face/back ID), for the verification queue where the
     * driver's licence is not needed. A licence is still a KYC document (the general route serves it)
     * but not an identity one — this alias 404s it.
     */
    public function identity(Request $request, int $photoId): JsonResponse|Response
    {
        return $this->serve($photoId, self::IDENTITY_TYPES);
    }

    /**
     * @param  list<string>  $allowedTypes
     */
    private function serve(int $photoId, array $allowedTypes): JsonResponse|Response
    {
        $photo = $this->photos->findById($photoId);

        // 404 (not 403) for a missing row OR a type this route will not serve: an error must not
        // let a caller map which photo ids exist or what kind of document each one is.
        if (! $photo || ! in_array($photo->type, $allowedTypes, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Document not found.',
            ], 404);
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($photo->path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Document not found.',
            ], 404);
        }

        $bytes = $disk->get($photo->path);

        return response($bytes, 200, [
            'Content-Type' => $this->mimeFor($photo->path),
            // Serve as an attachment-ish inline view but never cacheable, and never sniffable into
            // something executable: an uploaded "document" is untrusted input.
            'Content-Disposition' => 'inline; filename="'.basename($photo->path).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Only the two document formats the upload endpoint accepts; never trust the extension alone. */
    private function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            default => 'image/jpeg',
        };
    }
}
