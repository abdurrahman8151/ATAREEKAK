<?php

namespace Tests\Feature\Review;

use App\Models\Photo;
use App\Models\Profile;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RV-01 (Review R2) â€” KYC identity documents must not leak to other users.
 *
 * The defect: `ProfileController::formatProfileData()` accepted `$isOwner` and
 * then ignored it, so `GET /api/profile/{anyUserId}` returned every other
 * user's face/back ID and licence scan URLs to any authenticated caller, and
 * `GET /api/profile/verify/status/{anyUserId}` had no ownership check at all.
 *
 * These are RECORDING tests for the authorization half of RV-01. The storage
 * half (private disk + signed/staff-streamed access, so the URLs are not public
 * at all) is deliberately NOT included here: staff KYC review currently reads
 * those documents through the public URLs, so moving them is a product
 * decision (owner) recorded in the audit, not something to land blind.
 */
class KycDocumentExposureTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return app(JwtService::class)->generateTokenPair($user)['access_token'];
    }

    /** Give $user one stored document of the given type. */
    private function attachDocument(User $user, string $type = 'face_id'): Photo
    {
        return Photo::create([
            'user_id' => $user->id,
            'type' => $type,
            'path' => "verifications/{$type}/doc.png",
        ]);
    }

    public function test_another_user_cannot_see_the_kyc_documents_of_someone_else(): void
    {
        $victim = User::factory()->create();
        Profile::create(['user_id' => $victim->id, 'full_name' => 'Victim']);
        $this->attachDocument($victim, 'face_id');
        $this->attachDocument($victim, 'license');

        $attacker = User::factory()->create();

        $response = $this->withToken($this->token($attacker))
            ->getJson("/api/profile/{$victim->id}");

        $response->assertOk();

        $body = $response->json();
        $flat = json_encode($body);

        $this->assertStringNotContainsString(
            'face_id_pic', $flat,
            'RV-01: a non-owner must not receive another user\'s document fields'
        );
        $this->assertStringNotContainsString(
            'license_pic', $flat,
            'RV-01: a non-owner must not receive another user\'s licence field'
        );
    }

    public function test_the_owner_still_sees_their_own_documents(): void
    {
        $owner = User::factory()->create();
        Profile::create(['user_id' => $owner->id, 'full_name' => 'Owner']);
        $this->attachDocument($owner, 'face_id');

        $response = $this->withToken($this->token($owner))
            ->getJson("/api/profile/{$owner->id}");

        $response->assertOk();
        $this->assertStringContainsString(
            'face_id_pic',
            (string) json_encode($response->json()),
            'RV-01: the fix must not remove the owner\'s own document access'
        );
    }

    public function test_verification_status_of_another_user_is_forbidden(): void
    {
        $victim = User::factory()->create();
        $this->attachDocument($victim, 'face_id');

        $attacker = User::factory()->create();

        $response = $this->withToken($this->token($attacker))
            ->getJson("/api/profile/verify/status/{$victim->id}");

        $this->assertSame(403, $response->status(), 'RV-01: status() must refuse a non-owner');
        $this->assertStringNotContainsString(
            'documents', (string) json_encode($response->json()),
            'RV-01: a denied caller must not receive document URLs'
        );
    }

    public function test_status_of_a_nonexistent_user_is_404_not_500(): void
    {
        $caller = User::factory()->create();

        $response = $this->withToken($this->token($caller))
            ->getJson('/api/profile/verify/status/99999');

        $this->assertSame(404, $response->status(), 'RV-01: unknown user must be 404, not a findOrFail 500');
    }

    public function test_owner_can_still_read_their_own_verification_status(): void
    {
        $owner = User::factory()->create();
        $this->attachDocument($owner, 'face_id');

        $response = $this->withToken($this->token($owner))
            ->getJson("/api/profile/verify/status/{$owner->id}");

        $response->assertOk();
        $this->assertStringContainsString(
            'documents', (string) json_encode($response->json()),
            'RV-01: the owner keeps access to their own verification status'
        );
    }
}
