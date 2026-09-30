<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['password' => bcrypt('password123')]);
        $this->token = $this->getToken($this->user);
    }

    public function test_can_get_notifications(): void
    {
        $this->createNotificationForUser();
        $this->withToken($this->token)->getJson('/api/notifications')
            ->assertStatus(200)->assertJsonStructure(['data']);
    }

    public function test_can_get_unread_count(): void
    {
        $this->createNotificationForUser();
        $this->withToken($this->token)->getJson('/api/notifications/unread-count')
            ->assertStatus(200)->assertJsonStructure(['unread_count']);
    }

    public function test_unread_count_is_correct(): void
    {
        $this->createNotificationForUser();
        $this->createNotificationForUser();

        $response = $this->withToken($this->token)->getJson('/api/notifications/unread-count');
        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('unread_count'));
    }

    public function test_can_mark_notification_as_read(): void
    {
        $userNotif = $this->createNotificationForUser();

        // RV-36 / §1.3: was `assertNotEquals(500, ...)` blessing a 500 on any route
        // error. The route uses the user_notification id and the controller scopes by
        // the caller, so the correct result is a definite 200 with the row actually
        // marked read.
        $this->withToken($this->token)
            ->postJson("/api/notifications/{$userNotif->id}/read")
            ->assertStatus(200);

        $this->assertNotNull(
            $userNotif->fresh()->read_at,
            'the notification must really be marked read, not merely not-500'
        );
    }

    public function test_can_mark_all_as_read(): void
    {
        $this->createNotificationForUser();
        $this->createNotificationForUser();

        $this->withToken($this->token)->postJson('/api/notifications/read-all')
            ->assertStatus(200)->assertJsonPath('unread_count', 0);
    }

    public function test_mark_all_read_sets_unread_count_to_zero(): void
    {
        $this->createNotificationForUser();
        $this->createNotificationForUser();

        $this->withToken($this->token)->postJson('/api/notifications/read-all');

        $response = $this->withToken($this->token)->getJson('/api/notifications/unread-count');
        $this->assertEquals(0, $response->json('unread_count'));
    }

    public function test_can_delete_notification(): void
    {
        $userNotif = $this->createNotificationForUser();

        // RV-36 / §1.3: was `assertNotEquals(500, ...)`. The controller scopes by the
        // caller and returns a definite 200, and the row must actually be gone.
        $this->withToken($this->token)
            ->deleteJson("/api/notifications/{$userNotif->id}")
            ->assertStatus(200);

        $this->assertNull(
            UserNotification::find($userNotif->id),
            'the notification must really be deleted, not merely not-500'
        );
    }

    public function test_bulk_action_mark_read(): void
    {
        $n1 = $this->createNotificationForUser();
        $n2 = $this->createNotificationForUser();

        // RV-36 / §1.3: was `assertNotEquals(500, ...)` with a docblock claiming
        // auth()->id() is never populated. V12 REFUTED that — the JWT middleware calls
        // Auth::setUser(), so it resolves. Assert the real contract instead: 200 and the
        // rows actually flipped to read.
        $this->withToken($this->token)->postJson('/api/notifications/bulk-action', [
            'action' => 'mark_read',
            'notification_ids' => [$n1->id, $n2->id],
        ])->assertStatus(200)->assertJsonPath('success', true);

        $this->assertNotNull($n1->fresh()->read_at);
        $this->assertNotNull($n2->fresh()->read_at);
    }

    public function test_bulk_action_cannot_touch_another_users_notification(): void
    {
        // RV-36 IDOR/leak test. The victim owns the row; the caller does not.
        $victim = User::factory()->create(['password' => bcrypt('password123')]);
        $foreign = UserNotification::create([
            'user_id' => $victim->id,
            'notification_id' => Notification::create(
                ['title' => 'V', 'message' => 'V', 'type' => 'general', 'sent_at' => now()]
            )->id,
        ]);

        // The old code validated `exists:user_notifications,id` GLOBALLY, so this foreign
        // id passed validation, was dropped by the user_id filter, yet still returned a
        // success message — a silent no-op that looked like it worked. Scoping the rule to
        // the caller now makes it a clean 422.
        $response = $this->withToken($this->token)->postJson('/api/notifications/bulk-action', [
            'action' => 'mark_read',
            'notification_ids' => [$foreign->id],
        ]);
        $response->assertStatus(422);

        // The victim's row is untouched.
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_bulk_action_does_not_leak_id_existence(): void
    {
        // RV-36: with a global exists() rule, a NON-EXISTENT id returned 422 while
        // another user's REAL id returned 404 — a differentiable oracle that reveals which
        // notification ids exist. After ownership-scoping, both are the same 422, so the
        // response cannot distinguish "does not exist" from "exists but not yours".
        $victim = User::factory()->create(['password' => bcrypt('password123')]);
        $foreign = UserNotification::create([
            'user_id' => $victim->id,
            'notification_id' => Notification::create(
                ['title' => 'V', 'message' => 'V', 'type' => 'general', 'sent_at' => now()]
            )->id,
        ]);
        $nonExistent = $foreign->id + 999999;

        $realForeign = $this->withToken($this->token)->postJson('/api/notifications/bulk-action', [
            'action' => 'delete',
            'notification_ids' => [$foreign->id],
        ])->status();

        $fake = $this->withToken($this->token)->postJson('/api/notifications/bulk-action', [
            'action' => 'delete',
            'notification_ids' => [$nonExistent],
        ])->status();

        $this->assertSame(
            $realForeign,
            $fake,
            'existence must not be distinguishable by status code'
        );
    }

    public function test_can_get_notification_categories(): void
    {
        $this->withToken($this->token)->getJson('/api/notifications/categories')
            ->assertStatus(200)->assertJsonStructure(['data']);
    }

    public function test_notifications_require_auth(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
    }

    private function createNotificationForUser(): UserNotification
    {
        $notification = Notification::create([
            'title' => 'Test', 'message' => 'Test message', 'type' => 'general', 'sent_at' => now(),
        ]);

        return UserNotification::create([
            'user_id' => $this->user->id, 'notification_id' => $notification->id,
        ]);
    }

    private function getToken(User $user): string
    {
        $r = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123']);

        return $r->json('tokens.access_token');
    }
}
