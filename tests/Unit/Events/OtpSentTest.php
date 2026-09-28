<?php

namespace Tests\Unit\Events;

use App\Events\OtpSent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use PHPUnit\Framework\TestCase;

class OtpSentTest extends TestCase
{
    public function test_event_can_be_instantiated(): void
    {
        $event = new OtpSent;
        $this->assertInstanceOf(OtpSent::class, $event);
    }

    public function test_broadcast_on_returns_array(): void
    {
        $channels = (new OtpSent)->broadcastOn();
        $this->assertIsArray($channels);
    }

    public function test_broadcast_on_returns_non_empty_array(): void
    {
        $channels = (new OtpSent)->broadcastOn();
        $this->assertNotEmpty($channels);
    }

    public function test_broadcast_on_returns_private_channel(): void
    {
        $channels = (new OtpSent)->broadcastOn();
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
    }

    public function test_event_uses_dispatchable_trait(): void
    {
        $this->assertTrue(
            in_array(
                Dispatchable::class,
                class_uses_recursive(OtpSent::class)
            )
        );
    }

    public function test_event_uses_serializes_models_trait(): void
    {
        $this->assertTrue(
            in_array(
                SerializesModels::class,
                class_uses_recursive(OtpSent::class)
            )
        );
    }

    public function test_event_uses_interacts_with_sockets_trait(): void
    {
        $this->assertTrue(
            in_array(
                InteractsWithSockets::class,
                class_uses_recursive(OtpSent::class)
            )
        );
    }

    public function test_event_class_exists(): void
    {
        $this->assertTrue(class_exists(OtpSent::class));
    }

    public function test_event_has_broadcast_on_method(): void
    {
        $this->assertTrue(method_exists(OtpSent::class, 'broadcastOn'));
    }

    public function test_two_instances_are_independent(): void
    {
        $a = new OtpSent;
        $b = new OtpSent;
        $this->assertNotSame($a, $b);
    }
}
