<?php

namespace Tests\Feature\Snapshots;

/**
 * Batch I - the staff panel.
 *
 * Operations reads (bookings, trips, users), the support inbox and its reply door, the complaint
 * desk (list, open, escalate, respond), the escalated queue, the review moderation queue, the
 * verification queue and the session doors.
 *
 * Two routes are deliberately NOT snapshotted: `GET /api/staff/documents/{photoId}` and
 * `.../identity` return raw file bytes rather than JSON, so there is no body to snapshot - see
 * RouteInventoryTest for the parked list.
 *
 * `GET /api/staff/chat/conversations/{id}/messages` was PARKED in stage 0 because it answered 500:
 * `StaffChatController::messages()` formats the conversation through `formatConversation()`, which
 * reads `$conversation->latestMessage`, and `ChatRepository::findConversation()` did not eager-load
 * that relation, so the lazy-loading guard threw. Stage 0.5 step 2 applied the one-line eager-load
 * (the only app change in this goal), and the route is snapshotted here.
 */
final class StaffSnapshotTest extends ApiSnapshotTestCase
{
    public static function cases(): array
    {
        return [
            'staff/bookings' => [
                'route' => 'GET /api/staff/bookings',
                'method' => 'GET',
                'uri' => '/api/staff/bookings',
                'actor' => 'staff',
                'with_booking' => true,
                'expect' => 200,
            ],
            'staff/bookings-cancel' => [
                'route' => 'POST /api/staff/bookings/{bookingId}/cancel',
                'method' => 'POST',
                'uri' => '/api/staff/bookings/{booking}/cancel',
                'actor' => 'staff_admin',
                'with_booking' => true,
                'body' => ['reason' => 'The snapshot batch cancelled this booking.'],
                'expect' => 200,
            ],
            'staff/chat-conversations' => [
                'route' => 'GET /api/staff/chat/conversations',
                'method' => 'GET',
                'uri' => '/api/staff/chat/conversations',
                'actor' => 'staff',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->openSupportChat(),
                'expect' => 200,
            ],
            'staff/chat-messages' => [
                'route' => 'GET /api/staff/chat/conversations/{id}/messages',
                'method' => 'GET',
                'uri' => '/api/staff/chat/conversations/{conversation}/messages',
                'actor' => 'staff',
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    $test->openSupportChat();
                    $test->sendSupportMessage();
                },
                'expect' => 200,
            ],
            'staff/chat-send' => [
                'route' => 'POST /api/staff/chat/conversations/{id}/messages',
                'method' => 'POST',
                'uri' => '/api/staff/chat/conversations/{conversation}/messages',
                'actor' => 'staff',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->openSupportChat(),
                'body' => ['message' => 'A support reply from the snapshot batch.'],
                'expect' => 201,
            ],
            'staff/complaints' => [
                'route' => 'GET /api/staff/complaints',
                'method' => 'GET',
                'uri' => '/api/staff/complaints',
                'actor' => 'staff',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->createComplaint(),
                'expect' => 200,
            ],
            'staff/complaints-show' => [
                'route' => 'GET /api/staff/complaints/{id}',
                'method' => 'GET',
                'uri' => '/api/staff/complaints/{complaint}',
                'actor' => 'staff',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->createComplaint(),
                'expect' => 200,
            ],
            'staff/complaints-escalate' => [
                'route' => 'PATCH /api/staff/complaints/{id}/escalate',
                'method' => 'PATCH',
                'uri' => '/api/staff/complaints/{complaint}/escalate',
                'actor' => 'staff',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->createComplaint(),
                'body' => ['reason' => 'The snapshot batch escalates this complaint for the queue test.'],
                'expect' => 200,
            ],
            'staff/complaints-respond' => [
                'route' => 'PATCH /api/staff/complaints/{id}/respond',
                'method' => 'PATCH',
                'uri' => '/api/staff/complaints/{complaint}/respond',
                'actor' => 'staff',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->createComplaint(),
                'body' => [
                    'resolution_notes' => 'The snapshot batch resolved this complaint.',
                    'status' => 'resolved',
                ],
                'expect' => 200,
            ],
            'staff/escalated-complaints' => [
                'route' => 'GET /api/staff/escalated-complaints',
                'method' => 'GET',
                'uri' => '/api/staff/escalated-complaints',
                'actor' => 'staff_admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->escalateComplaint(),
                'expect' => 200,
            ],
            'staff/escalated-resolve' => [
                'route' => 'PATCH /api/staff/escalated-complaints/{id}/resolve',
                'method' => 'PATCH',
                'uri' => '/api/staff/escalated-complaints/{complaint}/resolve',
                'actor' => 'staff_admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->escalateComplaint(),
                'body' => [
                    'resolution_notes' => 'The snapshot batch resolved the escalated complaint.',
                    'status' => 'resolved',
                ],
                'expect' => 200,
            ],
            'staff/logout' => [
                'route' => 'POST /api/staff/logout',
                'method' => 'POST',
                'uri' => '/api/staff/logout',
                'actor' => 'staff',
                'expect' => 200,
            ],
            'staff/me' => [
                'route' => 'GET /api/staff/me',
                'method' => 'GET',
                'uri' => '/api/staff/me',
                'actor' => 'staff',
                'expect' => 200,
            ],
            'staff/reviews' => [
                'route' => 'GET /api/staff/reviews',
                'method' => 'GET',
                'uri' => '/api/staff/reviews',
                'actor' => 'staff',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->createProfileComment(),
                'expect' => 200,
            ],
            'staff/reviews-destroy' => [
                'route' => 'DELETE /api/staff/reviews/{commentId}',
                'method' => 'DELETE',
                'uri' => '/api/staff/reviews/{comment}',
                'actor' => 'staff',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->createProfileComment(),
                'expect' => 200,
            ],
            'staff/trips' => [
                'route' => 'GET /api/staff/trips',
                'method' => 'GET',
                'uri' => '/api/staff/trips',
                'actor' => 'staff',
                'expect' => 200,
            ],
            'staff/trips-cancel' => [
                'route' => 'POST /api/staff/trips/{rideId}/cancel',
                'method' => 'POST',
                'uri' => '/api/staff/trips/{ride}/cancel',
                'actor' => 'staff_admin',
                'body' => ['reason' => 'The snapshot batch cancelled this trip.'],
                'expect' => 200,
            ],
            'staff/users' => [
                'route' => 'GET /api/staff/users',
                'method' => 'GET',
                'uri' => '/api/staff/users',
                'actor' => 'staff',
                'expect' => 200,
            ],
            'staff/users-show' => [
                'route' => 'GET /api/staff/users/{userId}',
                'method' => 'GET',
                'uri' => '/api/staff/users/{passenger}',
                'actor' => 'staff',
                'expect' => 200,
            ],
            'staff/verifications-pending' => [
                'route' => 'GET /api/staff/verifications/pending',
                'method' => 'GET',
                'uri' => '/api/staff/verifications/pending',
                'actor' => 'staff_admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->markOtherPendingVerification(),
                'expect' => 200,
            ],
            'staff/verifications-approve' => [
                'route' => 'POST /api/staff/verifications/{userId}/approve',
                'method' => 'POST',
                'uri' => '/api/staff/verifications/{other}/approve',
                'actor' => 'staff_admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->markOtherPendingVerification(),
                'body' => ['national_id' => '01000000001'],
                'expect' => 200,
            ],
            'staff/verifications-reject' => [
                'route' => 'POST /api/staff/verifications/{userId}/reject',
                'method' => 'POST',
                'uri' => '/api/staff/verifications/{other}/reject',
                'actor' => 'staff_admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->markOtherPendingVerification(),
                'body' => ['reason' => 'The snapshot batch rejects this submission.'],
                'expect' => 200,
            ],
        ];
    }
}
