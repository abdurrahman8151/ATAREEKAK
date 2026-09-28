<?php

namespace Tests\Feature\Documents;

use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->user = User::factory()->create(['password' => bcrypt('password123')]);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_upload_requires_authentication(): void
    {
        $this->postJson('/api/profile/documents')->assertStatus(401);
    }

    // ── Successful uploads ────────────────────────────────────────────────────

    public function test_upload_face_id_succeeds(): void
    {
        $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'type' => 'face_id',
                'file' => UploadedFile::fake()->image('face.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_upload_back_id_succeeds(): void
    {
        $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'type' => 'back_id',
                'file' => UploadedFile::fake()->image('back.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(200);
    }

    public function test_upload_license_succeeds(): void
    {
        $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'type' => 'license',
                'file' => UploadedFile::fake()->image('license.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(200);
    }

    public function test_upload_mechanic_card_succeeds(): void
    {
        $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'type' => 'mechanic_card',
                'file' => UploadedFile::fake()->image('card.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(200);
    }

    // ── Validation failures ──────────────────────────────────────────────────

    public function test_upload_with_invalid_type_fails_with_422(): void
    {
        $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'type' => 'passport',
                'file' => UploadedFile::fake()->image('doc.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_upload_without_file_fails_with_422(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/profile/documents', ['type' => 'face_id'])
            ->assertStatus(422);
    }

    public function test_upload_without_type_fails_with_422(): void
    {
        $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'file' => UploadedFile::fake()->image('doc.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_upload_pdf_file_is_rejected(): void
    {
        $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'type' => 'face_id',
                'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    // ── Business logic ────────────────────────────────────────────────────────

    public function test_upload_persists_photo_to_database(): void
    {
        $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'type' => 'face_id',
                'file' => UploadedFile::fake()->image('face.jpg'),
            ], ['Accept' => 'application/json']);

        $this->assertDatabaseHas('photos', [
            'user_id' => $this->user->id,
            'type' => 'face_id',
        ]);
    }

    public function test_uploading_same_type_twice_replaces_previous(): void
    {
        $this->withToken($this->token)->post('/api/profile/documents', [
            'type' => 'face_id',
            'file' => UploadedFile::fake()->image('face1.jpg'),
        ], ['Accept' => 'application/json']);

        $this->withToken($this->token)->post('/api/profile/documents', [
            'type' => 'face_id',
            'file' => UploadedFile::fake()->image('face2.jpg'),
        ], ['Accept' => 'application/json']);

        $this->assertEquals(
            1,
            Photo::where('user_id', $this->user->id)->where('type', 'face_id')->count()
        );
    }

    public function test_upload_resets_verification_status_to_none(): void
    {
        $this->user->update([
            'verification_status' => 'approved',
            'is_verified_driver' => true,
            'is_verified_passenger' => true,
        ]);

        $this->withToken($this->token)->post('/api/profile/documents', [
            'type' => 'face_id',
            'file' => UploadedFile::fake()->image('face.jpg'),
        ], ['Accept' => 'application/json']);

        $this->user->refresh();
        $this->assertEquals('none', $this->user->verification_status);
        $this->assertFalse((bool) $this->user->is_verified_driver);
        $this->assertFalse((bool) $this->user->is_verified_passenger);
    }

    public function test_upload_blocked_while_verification_pending(): void
    {
        $this->user->update(['verification_status' => 'pending']);

        $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'type' => 'face_id',
                'file' => UploadedFile::fake()->image('face.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    // ── Response shape ───────────────────────────────────────────────────────

    public function test_response_contains_data_with_id_url_type(): void
    {
        $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'type' => 'face_id',
                'file' => UploadedFile::fake()->image('face.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['id', 'url', 'type'],
            ]);
    }

    public function test_response_type_matches_requested_type(): void
    {
        $response = $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'type' => 'back_id',
                'file' => UploadedFile::fake()->image('back.jpg'),
            ], ['Accept' => 'application/json']);

        $this->assertEquals('back_id', $response->json('data.type'));
    }

    public function test_response_url_is_a_string(): void
    {
        $response = $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'type' => 'face_id',
                'file' => UploadedFile::fake()->image('face.jpg'),
            ], ['Accept' => 'application/json']);

        $this->assertIsString($response->json('data.url'));
    }
}
