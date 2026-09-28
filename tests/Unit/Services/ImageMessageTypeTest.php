<?php

namespace Tests\Unit\Services;

use App\Interfaces\MessageTypeInterface;
use App\Services\MessageTypes\ImageMessageType;
use Tests\TestCase;

/**
 * ImageMessageTypeTest
 *
 * ImageMessageType is a small, stateless class implementing MessageTypeInterface.
 * It validates that an incoming message has image content and processes it into
 * the standard message payload shape the chat system expects.
 */
class ImageMessageTypeTest extends TestCase
{
    private ImageMessageType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = new ImageMessageType;
    }

    // ─── Instantiation & interface ─────────────────────────────────────────

    public function test_can_be_instantiated(): void
    {
        $this->assertInstanceOf(ImageMessageType::class, $this->type);
    }

    public function test_implements_message_type_interface(): void
    {
        $this->assertInstanceOf(MessageTypeInterface::class, $this->type);
    }

    public function test_has_validate_method(): void
    {
        $this->assertTrue(method_exists(ImageMessageType::class, 'validate'));
    }

    public function test_has_process_method(): void
    {
        $this->assertTrue(method_exists(ImageMessageType::class, 'process'));
    }

    // ─── validate ──────────────────────────────────────────────────────────

    public function test_validate_returns_true_with_valid_image_content(): void
    {
        $result = $this->type->validate([
            'content' => 'images/chat/photo.jpg',
            'type' => 'image',
        ]);

        $this->assertTrue($result);
    }

    public function test_validate_returns_bool(): void
    {
        $result = $this->type->validate(['content' => 'some/path.png']);

        $this->assertIsBool($result);
    }

    public function test_validate_returns_false_when_content_missing(): void
    {
        $result = $this->type->validate(['type' => 'image']);

        $this->assertFalse($result);
    }

    public function test_validate_returns_false_for_empty_array(): void
    {
        $this->assertFalse($this->type->validate([]));
    }

    public function test_validate_returns_false_when_content_is_empty_string(): void
    {
        $this->assertFalse($this->type->validate(['content' => '']));
    }

    // ─── process ───────────────────────────────────────────────────────────

    public function test_process_returns_array(): void
    {
        $result = $this->type->process([
            'content' => 'images/chat/test.jpg',
            'type' => 'image',
        ]);

        $this->assertIsArray($result);
    }

    public function test_process_result_is_not_empty(): void
    {
        $result = $this->type->process([
            'content' => 'images/chat/test.jpg',
        ]);

        $this->assertNotEmpty($result);
    }

    public function test_process_preserves_content_field(): void
    {
        $result = $this->type->process([
            'content' => 'images/chat/preserved.jpg',
        ]);

        $this->assertArrayHasKey('content', $result);
        $this->assertNotEmpty($result['content']);
    }

    public function test_validate_then_process_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();

        $data = ['content' => 'images/chat/photo.png', 'type' => 'image'];

        if ($this->type->validate($data)) {
            $this->type->process($data);
        }
    }
}
