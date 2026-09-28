<?php

namespace Tests\Unit\Notifications;

use App\Models\User;
use App\Notifications\UserVerifiedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class UserVerifiedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private UserVerifiedNotification $notification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->notification = new UserVerifiedNotification;
    }

    public function test_via_returns_mail_channel(): void
    {
        $channels = $this->notification->via($this->user);
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
}
