<?php

namespace Tests\Unit\Notifications;

use App\Models\User;
use App\Notifications\MessageReceivedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

class MessageReceivedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MessageReceivedNotification $notification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->notification = new MessageReceivedNotification;
    }

    // ─── Instantiation ────────────────────────────────────────────────────────────

    public function test_notification_can_be_instantiated(): void
    {
        $this->assertInstanceOf(MessageReceivedNotification::class, $this->notification);
    }

    // ─── via() ────────────────────────────────────────────────────────────────────

    public function test_via_returns_array(): void
    {
        $channels = $this->notification->via($this->user);
        $this->assertIsArray($channels);
    }

    public function test_via_returns_mail_channel(): void
    {
        $channels = $this->notification->via($this->user);
        $this->assertContains('mail', $channels);
    }

    public function test_via_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->notification->via($this->user);
    }

    // ─── toMail() ─────────────────────────────────────────────────────────────────

    public function test_to_mail_returns_mail_message_instance(): void
    {
        $mail = $this->notification->toMail($this->user);
        $this->assertInstanceOf(MailMessage::class, $mail);
    }

    public function test_to_mail_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->notification->toMail($this->user);
    }

    // ─── toArray() ────────────────────────────────────────────────────────────────

    public function test_to_array_returns_array(): void
    {
        $array = $this->notification->toArray($this->user);
        $this->assertIsArray($array);
    }

    public function test_to_array_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->notification->toArray($this->user);
    }

    // ─── Traits ───────────────────────────────────────────────────────────────────

    public function test_notification_uses_queueable_trait(): void
    {
        $this->assertTrue(
            in_array(
                Queueable::class,
                class_uses_recursive(MessageReceivedNotification::class)
            )
        );
    }

    public function test_notification_extends_laravel_notification(): void
    {
        $this->assertInstanceOf(
            Notification::class,
            $this->notification
        );
    }
}
