<?php

namespace Tests\Feature\Snapshots;

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
