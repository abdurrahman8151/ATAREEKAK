<?php

namespace Tests\Feature\Snapshots;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Batch E - chat, notifications and push devices.
 *
 * The conversation list/create doors, the two message doors, and the notification inbox with its
 * read/unread/delete/bulk actions. The notification fixtures are inserted directly (there is no
 * public door that creates one for another user), which is also what makes the ids deterministic
 * within the case.
 */
final class EngagementSnapshotTest extends ApiSnapshotTestCase
{
    public static function cases(): array
    {
        return [
            'chat/conversations-index' => [
                'route' => 'GET /api/chat/conversations',
                'method' => 'GET',
                'uri' => '/api/chat/conversations',
                'actor' => 'passenger',
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    $test->startSnapshotConversation();
                },
                'expect' => 200,
            ],
            'chat/conversations-store' => [
                'route' => 'POST /api/chat/conversations',
                'method' => 'POST',
                'uri' => '/api/chat/conversations',
                'actor' => 'passenger',
                'body' => ['user_id' => '{other}'],
                'expect' => 201,
            ],
            'chat/messages-index' => [
                'route' => 'GET /api/chat/conversations/{conversationId}/messages',
                'method' => 'GET',
                'uri' => '/api/chat/conversations/{conversation}/messages',
                'actor' => 'passenger',
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    $test->startSnapshotConversation();
                },
                'expect' => 200,
            ],
            'chat/messages-store' => [
                'route' => 'POST /api/chat/conversations/{conversationId}/messages',
                'method' => 'POST',
                'uri' => '/api/chat/conversations/{conversation}/messages',
                'actor' => 'passenger',
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    $test->startSnapshotConversation();
                },
                'body' => ['content' => 'Hello from the snapshot batch.'],
                'expect' => 201,
            ],
            'chat/messages-destroy' => [
                'route' => 'DELETE /api/chat/messages/{messageId}',
                'method' => 'DELETE',
                'uri' => '/api/chat/messages/{message}',
                'actor' => 'passenger',
                'prepare' => static function (ApiSnapshotTestCase $test): void {
                    $test->startSnapshotConversation();
                    $test->sendSnapshotMessage();
                },
                'expect' => 200,
            ],
            'notifications/index' => [
                'route' => 'GET /api/notifications',
                'method' => 'GET',
                'uri' => '/api/notifications',
                'actor' => 'passenger',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->seedSnapshotNotification(),
                'expect' => 200,
            ],
            'notifications/unread-count' => [
                'route' => 'GET /api/notifications/unread-count',
                'method' => 'GET',
                'uri' => '/api/notifications/unread-count',
                'actor' => 'passenger',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->seedSnapshotNotification(),
                'expect' => 200,
            ],
            'notifications/categories' => [
                'route' => 'GET /api/notifications/categories',
                'method' => 'GET',
                'uri' => '/api/notifications/categories',
                'actor' => 'passenger',
                'expect' => 200,
            ],
            'notifications/read' => [
                'route' => 'POST /api/notifications/{id}/read',
                'method' => 'POST',
                'uri' => '/api/notifications/{notification}/read',
                'actor' => 'passenger',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->seedSnapshotNotification(),
                'expect' => 200,
            ],
            'notifications/unread' => [
                'route' => 'POST /api/notifications/{id}/unread',
                'method' => 'POST',
                'uri' => '/api/notifications/{notification}/unread',
                'actor' => 'passenger',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->seedSnapshotNotification(),
                'expect' => 200,
            ],
            'notifications/read-all' => [
                'route' => 'POST /api/notifications/read-all',
                'method' => 'POST',
                'uri' => '/api/notifications/read-all',
                'actor' => 'passenger',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->seedSnapshotNotification(),
                'expect' => 200,
            ],
            'notifications/bulk-action' => [
                'route' => 'POST /api/notifications/bulk-action',
                'method' => 'POST',
                'uri' => '/api/notifications/bulk-action',
                'actor' => 'passenger',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->seedSnapshotNotification(),
                'body' => [
                    'action' => 'mark_read',
                    'notification_ids' => ['{notification}'],
                ],
                'expect' => 200,
            ],
            'notifications/destroy' => [
                'route' => 'DELETE /api/notifications/{id}',
                'method' => 'DELETE',
                'uri' => '/api/notifications/{notification}',
                'actor' => 'passenger',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->seedSnapshotNotification(),
                'expect' => 200,
            ],
            'push-tokens/register' => [
                'route' => 'POST /api/push-tokens',
                'method' => 'POST',
                'uri' => '/api/push-tokens',
                'actor' => 'passenger',
                'body' => [
                    'token' => 'snapshot-device-token',
                    'platform' => 'android',
                ],
                'expect' => 200,
            ],
            'push-tokens/index' => [
                'route' => 'GET /api/push-tokens',
                'method' => 'GET',
                'uri' => '/api/push-tokens',
                'actor' => 'passenger',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->registerSnapshotPushToken(),
                'expect' => 200,
            ],
            'push-tokens/destroy' => [
                'route' => 'DELETE /api/push-tokens',
                'method' => 'DELETE',
                'uri' => '/api/push-tokens',
                'actor' => 'passenger',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->registerSnapshotPushToken(),
                'body' => ['token' => 'snapshot-device-token'],
                'expect' => 200,
            ],
        ];
    }

    /**
     * Start the standard passenger <-> other conversation and remember its id as {conversation}.
     */
    public function startSnapshotConversation(): void
    {
        $response = $this->withToken($this->passengerToken)->postJson('/api/chat/conversations', [
            'user_id' => $this->otherPassenger->id,
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail(
                'Snapshot world could not start a conversation (HTTP '.$response->getStatusCode().'): '
                .substr((string) $response->getContent(), 0, 400)
            );
        }

        $this->caseVars['{conversation}'] = (string) $response->json('data.conversation_id');

        // A conversation with no messages makes the messages index pin `[]`, which says nothing
        // about its query. Three messages, one of them an image row with real metadata (the text
        // handler always writes `metadata: []`, so a text-only conversation can never show the
        // shape), written directly because an upload's stored filename carries the two user ids.
        $this->sendSnapshotMessage('Hello, is the trip still leaving at nine?');
        $this->sendSnapshotMessage('Yes, I will be at the pickup point.');

        $image = $this->seedChatImage((int) $this->caseVars['{conversation}'], $this->otherPassenger->id);

        // The index answers newest first, so the image is stamped as the NEWEST of the three: the
        // populated `metadata` map belongs on `data.0`, and the two text rows pin the empty map the
        // text handler writes. Stamping all three explicitly keeps the order independent of the
        // second in which each row was inserted.
        $textIds = Message::where('conversation_id', (int) $this->caseVars['{conversation}'])
            ->where('type', 'text')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach ($textIds as $offset => $id) {
            Message::where('id', $id)->update(['created_at' => now()->subMinutes(3 - $offset)]);
        }
    }

    public function sendSnapshotMessage(?string $content = null): void
    {
        $response = $this->withToken($this->passengerToken)->postJson(
            '/api/chat/conversations/'.$this->caseVars['{conversation}'].'/messages',
            ['content' => $content ?? 'A message that will be deleted.']
        );

        if ($response->getStatusCode() !== 201) {
            $this->fail(
                'Snapshot world could not send a message (HTTP '.$response->getStatusCode().'): '
                .substr((string) $response->getContent(), 0, 400)
            );
        }

        $this->caseVars['{message}'] = (string) $response->json('data.id');
    }

    /**
     * One unread notification owned by the standard passenger.
     */
    public function seedSnapshotNotification(): int
    {
        $notificationId = (int) DB::table('notifications')->insertGetId([
            'title' => 'Snapshot notification',
            'message' => 'A deterministic notification for the snapshot batch.',
            'type' => 'general',
            'data' => json_encode(['ride_id' => 1]),
            'user_id' => $this->passenger->id,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userNotificationId = (int) DB::table('user_notifications')->insertGetId([
            'user_id' => $this->passenger->id,
            'notification_id' => $notificationId,
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->caseVars['{notification}'] = (string) $userNotificationId;

        return $userNotificationId;
    }

    public function registerSnapshotPushToken(): void
    {
        $response = $this->withToken($this->passengerToken)->postJson('/api/push-tokens', [
            'token' => 'snapshot-device-token',
            'platform' => 'android',
        ]);

        if ($response->getStatusCode() !== 200) {
            $this->fail(
                'Snapshot world could not register a push token (HTTP '.$response->getStatusCode().'): '
                .substr((string) $response->getContent(), 0, 400)
            );
        }
    }

    /** Kept so a future case can reach the driver's notification inbox without a new helper. */
    protected function driverNotificationCount(): int
    {
        return DB::table('user_notifications')->where('user_id', $this->driver->id)->count();
    }
}
