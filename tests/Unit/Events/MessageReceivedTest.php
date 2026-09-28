<?php

namespace Tests\Unit\Events;

use App\Events\MessageReceived;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PHPUnit\Framework\TestCase;

class MessageReceivedTest extends TestCase
{
    public function test_event_can_be_instantiated(): void
    {
        $event = new MessageReceived;
        $this->assertInstanceOf(MessageReceived::class, $event);
    }

    public function test_broadcast_on_returns_array(): void
    {
        $channels = (new MessageReceived)->broadcastOn();
        $this->assertIsArray($channels);
    }

    public function test_broadcast_on_returns_non_empty_array(): void
    {
        $channels = (new MessageReceived)->broadcastOn();
        $this->assertNotEmpty($channels);
    }

    public function test_broadcast_on_returns_private_channel(): void
    {
        $channels = (new MessageReceived)->broadcastOn();
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
    }

    public function test_event_uses_dispatchable_trait(): void
    {
        $this->assertTrue(
            in_array(
                Dispatchable::class,
                class_uses_recursive(MessageReceived::class)
            )
        );
    }

    public function test_event_uses_serializes_models_trait(): void
    {
        $this->assertTrue(
            in_array(
                SerializesModels::class,
                class_uses_recursive(MessageReceived::class)
            )
        );
    }

    public function test_event_uses_interacts_with_sockets_trait(): void
    {
        $this->assertTrue(
            in_array(
                InteractsWithSockets::class,
                class_uses_recursive(MessageReceived::class)
            )
        );
    }

    public function test_event_class_exists(): void
    {
        $this->assertTrue(class_exists(MessageReceived::class));
    }

    public function test_event_has_broadcast_on_method(): void
    {
        $this->assertTrue(method_exists(MessageReceived::class, 'broadcastOn'));
    }
}
