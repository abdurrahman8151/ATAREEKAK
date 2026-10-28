<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendPushNotification;
use App\Models\User;
use App\Services\PushNotification\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Unit tests for SendPushNotification.
 *
 * PushNotificationService (and its collaborators) are all `final`, so they
 * cannot be Mockery-doubled and still satisfy handle()'s type-hinted
 * parameter. These tests use the real service instead — it degrades safely
 * when FCM isn't configured (see FcmSenderServiceTest) — and use Log
 * expectations / Reflection where verification is needed.
 */
class SendPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_sends_notification_when_user_exists(): void
    {
        $user = User::factory()->create();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, "Push notification sent to user {$user->id}"));
        // PushNotificationService (and the FcmSenderService/PushTokenManager it
        // delegates to) may log their own info/warning/error lines internally —
        // e.g. "no active push tokens for this user" — before the job logs its
        // own success line. Tolerate those incidental calls the same way the
        // warning/error expectations already do, instead of letting the mocked
        // Log facade reject them as unexpected.
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $job = new SendPushNotification($user->id, ['title' => 'Ride confirmed', 'body' => 'Your ride has been confirmed.']);
        $job->handle(app(PushNotificationService::class));

        $this->assertTrue(true);
    }

    public function test_handle_does_nothing_when_user_does_not_exist(): void
    {
        $nonExistentUserId = 999999;

        // Resolve the service BEFORE arming the Log mock: building it writes its own info line
        // (the FCM sender degrades safely when it is not configured), and that line must not be
        // counted as something the job logged. Batch 229.
        $service = app(PushNotificationService::class);

        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('info')->never();
        Log::shouldReceive('error')->never();

        $job = new SendPushNotification($nonExistentUserId, ['title' => 'Hello']);

        $job->handle($service);

        $this->assertTrue(true);
    }

    public function test_handle_logs_and_rethrows_when_service_fails(): void
    {
        $user = User::factory()->create();

        // Same ordering rule as above: build the service first. Its own info line for a user with
        // no push tokens ("No active tokens found for user: N") is the first info call inside the
        // job's try block, so that is where the injected failure has to land. Batch 229.
        $service = app(PushNotificationService::class);

        Log::shouldReceive('warning')->zeroOrMoreTimes();

        Log::shouldReceive('info')
            ->once()
            ->andThrow(new \RuntimeException('FCM endpoint unreachable'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Failed to send push notification')
                && str_contains($message, 'FCM endpoint unreachable'));

        $job = new SendPushNotification($user->id, ['title' => 'Hello']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FCM endpoint unreachable');

        $job->handle($service);
    }

    public function test_constructor_stores_user_id_and_notification_data(): void
    {
        $user = User::factory()->create();
        $data = ['title' => 'Test', 'body' => 'Body'];

        $job = new SendPushNotification($user->id, $data);

        // Properties are protected — inspect directly instead of inferring
        // via a mocked collaborator (which isn't possible for this final class).
        $reflection = new \ReflectionClass($job);

        $userIdProperty = $reflection->getProperty('userId');
        $userIdProperty->setAccessible(true);
        $this->assertEquals($user->id, $userIdProperty->getValue($job));

        $dataProperty = $reflection->getProperty('notificationData');
        $dataProperty->setAccessible(true);
        $this->assertEquals($data, $dataProperty->getValue($job));
    }
}
