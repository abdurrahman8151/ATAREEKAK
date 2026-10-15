<?php

namespace Tests\Feature\Review;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Characterization of POST /api/profile/documents (DocumentController::store), pinned on the UNCHANGED code
 * before the method is moved.
 *
 * Existing tests pin the 403 while pending (by status), the identity-document reset, and the 422 validation.
 * Two contracts are not pinned by value:
 *  - the 403 sentence "Cannot modify documents while verification is pending".
 *  - the driver-document revocation rule: replacing a licence or mechanic card clears the driver verification
 *    but must NOT clear passenger verification when the user is a verified passenger (the unset branch).
 *    Stating the rule as a test keeps an auth-relevant behaviour from changing silently.
 *
 * Real route, real upload, real database. Only the disk is faked (the documents disk is `local`).
 */
class DocumentUploadRevocationCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->user = User::factory()->create([
            'password' => bcrypt('password123'),
            'email_verified_at' => now(),
            'status' => 1,
        ]);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');
    }

    public function test_upload_while_pending_returns_403_with_the_exact_sentence(): void
    {
        $this->user->update(['verification_status' => 'pending']);

        $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'type' => 'face_id',
                'file' => UploadedFile::fake()->image('face.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Cannot modify documents while verification is pending');
    }

    public function test_replacing_a_driver_document_keeps_passenger_verification_when_the_user_is_a_verified_passenger(): void
    {
        $this->user->update([
            'verification_status' => 'approved',
            'is_verified_passenger' => true,
            'is_verified_driver' => true,
        ]);

        $this->withToken($this->token)
            ->post('/api/profile/documents', [
                'type' => 'license',
                'file' => UploadedFile::fake()->image('licence.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $this->user->refresh();

        // Driver verification is revoked by a driver-document change ...
        $this->assertFalse((bool) $this->user->is_verified_driver, 'driver verification must be revoked');
        // ... but a verified passenger keeps passenger status and the approved verification_status.
        $this->assertTrue((bool) $this->user->is_verified_passenger, 'passenger verification must be kept');
        $this->assertEquals('approved', $this->user->verification_status, 'verification_status must be kept');
    }
}
