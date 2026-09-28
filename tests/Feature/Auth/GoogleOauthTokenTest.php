<?php

namespace Tests\Feature\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * T2-9 regression — the Google OAuth callback must issue a credential the API
 * actually accepts.
 *
 * Before the fix the callback returned $user->createToken(...)->plainTextToken,
 * a SANCTUM personal-access token. Every protected endpoint is guarded by the
 * custom `jwt` middleware (Kernel alias -> JwtAuthMiddleware), which decodes
 * with JwtService and requires a `type === 'access'` claim. A Sanctum token has
 * none of that, so the returned string was accepted by NO route: Google sign-in
 * was broken end-to-end and the failure was silent (a token was returned; only
 * the next request 401'd). It also wrote an orphaned personal_access_tokens row.
 *
 * The decisive assertion in this file takes the returned credential and uses it
 * against GET /api/user, which is real `jwt`-protected routing — not a claim
 * about the token's shape.
 */
class GoogleOauthTokenTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE_ID = 'g-1234567890';

    private const EMAIL = 'someone@gmail.com';

    // ── Socialite stubbing (mirrors GoogleControllerTest) ──────────────────────

    private function mockGoogleUser(string $id = self::GOOGLE_ID, string $email = self::EMAIL): void
    {
        $su = Mockery::mock(SocialiteUser::class);
        $su->shouldReceive('getId')->andReturn($id);
        $su->shouldReceive('getEmail')->andReturn($email);
        $su->shouldReceive('getName')->andReturn('Someone Famous');
        $su->shouldReceive('getAvatar')->andReturn('https://example.com/a.jpg');
        $su->user = ['given_name' => 'Someone', 'family_name' => 'Famous'];

        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('setHttpClient')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($su);

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    // ── the token the callback returns actually authenticates ──────────────────

    public function test_callback_token_authenticates_a_jwt_protected_route(): void
    {
        $this->mockGoogleUser();

        $response = $this->get('/auth/google/callback')->assertStatus(200);

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        // The bug: this returned 401 TOKEN_INVALID pre-fix, because the
        // credential was a Sanctum token the jwt middleware cannot decode.
        $this->getJson('/api/user', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(200)
            ->assertJsonPath('user.email', self::EMAIL);
    }

    public function test_tokens_block_is_returned_in_the_login_controller_shape(): void
    {
        $this->mockGoogleUser();

        $this->get('/auth/google/callback')
            ->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'token_type',
                'tokens' => [
                    'access_token',
                    'access_token_expires_at',
                    'refresh_token',
                    'refresh_token_expires_at',
                    'token_type',
                ],
            ]);
    }

    public function test_the_flat_token_key_is_preserved_for_backward_compatibility(): void
    {
        $this->mockGoogleUser();

        $r = $this->get('/auth/google/callback')->assertStatus(200);

        // An out-of-repo client may still read `token`; it must remain present
        // and must now equal a working access token rather than a dead one.
        $this->assertSame($r->json('token'), $r->json('tokens.access_token'));
        $this->assertSame('Bearer', $r->json('token_type'));
    }

    public function test_callback_no_longer_creates_a_sanctum_personal_access_token(): void
    {
        $this->mockGoogleUser();
        $this->get('/auth/google/callback')->assertStatus(200);

        $this->assertSame(
            0,
            \DB::table('personal_access_tokens')->count(),
            'The callback must not write orphaned personal_access_tokens rows.'
        );
    }

    public function test_callback_returns_a_refresh_token_and_persists_its_row(): void
    {
        $this->mockGoogleUser();
        $r = $this->get('/auth/google/callback')->assertStatus(200);

        $refresh = $r->json('tokens.refresh_token');
        $this->assertNotEmpty($refresh);

        // RefreshToken stores only the SHA-256 digest (the safe half of the
        // two refresh-token systems), so look the row up the same way.
        $this->assertDatabaseHas('refresh_tokens', [
            'token' => hash('sha256', $refresh),
        ]);
    }

    public function test_the_issued_access_token_can_be_refreshed(): void
    {
        $this->mockGoogleUser();
        $refresh = $this->get('/auth/google/callback')->json('tokens.refresh_token');

        $this->postJson('/api/auth/refresh', ['refresh_token' => $refresh])
            ->assertStatus(200)
            ->assertJsonStructure(['tokens' => ['access_token']]);
    }

    // ── denied / edge paths, including the ones this fix newly exposes ─────────

    public function test_a_banned_account_gets_no_credential(): void
    {
        $victim = User::factory()->create([
            'email' => self::EMAIL,
            'status' => -1,
            'ban_type' => 'permanent',
            'ban_reason' => 'Repeated spam behaviour',
        ]);

        $this->mockGoogleUser();

        $r = $this->get('/auth/google/callback')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_BANNED');

        $this->assertNull($r->json('token'), 'No access token may be issued to a banned account.');
        $this->assertNull($r->json('tokens'), 'No token pair may be issued to a banned account.');

        // And it must not have quietly written a durable 7-day refresh row.
        $this->assertSame(
            0,
            RefreshToken::where('user_id', $victim->id)->count(),
            'A banned account must not accumulate refresh-token rows.'
        );
    }

    public function test_a_signed_out_account_is_reactivated_so_its_new_token_works(): void
    {
        // LogoutController leaves status 0 and JwtAuthMiddleware:97 rejects 0,
        // so an OAuth sign-in that did not reactivate would 401 with
        // USER_INACTIVE on the very next request.
        User::factory()->create([
            'email' => self::EMAIL,
            'google_id' => self::GOOGLE_ID,
            'status' => 0,
        ]);

        $this->mockGoogleUser();
        $token = $this->get('/auth/google/callback')->assertStatus(200)->json('token');

        $this->getJson('/api/user', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(200)
            ->assertJsonPath('user.email', self::EMAIL);

        $this->assertSame(1, (int) User::where('email', self::EMAIL)->value('status'));
    }

    public function test_an_existing_user_links_google_id_without_creating_a_duplicate(): void
    {
        $existing = User::factory()->create([
            'email' => self::EMAIL,
            'google_id' => null,
            'email_verified_at' => now(),
            'status' => 1,
        ]);

        $this->mockGoogleUser();
        $token = $this->get('/auth/google/callback')->assertStatus(200)->json('token');

        $this->assertSame(1, User::where('email', self::EMAIL)->count());
        $this->assertSame(self::GOOGLE_ID, $existing->fresh()->google_id);
        $this->getJson('/api/user', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
    }

    // ── a google-created account is immediately usable (the point of the flow) ──

    public function test_a_google_provisioned_user_can_use_protected_endpoints(): void
    {
        $this->mockGoogleUser();
        $r = $this->get('/auth/google/callback')->assertStatus(200);

        $this->getJson('/api/user', ['Authorization' => "Bearer {$r->json('token')}"])
            ->assertStatus(200)
            ->assertJsonPath('user.id', $r->json('user.id'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
