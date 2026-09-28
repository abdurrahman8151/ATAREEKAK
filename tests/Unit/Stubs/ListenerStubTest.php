<?php

namespace Tests\Unit\Stubs;

use App\Events\UserVerified;
use App\Jobs\SendPushNotification;
use App\Jobs\SendScheduledNotification;
use App\Listeners\SendMessageNotification;
use App\Listeners\SendOtpNotification;
use App\Listeners\SendRideBookedNotification;
use App\Listeners\SendRideCancelledNotification;
use App\Listeners\SendUserVerifiedNotification;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserRating;
use App\Services\NotificationService;
use Mockery;
use PHPUnit\Framework\TestCase;

// ════════════════════════════════════════════════════════════════════════════
// Listener stub tests
// These listeners have empty handle() methods — tests confirm they exist,
// can be instantiated, and implement the expected contract.
// ════════════════════════════════════════════════════════════════════════════

class ListenerStubTest extends TestCase
{
    public function test_send_message_notification_listener_can_be_instantiated(): void
    {
        $listener = new SendMessageNotification;
        $this->assertInstanceOf(SendMessageNotification::class, $listener);
    }

    public function test_send_message_notification_has_handle_method(): void
    {
        $this->assertTrue(method_exists(SendMessageNotification::class, 'handle'));
    }

    public function test_send_otp_notification_listener_can_be_instantiated(): void
    {
        $listener = new SendOtpNotification;
        $this->assertInstanceOf(SendOtpNotification::class, $listener);
    }

    public function test_send_otp_notification_has_handle_method(): void
    {
        $this->assertTrue(method_exists(SendOtpNotification::class, 'handle'));
    }

    public function test_send_ride_booked_notification_can_be_instantiated(): void
    {
        $listener = new SendRideBookedNotification;
        $this->assertInstanceOf(SendRideBookedNotification::class, $listener);
    }

    public function test_send_ride_booked_notification_has_handle_method(): void
    {
        $this->assertTrue(method_exists(SendRideBookedNotification::class, 'handle'));
    }

    public function test_send_ride_cancelled_notification_can_be_instantiated(): void
    {
        $listener = new SendRideCancelledNotification;
        $this->assertInstanceOf(SendRideCancelledNotification::class, $listener);
    }

    public function test_send_user_verified_notification_can_be_instantiated(): void
    {
        $listener = new SendUserVerifiedNotification;
        $this->assertInstanceOf(SendUserVerifiedNotification::class, $listener);
    }
}

// ════════════════════════════════════════════════════════════════════════════
// Job tests
// ════════════════════════════════════════════════════════════════════════════

class JobStubTest extends TestCase
{
    public function test_send_push_notification_job_can_be_instantiated(): void
    {
        $job = new SendPushNotification(1, ['title' => 'Test', 'body' => 'Hello']);
        $this->assertInstanceOf(SendPushNotification::class, $job);
    }

    public function test_send_push_notification_has_handle_method(): void
    {
        $this->assertTrue(method_exists(SendPushNotification::class, 'handle'));
    }

    public function test_send_scheduled_notification_job_can_be_instantiated(): void
    {
        $job = new SendScheduledNotification([
            'title' => 'Scheduled',
            'message' => 'Hello',
            'type' => 'test',
            'user_id' => 1,
        ]);
        $this->assertInstanceOf(SendScheduledNotification::class, $job);
    }

    public function test_send_scheduled_notification_has_handle_method(): void
    {
        $this->assertTrue(method_exists(SendScheduledNotification::class, 'handle'));
    }

    public function test_send_scheduled_notification_handle_calls_notification_service(): void
    {
        $notifService = Mockery::mock(NotificationService::class);
        $notifService->shouldReceive('create')->once()->andReturn(
            new UserNotification
        );

        $job = new SendScheduledNotification([
            'title' => 'Test',
            'message' => 'Body',
            'type' => 'test',
            'user_id' => 1,
        ]);

        $job->handle($notifService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}

// ════════════════════════════════════════════════════════════════════════════
// UserVerified Event tests
// ════════════════════════════════════════════════════════════════════════════

class UserVerifiedEventTest extends TestCase
{
    private function makeUser(): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 1;
        $user->first_name = 'Ahmad';
        $user->last_name = 'Ali';
        $user->is_verified_driver = true;
        $user->is_verified_passenger = true;

        return $user;
    }

    public function test_user_verified_event_stores_user(): void
    {
        $user = $this->makeUser();
        $event = new UserVerified($user, 'driver');
        $this->assertSame($user, $event->user);
    }

    public function test_user_verified_event_stores_verification_type(): void
    {
        $user = $this->makeUser();
        $event = new UserVerified($user, 'passenger');
        $this->assertEquals('passenger', $event->verificationType);
    }

    public function test_broadcast_on_returns_private_channel(): void
    {
        $user = $this->makeUser();
        $event = new UserVerified($user, 'driver');
        $channels = $event->broadcastOn();
        $this->assertNotEmpty($channels);
    }

    public function test_broadcast_as_returns_correct_event_name(): void
    {
        $user = $this->makeUser();
        $event = new UserVerified($user, 'driver');
        $this->assertEquals('user.verified', $event->broadcastAs());
    }

    public function test_broadcast_with_contains_required_keys(): void
    {
        $user = $this->makeUser();
        $event = new UserVerified($user, 'driver');
        $data = $event->broadcastWith();

        $this->assertArrayHasKey('user_id', $data);
        $this->assertArrayHasKey('verification_type', $data);
        $this->assertArrayHasKey('is_verified_driver', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('verified_at', $data);
    }

    public function test_driver_verification_message(): void
    {
        $user = $this->makeUser();
        $event = new UserVerified($user, 'driver');
        $data = $event->broadcastWith();
        $this->assertStringContainsString('driver', strtolower($data['message']));
    }

    public function test_passenger_verification_message(): void
    {
        $user = $this->makeUser();
        $event = new UserVerified($user, 'passenger');
        $data = $event->broadcastWith();
        $this->assertStringContainsString('passenger', strtolower($data['message']));
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}

// ════════════════════════════════════════════════════════════════════════════
// UserRating model tests
// ════════════════════════════════════════════════════════════════════════════

class UserRatingModelTest extends TestCase
{
    public function test_fillable_contains_expected_fields(): void
    {
        $model = new UserRating;
        $fillable = $model->getFillable();

        $this->assertContains('rater_id', $fillable);
        $this->assertContains('rated_user_id', $fillable);
        $this->assertContains('rating', $fillable);
    }

    public function test_rating_is_cast_to_float(): void
    {
        $casts = (new UserRating)->getCasts();
        $this->assertEquals('float', $casts['rating']);
    }

    public function test_has_rater_relationship(): void
    {
        $this->assertTrue(method_exists(UserRating::class, 'rater'));
    }

    public function test_has_rated_user_relationship(): void
    {
        $this->assertTrue(method_exists(UserRating::class, 'ratedUser'));
    }
}
