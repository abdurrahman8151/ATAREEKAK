<?php

namespace Tests\Unit\Services;

use App\Interfaces\MessageTypeInterface;
use App\Services\MessageTypes\ImageMessageType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ImageMessageTypeTest
 *
 * ImageMessageType implements MessageTypeInterface. The live image contract is an
 * UploadedFile under the `image` key (ChatMessageHandler::sendImageMessage). The earlier
 * tests passed a stored path string as `content`, which is a retired contract that
 * validate() correctly rejects (RV-51, owner ruling option 1).
 */
class ImageMessageTypeTest extends TestCase
{
    private ImageMessageType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = new ImageMessageType;
        Storage::fake('public');
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

    public function test_validate_returns_true_with_valid_uploaded_image(): void
    {
        $result = $this->type->validate([
            'image' => UploadedFile::fake()->image('photo.jpg'),
        ]);

        $this->assertTrue($result);
    }

    public function test_validate_returns_bool(): void
    {
        $result = $this->type->validate(['image' => UploadedFile::fake()->image('a.png')]);

        $this->assertIsBool($result);
    }

    public function test_validate_returns_false_when_image_missing(): void
    {
        $result = $this->type->validate(['type' => 'image']);

        $this->assertFalse($result);
    }

    public function test_validate_returns_false_for_empty_array(): void
    {
        $this->assertFalse($this->type->validate([]));
    }

    public function test_validate_rejects_a_stored_path_string_instead_of_a_file(): void
    {
        // The retired contract: a path string is not an uploaded image.
        $this->assertFalse($this->type->validate(['image' => 'images/chat/photo.jpg']));
    }

    // ─── process ───────────────────────────────────────────────────────────

    public function test_process_returns_array(): void
    {
        $result = $this->type->process([
            'image' => UploadedFile::fake()->image('test.jpg'),
            'caption' => 'hello',
        ]);

        $this->assertIsArray($result);
    }

    public function test_process_result_is_not_empty(): void
    {
        $result = $this->type->process([
            'image' => UploadedFile::fake()->image('test.jpg'),
        ]);

        $this->assertNotEmpty($result);
    }

    public function test_process_preserves_caption_as_content(): void
    {
        $result = $this->type->process([
            'image' => UploadedFile::fake()->image('preserved.jpg'),
            'caption' => 'a caption',
        ]);

        $this->assertArrayHasKey('content', $result);
        $this->assertSame('a caption', $result['content']);
    }

    public function test_process_stores_the_image_and_reports_its_metadata(): void
    {
        $file = UploadedFile::fake()->image('stored.jpg');

        $result = $this->type->process(['image' => $file]);

        $this->assertArrayHasKey('metadata', $result);
        $this->assertSame('stored.jpg', $result['metadata']['image_name']);
        $this->assertArrayHasKey('image_url', $result['metadata']);
        $this->assertNotEmpty($result['metadata']['image_url']);
    }

    public function test_validate_then_process_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();

        $data = ['image' => UploadedFile::fake()->image('photo.png')];

        if ($this->type->validate($data)) {
            $this->type->process($data);
        }
    }
}
