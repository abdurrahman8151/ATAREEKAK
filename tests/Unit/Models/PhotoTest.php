<?php

namespace Tests\Unit\Models;

use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // ─── Fillable ─────────────────────────────────────────────────────────────────

    public function test_fillable_contains_user_id(): void
    {
        $this->assertContains('user_id', (new Photo)->getFillable());
    }

    public function test_fillable_contains_type(): void
    {
        $this->assertContains('type', (new Photo)->getFillable());
    }

    public function test_fillable_contains_path(): void
    {
        $this->assertContains('path', (new Photo)->getFillable());
    }

    // ─── Relationships ────────────────────────────────────────────────────────────

    public function test_has_user_relationship_method(): void
    {
        $this->assertTrue(method_exists(Photo::class, 'user'));
    }

    public function test_user_relationship_returns_correct_user(): void
    {
        $photo = Photo::create([
            'user_id' => $this->user->id,
            'type' => 'face_id',
            'path' => 'verifications/face_id/test.jpg',
        ]);

        $this->assertEquals($this->user->id, $photo->user->id);
    }

    // ─── Persistence ──────────────────────────────────────────────────────────────

    public function test_photo_can_be_created_in_database(): void
    {
        Photo::create([
            'user_id' => $this->user->id,
            'type' => 'face_id',
            'path' => 'verifications/face_id/test.jpg',
        ]);

        $this->assertDatabaseHas('photos', [
            'user_id' => $this->user->id,
            'type' => 'face_id',
        ]);
    }

    public function test_multiple_photo_types_can_exist_for_same_user(): void
    {
        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $type) {
            Photo::create([
                'user_id' => $this->user->id,
                'type' => $type,
                'path' => "verifications/{$type}/test.jpg",
            ]);
        }

        $this->assertEquals(4, Photo::where('user_id', $this->user->id)->count());
    }

    public function test_photo_belongs_to_user_and_is_deleted_with_user(): void
    {
        Photo::create([
            'user_id' => $this->user->id,
            'type' => 'back_id',
            'path' => 'verifications/back_id/test.jpg',
        ]);

        $userId = $this->user->id;
        $this->user->delete();

        // After user deletion, photo user() returns null
        $photo = Photo::where('user_id', $userId)->first();
        $this->assertNull($photo); // cascades on delete or photo has no user
    }

    public function test_photo_path_is_stored_correctly(): void
    {
        $path = 'verifications/license/driver_42_1234567890.jpg';
        $photo = Photo::create([
            'user_id' => $this->user->id,
            'type' => 'license',
            'path' => $path,
        ]);

        $this->assertEquals($path, $photo->fresh()->path);
    }

    public function test_photo_type_is_stored_correctly(): void
    {
        $photo = Photo::create([
            'user_id' => $this->user->id,
            'type' => 'mechanic_card',
            'path' => 'verifications/mechanic_card/test.jpg',
        ]);

        $this->assertEquals('mechanic_card', $photo->fresh()->type);
    }
}
