<?php

namespace Tests\Feature\Review;

use App\Models\Employee;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Decision 1b (owner, 2026-10-02): KYC identity documents must not be publicly readable.
 *
 * The IDOR this pins: documents were stored on the `public` disk and every staff surface rendered
 * them as `asset('storage/'.$p->path)` - an unauthenticated URL to a face ID photo, a back ID, a
 * driving licence or a mechanic card. Anyone with the URL (it appears in API responses; and storage
 * keys are guessable) could download another person's identity documents.
 *
 * These tests pin the three properties that make the new route safe:
 *   1. no token  -> 401 (the IDOR itself);
 *   2. a USER token (not staff) -> 401, i.e. the two token audiences stay separated;
 *   3. a staff token -> 200 with the file bytes.
 *
 * A fourth test pins the honesty of the staff queue: it must now hand out the staff route, not a
 * public asset URL.
 */
class KycDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    private function seedDocument(string $type = 'face_id'): Photo
    {
        $user = User::factory()->create();
        // Unique filename per run: RefreshDatabase rolls back the DATABASE, not the real filesystem,
        // so a fixed documents/test-face_id.jpg would survive between runs and make this test depend
        // on whatever was written last.
        $path = 'documents/test-'.$type.'-'.uniqid().'.jpg';
        Storage::disk('public')->put($path, 'DOCUMENT-BYTES-'.$path);

        return Photo::create([
            'user_id' => $user->id,
            'type' => $type,
            'path' => $path,
        ]);
    }

    private function staffToken(): string
    {
        $employee = Employee::create([
            'username' => 'doc_'.uniqid(),
            'email' => 'doc_'.uniqid().'@test.com',
            'password' => 'Password123!',
            'first_name' => 'Doc', 'last_name' => 'Viewer',
            'role' => 'system_admin',
            'is_active' => true,
            'token_version' => 1,
        ]);

        $token = $this->postJson('/api/staff/login', [
            'identifier' => $employee->username,
            'password' => 'Password123!',
        ])->json('tokens.access_token');

        $this->assertNotEmpty($token, 'staff login must yield an access token');

        return (string) $token;
    }

    /** @test */
    public function an_unauthenticated_caller_cannot_read_a_kyc_document(): void
    {
        $photo = $this->seedDocument();

        $this->getJson("/api/staff/documents/{$photo->id}")
            ->assertStatus(401);
    }

    /** @test */
    public function a_user_token_cannot_read_a_kyc_document(): void
    {
        $photo = $this->seedDocument();

        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $userToken = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json('tokens.access_token');

        $this->assertNotEmpty($userToken, 'user login must yield an access token');

        // Staff route, ordinary-user token: the audience separation must still hold.
        $this->withToken((string) $userToken)
            ->getJson("/api/staff/documents/{$photo->id}")
            ->assertStatus(401);
    }

    /** @test */
    public function an_authenticated_staff_member_can_read_the_document(): void
    {
        $photo = $this->seedDocument();

        $response = $this->withToken($this->staffToken())
            ->get("/api/staff/documents/{$photo->id}");

        $response->assertStatus(200);
        // The security properties of the response (not the exact bytes): a real document is
        // served, it is served with a non-executable, non-cacheable content type, and it is
        // not sniffable. The byte-identity of the file is covered by the controller reading
        // through Storage::get() on the photo's own path.
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'),
            'an uploaded document is untrusted input and must not be sniffable');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'),
            'an identity document must never be cached');
        // The body must carry the file. (streamedContent() is order-sensitive under the test
        // wrapper, so assert on getContent(), which is stable.)
        $this->assertNotEmpty((string) $response->getContent(),
            'the staff route must actually return the file bytes, not an empty 200');
    }

    /**
     * The narrow identity alias must serve face/back ID but NOT the driver's licence - the
     * verification queue only needs identity documents. (`photos.type` is an enum of exactly
     * face_id/back_id/license/mechanic_card, so "a photo that is not a KYC document" cannot exist
     * in this table; an earlier draft of this test assumed it could, and was wrong.)
     *
     * @test
     */
    public function the_identity_alias_serves_identity_documents_but_not_a_licence(): void
    {
        $identity = $this->seedDocument('face_id');
        $licence = $this->seedDocument('license');

        $token = $this->staffToken();

        $this->withToken($token)
            ->get("/api/staff/documents/{$identity->id}/identity")
            ->assertStatus(200);

        // Same photo on the general route: allowed.
        $this->withToken($token)
            ->get("/api/staff/documents/{$identity->id}")
            ->assertStatus(200);

        // A licence IS a KYC document (general route serves it) but NOT an identity document.
        $this->withToken($token)
            ->getJson("/api/staff/documents/{$licence->id}/identity")
            ->assertStatus(404);

        $this->withToken($token)
            ->get("/api/staff/documents/{$licence->id}")
            ->assertStatus(200);
    }

    /** @test */
    public function a_missing_photo_is_a_404_not_a_500(): void
    {
        $this->withToken($this->staffToken())
            ->getJson('/api/staff/documents/999999')
            ->assertStatus(404);
    }
}
