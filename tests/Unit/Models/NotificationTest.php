<?php

namespace Models;

use App\Models\Notification;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    // ─── Fillable ─────────────────────────────────────────────────────────────────

    public function test_fillable_contains_title(): void
    {
        $this->assertContains('title', (new Notification)->getFillable());
    }

    public function test_fillable_contains_message(): void
    {
        $this->assertContains('message', (new Notification)->getFillable());
    }

    public function test_fillable_contains_type(): void
    {
        $this->assertContains('type', (new Notification)->getFillable());
    }

    public function test_fillable_contains_data(): void
    {
        $this->assertContains('data', (new Notification)->getFillable());
    }

    public function test_fillable_contains_sent_at(): void
    {
        $this->assertContains('sent_at', (new Notification)->getFillable());
    }

    // ─── Casts ────────────────────────────────────────────────────────────────────

    public function test_data_is_cast_to_array(): void
    {
        $this->assertEquals('array', (new Notification)->getCasts()['data']);
    }

    public function test_sent_at_is_cast_to_datetime(): void
    {
        $this->assertEquals('datetime', (new Notification)->getCasts()['sent_at']);
    }

    // ─── Relationships ────────────────────────────────────────────────────────────

    public function test_has_user_relationship_method(): void
    {
        $this->assertTrue(method_exists(Notification::class, 'user'));
    }

    public function test_has_user_notifications_relationship_method(): void
    {
        $this->assertTrue(method_exists(Notification::class, 'userNotifications'));
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────────

    public function test_of_type_scope_filters_by_type(): void
    {
        Notification::create(['title' => 'A', 'message' => 'msg', 'type' => 'general', 'sent_at' => now()]);
        Notification::create(['title' => 'B', 'message' => 'msg', 'type' => 'system',  'sent_at' => now()]);

        $results = Notification::ofType('general')->get();

        $this->assertCount(1, $results);
        $this->assertEquals('general', $results->first()->type);
    }

    public function test_of_type_scope_returns_empty_when_no_match(): void
    {
        Notification::create(['title' => 'A', 'message' => 'msg', 'type' => 'general', 'sent_at' => now()]);

        $results = Notification::ofType('nonexistent')->get();

        $this->assertEmpty($results);
    }

    public function test_recent_scope_returns_notifications_within_7_days(): void
    {
        Notification::create(['title' => 'Recent', 'message' => 'msg', 'type' => 'general', 'sent_at' => now()->subDays(3)]);
        Notification::create(['title' => 'Old',    'message' => 'msg', 'type' => 'general', 'sent_at' => now()->subDays(10)]);

        // recent() defaults to last 7 days
        $results = Notification::recent()->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Recent', $results->first()->title);
    }

    public function test_recent_scope_accepts_custom_day_count(): void
    {
        Notification::create(['title' => 'A', 'message' => 'msg', 'type' => 'general', 'sent_at' => now()->subDays(5)]);
        Notification::create(['title' => 'B', 'message' => 'msg', 'type' => 'general', 'sent_at' => now()->subDays(15)]);

        $results = Notification::recent(10)->get();

        $this->assertCount(1, $results);
    }

    // ─── Persistence ──────────────────────────────────────────────────────────────

    public function test_notification_can_be_created_with_json_data(): void
    {
        $notification = Notification::create([
            'title' => 'Test',
            'message' => 'Body',
            'type' => 'general',
            'data' => ['key' => 'value'],
            'sent_at' => now(),
        ]);

        $fresh = Notification::find($notification->id);
        $this->assertIsArray($fresh->data);
        $this->assertEquals('value', $fresh->data['key']);
    }

    public function test_notification_user_notifications_relationship_loads(): void
    {
        $user = User::factory()->create();
        $notification = Notification::create([
            'title' => 'Test',
            'message' => 'Body',
            'type' => 'general',
            'sent_at' => now(),
        ]);

        UserNotification::create([
            'user_id' => $user->id,
            'notification_id' => $notification->id,
        ]);

        $this->assertEquals(1, $notification->userNotifications()->count());
    }
}
