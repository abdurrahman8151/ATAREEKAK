<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendScheduledNotification;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\SerializesModels;
use Mockery;
use Tests\TestCase;

/**
 * SendScheduledNotificationTest — Unit tests for SendScheduledNotification.
 *
 * COVERS:
 *   __construct()  — stores notificationData payload
 *   handle()       — delegates to NotificationService::create(); rethrows exceptions
 *   Traits         — Queueable, SerializesModels, Dispatchable, ShouldQueue
 */
class SendScheduledNotificationTest extends TestCase
{
    use RefreshDatabase;

    private array $sampleData = [
        'title' => 'Scheduled Notification',
        'message' => 'This is a scheduled message.',
        'type' => 'general',
        'user_id' => 1,
    ];

    // ─── Instantiation ────────────────────────────────────────────────────────

    public function test_can_be_instantiated(): void
    {
        $this->assertInstanceOf(
            SendScheduledNotification::class,
            new SendScheduledNotification($this->sampleData)
        );
    }

    public function test_has_handle_method(): void
    {
        $this->assertTrue(method_exists(SendScheduledNotification::class, 'handle'));
    }

    public function test_stores_notification_data_passed_to_constructor(): void
    {
        $data = ['title' => 'Hello', 'message' => 'World', 'type' => 'test', 'user_id' => 42];
        $job = new SendScheduledNotification($data);

        $ref = new \ReflectionClass($job);
        $prop = $ref->getProperty('notificationData');
        $prop->setAccessible(true);

        $this->assertEquals($data, $prop->getValue($job));
    }

    // ─── handle() ─────────────────────────────────────────────────────────────

    public function test_handle_calls_notification_service_create_once(): void
    {
        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('create')->once()->andReturn(new UserNotification);

        (new SendScheduledNotification($this->sampleData))->handle($service);
    }

    public function test_handle_passes_correct_data_to_service(): void
    {
        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('create')
            ->once()
            ->with($this->sampleData)
            ->andReturn(new UserNotification);

        (new SendScheduledNotification($this->sampleData))->handle($service);
    }

    public function test_handle_rethrows_exception_from_service(): void
    {
        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('create')
            ->once()
            ->andThrow(new \RuntimeException('Service failure'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Service failure');

        (new SendScheduledNotification($this->sampleData))->handle($service);
    }

    // ─── Traits & contracts ───────────────────────────────────────────────────

    public function test_uses_queueable_trait(): void
    {
        $this->assertTrue(
            in_array(Queueable::class,
                class_uses_recursive(SendScheduledNotification::class))
        );
    }

    public function test_uses_serializes_models_trait(): void
    {
        $this->assertTrue(
            in_array(SerializesModels::class,
                class_uses_recursive(SendScheduledNotification::class))
        );
    }

    public function test_uses_dispatchable_trait(): void
    {
        $this->assertTrue(
            in_array(Dispatchable::class,
                class_uses_recursive(SendScheduledNotification::class))
        );
    }

    public function test_implements_should_queue_interface(): void
    {
        $this->assertInstanceOf(
            ShouldQueue::class,
            new SendScheduledNotification($this->sampleData)
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
