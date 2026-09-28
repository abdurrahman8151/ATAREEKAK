<?php

namespace Tests\Unit\Notifications;

use App\Models\User;
use App\Notifications\RideBookedNotification;
use App\Notifications\RideCancelledNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * RideNotificationsTest — Unit tests for stub ride notification classes.
 *
 * LOCATION: tests/Unit/Notifications/RideNotificationsTest.php
 *
 * NOTE ON THESE NOTIFICATIONS:
 * RideBookedNotification and RideCancelledNotification are currently stubs —
 * their toMail() returns a generic MailMessage and toArray() returns [].
 * These tests verify the notification contract (channels, types, structure) and
 * act as a regression guard so that when real content is added, the tests
 * serve as a spec to fill in.
 *
 * POSTMAN EQUIVALENT: No direct HTTP endpoint — notifications are dispatched
 * internally when a ride is booked/cancelled. To trigger manually:
 *   php artisan notification:test {user_id} --type=welcome
 */

// ════════════════════════════════════════════════════════════════════════════
// RideBookedNotification
// ════════════════════════════════════════════════════════════════════════════

class RideBookedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private RideBookedNotification $notification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->notification = new RideBookedNotification;
    }

    public function test_can_be_instantiated(): void
    {
        $this->assertInstanceOf(RideBookedNotification::class, $this->notification);
    }

    public function test_via_returns_mail_channel(): void
    {
        $channels = $this->notification->via($this->user);
        $this->assertIsArray($channels);
        $this->assertContains('mail', $channels);
    }

    public function test_to_mail_returns_mail_message_instance(): void
    {
        $mail = $this->notification->toMail($this->user);
        $this->assertInstanceOf(MailMessage::class, $mail);
    }

    public function test_to_array_returns_array(): void
    {
        $array = $this->notification->toArray($this->user);
        $this->assertIsArray($array);
    }

    public function test_to_mail_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->notification->toMail($this->user);
    }

    public function test_to_array_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->notification->toArray($this->user);
    }

    public function test_via_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->notification->via($this->user);
    }

    public function test_notification_uses_queueable_trait(): void
    {
        $this->assertTrue(
            in_array(Queueable::class, class_uses_recursive(RideBookedNotification::class))
        );
    }
}
