<?php

namespace Tests\Feature\Snapshots;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Batch D - profile, identity verification and session.
 *
 * The profile view/update surface, the rating and comment doors, the KYC document upload, the
 * two verification submissions, the shared /api/user session read and logout.
 *
 * Two of these write FILES, so the documents disk is faked per case: a snapshot test must never
 * leave a document behind in storage.
 */
final class ProfileSnapshotTest extends ApiSnapshotTestCase
{
    public static function cases(): array
    {
        return [
            'profile/show' => [
                'route' => 'GET /api/profile/{userId}',
                'method' => 'GET',
                'uri' => '/api/profile/{other}',
                'actor' => 'passenger',
                'expect' => 200,
            ],
            'profile/update' => [
                'route' => 'POST /api/profile',
                'method' => 'POST',
                'uri' => '/api/profile',
                'actor' => 'passenger',
                'body' => [
                    'first_name' => 'Snapshot',
                    'last_name' => 'Passenger',
                    'description' => 'Snapshot profile',
                    'address' => 'دمشق',
                    'gender' => 'M',
                ],
                'expect' => 200,
            ],
            'profile/comment' => [
                'route' => 'POST /api/profile/{userId}/comments',
                'method' => 'POST',
                'uri' => '/api/profile/{driver}/comments',
                'actor' => 'passenger',
                'with_booking' => true,
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    $test->completeStandardRide();
                },
                'body' => [
                    'comment' => 'A calm and punctual driver.',
                    'ride_id' => '{ride}',
                ],
                'expect' => 201,
            ],
            'profile/rate' => [
                'route' => 'POST /api/profile/{userId}/rate',
                'method' => 'POST',
                'uri' => '/api/profile/{driver}/rate',
                'actor' => 'passenger',
                'with_booking' => true,
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    $test->completeStandardRide();
                },
                'body' => [
                    'rating' => 5,
                    'ride_id' => '{ride}',
                ],
                'expect' => 200,
            ],
            'profile/documents-upload' => [
                'route' => 'POST /api/profile/documents',
                'method' => 'POST',
                'uri' => '/api/profile/documents',
                'actor' => 'driver',
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    Storage::fake('snapshot-documents');
                    config(['filesystems.documents_disk' => 'snapshot-documents']);
                },
                'files' => [
                    'file' => UploadedFile::fake()->image('face-id.jpg', 64, 64),
                ],
                'body' => ['type' => 'face_id'],
                'expect' => 200,
            ],
            'profile/verify-passenger' => [
                'route' => 'POST /api/profile/verify/passenger',
                'method' => 'POST',
                'uri' => '/api/profile/verify/passenger',
                'actor' => 'other',
                'body' => [],
                'expect' => 201,
            ],
            'profile/verify-driver' => [
                'route' => 'POST /api/profile/verify/driver',
                'method' => 'POST',
                'uri' => '/api/profile/verify/driver',
                'actor' => 'driver',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->seedDriverDocuments(),
                'body' => [
                    'type_of_car' => 'Sedan',
                    'color_of_car' => 'White',
                    'number_of_seats' => 4,
                ],
                'expect' => 201,
            ],
            'profile/verify-status' => [
                'route' => 'GET /api/profile/verify/status/{userId}',
                'method' => 'GET',
                'uri' => '/api/profile/verify/status/{passenger}',
                'actor' => 'passenger',
                'expect' => 200,
            ],
            'session/user' => [
                'route' => 'GET /api/user',
                'method' => 'GET',
                'uri' => '/api/user',
                'actor' => 'passenger',
                'expect' => 200,
            ],
            'session/logout' => [
                'route' => 'POST /api/logout',
                'method' => 'POST',
                'uri' => '/api/logout',
                'actor' => 'passenger',
                'expect' => 200,
            ],
        ];
    }
}
