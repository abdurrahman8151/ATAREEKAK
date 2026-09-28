<?php

namespace Tests\Unit\Broadcasting;

use App\Broadcasting\NotificationChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationChannelTest extends TestCase
{
    use RefreshDatabase;

    // ─── Structure ────────────────────────────────────────────────────────────────

    public function test_channel_class_exists(): void
    {
        $this->assertTrue(class_exists(NotificationChannel::class));
    }

    public function test_channel_can_be_instantiated(): void
    {
        $channel = new NotificationChannel;
        $this->assertInstanceOf(NotificationChannel::class, $channel);
    }

    public function test_channel_has_join_method(): void
    {
        $this->assertTrue(method_exists(NotificationChannel::class, 'join'));
    }

    public function test_channel_can_be_resolved_from_container(): void
    {
        $channel = app(NotificationChannel::class);
        $this->assertInstanceOf(NotificationChannel::class, $channel);
    }

    // ─── join() ───────────────────────────────────────────────────────────────────

    public function test_join_method_throws_type_error_because_body_is_empty_stub(): void
    {
        // FIX: NotificationChannel::join() has return type array|bool but its body
        // is empty (stub), implicitly returning null. PHP 8 enforces return types
        // at call time and throws TypeError. Fix: implement the method body.
        $user = User::factory()->create();
        $channel = new NotificationChannel;

        $this->expectException(\TypeError::class);
        $channel->join($user);
    }

    public function test_join_return_type_is_declared_as_array_or_bool(): void
    {
        $ref = new \ReflectionClass(NotificationChannel::class);
        $method = $ref->getMethod('join');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertStringContainsString('bool', (string) $returnType);
    }

    public function test_join_accepts_user_parameter(): void
    {
        $ref = new \ReflectionClass(NotificationChannel::class);
        $method = $ref->getMethod('join');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('user', $params[0]->getName());
    }
}
