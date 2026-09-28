<?php

namespace Tests\Unit\Notifications;

use App\Models\User;
use App\Notifications\CustomResetPassword;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class CustomResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CustomResetPassword $notification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email' => 'test@example.com']);
        $this->notification = new CustomResetPassword('fake-reset-token-abc123');
    }

    // ── Instantiation ─────────────────────────────────────────────────────────

    public function test_notification_can_be_instantiated(): void
    {
        $this->assertInstanceOf(CustomResetPassword::class, $this->notification);
    }

    public function test_notification_stores_token(): void
    {
        $n = new CustomResetPassword('my-secret-token');
        $this->assertEquals('my-secret-token', $n->token);
    }

    // ── toMail() ──────────────────────────────────────────────────────────────

    public function test_to_mail_returns_mail_message_instance(): void
    {
        $mail = $this->notification->toMail($this->user);
        $this->assertInstanceOf(MailMessage::class, $mail);
    }

    public function test_to_mail_subject_contains_reset(): void
    {
        $mail = $this->notification->toMail($this->user);
        $this->assertStringContainsString('Reset', $mail->subject);
    }

    public function test_to_mail_action_url_contains_token(): void
    {
        $mail = $this->notification->toMail($this->user);
        $this->assertStringContainsString('fake-reset-token-abc123', $mail->actionUrl);
    }

    public function test_to_mail_action_url_contains_email(): void
    {
        $mail = $this->notification->toMail($this->user);
        $this->assertStringContainsString(urlencode('test@example.com'), $mail->actionUrl);
    }

    public function test_to_mail_has_action_text(): void
    {
        $mail = $this->notification->toMail($this->user);
        $this->assertNotEmpty($mail->actionText);
    }

    public function test_to_mail_mentions_expiry_in_lines(): void
    {
        $mail = $this->notification->toMail($this->user);
        $allLines = array_merge(
            $mail->introLines ?? [],
            $mail->outroLines ?? []
        );
        $text = implode(' ', $allLines);
        // Should mention 60 minutes expiry
        $this->assertStringContainsString('60', $text);
    }

    public function test_to_mail_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->notification->toMail($this->user);
    }

    // ── Different tokens produce different URLs ────────────────────────────────

    public function test_different_tokens_produce_different_action_urls(): void
    {
        $n1 = new CustomResetPassword('token-one');
        $n2 = new CustomResetPassword('token-two');

        $url1 = $n1->toMail($this->user)->actionUrl;
        $url2 = $n2->toMail($this->user)->actionUrl;

        $this->assertNotEquals($url1, $url2);
    }

    // ── Traits ────────────────────────────────────────────────────────────────

    public function test_notification_uses_queueable_trait(): void
    {
        $this->assertTrue(
            in_array(
                Queueable::class,
                class_uses_recursive(CustomResetPassword::class)
            )
        );
    }

    public function test_notification_extends_reset_password_notification(): void
    {
        $this->assertInstanceOf(
            ResetPassword::class,
            $this->notification
        );
    }
}
