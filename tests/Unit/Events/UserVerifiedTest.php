<?php

namespace Tests\Unit\Events;

use App\Events\UserVerified;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserVerifiedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private UserVerified $driverEvent;

    private UserVerified $passengerEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'is_verified_driver' => true,
            'is_verified_passenger' => true,
        ]);

        $this->driverEvent = new UserVerified($this->user, 'driver');
        $this->passengerEvent = new UserVerified($this->user, 'passenger');
    }

    public function test_stores_user_and_verification_type(): void
    {
        $this->assertSame($this->user, $this->driverEvent->user);
        $this->assertEquals('driver', $this->driverEvent->verificationType);
    }

    public function test_broadcast_on_returns_private_channel_for_user(): void
    {
        $channels = $this->driverEvent->broadcastOn();

        $this->assertNotEmpty($channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
    }

    public function test_broadcast_as_returns_user_verified(): void
    {
        $this->assertEquals('user.verified', $this->driverEvent->broadcastAs());
    }

    public function test_broadcast_with_contains_required_keys(): void
    {
        $data = $this->driverEvent->broadcastWith();

        $this->assertArrayHasKey('user_id', $data);
        $this->assertArrayHasKey('verification_type', $data);
        $this->assertArrayHasKey('is_verified_driver', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('verified_at', $data);
    }

    public function test_broadcast_with_has_correct_user_id(): void
    {
        $data = $this->driverEvent->broadcastWith();
        $this->assertEquals($this->user->id, $data['user_id']);
    }

    public function test_driver_message_contains_driver_keyword(): void
    {
        $data = $this->driverEvent->broadcastWith();
        $this->assertStringContainsString('driver', strtolower($data['message']));
    }

    public function test_passenger_message_contains_passenger_keyword(): void
    {
        $data = $this->passengerEvent->broadcastWith();
        $this->assertStringContainsString('passenger', strtolower($data['message']));
    }

    public function test_unknown_type_returns_generic_message(): void
    {
        $event = new UserVerified($this->user, 'unknown');
        $data = $event->broadcastWith();

        $this->assertIsString($data['message']);
        $this->assertNotEmpty($data['message']);
    }
}
