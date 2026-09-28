<?php

namespace Tests\Unit\Notifications;

use App\Models\User;
use App\Notifications\RideCancelledNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

/**
 * RideCancelledNotificationTest — Unit tests for RideCancelledNotification.
 *
 * RideCancelledNotification is currently a stub — toMail() returns a generic
 * MailMessage and toArray() returns [].  These tests verify the notification
 * contract and act as a regression guard so that when real content is added
 * the tests serve as a spec to fill in.
 */
class RideCancelledNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private RideCancelledNotification $notification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->notification = new RideCancelledNotification;
    }

    // ─── Instantiation ────────────────────────────────────────────────────────

    public function test_can_be_instantiated(): void
    {
        $this->assertInstanceOf(RideCancelledNotification::class, $this->notification);
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists(RideCancelledNotification::class));
    }

    public function test_extends_notification_base_class(): void
    {
        $this->assertInstanceOf(
            Notification::class,
            $this->notification
        );
    }

    // ─── via ──────────────────────────────────────────────────────────────────

    public function test_via_returns_array(): void
    {
        $this->assertIsArray($this->notification->via($this->user));
    }

    public function test_via_contains_mail_channel(): void
    {
        $this->assertContains('mail', $this->notification->via($this->user));
    }

    public function test_via_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->notification->via($this->user);
    }

    // ─── toMail ───────────────────────────────────────────────────────────────

    public function test_to_mail_returns_mail_message_instance(): void
    {
        $this->assertInstanceOf(MailMessage::class, $this->notification->toMail($this->user));
    }

    public function test_to_mail_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->notification->toMail($this->user);
    }

    // ─── toArray ──────────────────────────────────────────────────────────────

    public function test_to_array_returns_array(): void
    {
        $this->assertIsArray($this->notification->toArray($this->user));
    }

    public function test_to_array_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->notification->toArray($this->user);
    }

    // ─── Traits ───────────────────────────────────────────────────────────────

    public function test_uses_queueable_trait(): void
    {
        $this->assertTrue(
            in_array(
                Queueable::class,
                class_uses_recursive(RideCancelledNotification::class)
            )
        );
    }
}
