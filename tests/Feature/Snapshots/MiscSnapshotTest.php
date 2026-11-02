<?php

namespace Tests\Feature\Snapshots;

use Illuminate\Http\UploadedFile;

/**
 * Batch G - score, complaints, support contact and the two public health doors.
 *
 * The score ledger reads, the complaint lifecycle (create, list, read), the support-chat door and
 * the unauthenticated /api/ping and /api/test probes. Complaints are created through the real
 * endpoint in `prepare`, so the read case sees a row the API itself produced.
 */
final class MiscSnapshotTest extends ApiSnapshotTestCase
{
    public static function cases(): array
    {
        return [
            'score/show' => [
                'route' => 'GET /api/score',
                'method' => 'GET',
                'uri' => '/api/score',
                'actor' => 'passenger',
                'expect' => 200,
            ],
            'score/history' => [
                'route' => 'GET /api/score/history',
                'method' => 'GET',
                'uri' => '/api/score/history',
                'actor' => 'passenger',
                'expect' => 200,
            ],
            'score/transactions' => [
                'route' => 'GET /api/score/transactions',
                'method' => 'GET',
                'uri' => '/api/score/transactions',
                'actor' => 'passenger',
                'expect' => 200,
            ],
            // Stage 0.6 item 2: the SAME route, but with the two reference shapes the parked 500
            // needed - a score row that points at a Ride and one that points at a Booking. The case
            // above cannot catch it: none of the world's score rows carry a reference, so the
            // resource's reference formatters never ran and the endpoint looked healthy.
            'score/transactions-with-ride-and-booking-references' => [
                'route' => 'GET /api/score/transactions',
                'method' => 'GET',
                'uri' => '/api/score/transactions',
                'actor' => 'passenger',
                'with_booking' => true,
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->seedScoreReferenceTransactions(),
                // The rows behind the response: the score ledger and the score counters it moved.
                // A response that says "+10" over a row that was never written is exactly what a
                // body snapshot cannot see.
                'state' => 'score',
                'expect' => 200,
            ],
            'complaints/index' => [
                'route' => 'GET /api/complaints',
                'method' => 'GET',
                'uri' => '/api/complaints',
                'actor' => 'passenger',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->createComplaint(),
                'expect' => 200,
            ],
            'complaints/store' => [
                'route' => 'POST /api/complaints',
                'method' => 'POST',
                'uri' => '/api/complaints',
                'actor' => 'passenger',
                'body' => [
                    'title' => 'Snapshot complaint',
                    'description' => 'A deterministic complaint created by the snapshot batch.',
                    'type' => 'technical_issue',
                ],
                // The store door accepts attachments and every other complaint case uploads one, so
                // this case does too. Without it the response pinned `attachments: []` while the
                // index and the show door returned a populated list for the same kind of row.
                'files' => [
                    'attachments' => [UploadedFile::fake()->create('snapshot-attachment.pdf', 12, 'application/pdf')],
                ],
                'expect' => 201,
            ],
            'complaints/show' => [
                'route' => 'GET /api/complaints/{id}',
                'method' => 'GET',
                'uri' => '/api/complaints/{complaint}',
                'actor' => 'passenger',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->createComplaint(),
                'expect' => 200,
            ],
            'contact/store' => [
                'route' => 'POST /api/contact',
                'method' => 'POST',
                'uri' => '/api/contact',
                'actor' => 'passenger',
                // The door routes the caller to the least-loaded support agent; the standard world's
                // agent must exist or the route answers 503 instead of opening the conversation.
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->staffEmployee(),
                'expect' => 201,
            ],
            'misc/ping' => [
                'route' => 'GET /api/ping',
                'method' => 'GET',
                'uri' => '/api/ping',
                'actor' => null,
                'expect' => 200,
            ],
            'misc/test' => [
                'route' => 'GET /api/test',
                'method' => 'GET',
                'uri' => '/api/test',
                'actor' => null,
                'expect' => 200,
            ],
        ];
    }
}
