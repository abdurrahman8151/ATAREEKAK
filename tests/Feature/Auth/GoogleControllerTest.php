<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * GoogleControllerTest
 *
 * NOTE ON STRATEGY:
 * GoogleController::redirect()  → can be tested without real Google credentials.
 * GoogleController::callback()  → requires mocking the Socialite facade so no
 *                                  real OAuth round-trip happens.
 *
 * SSL verification is disabled in local/testing environments inside the
 * controller, so no extra Guzzle config is needed here.
 */
class GoogleControllerTest extends TestCase
{
    use RefreshDatabase;

    // ─── redirect ──────────────────────────────────────────────────────────

    public function test_redirect_returns_a_redirect_response(): void
    {
        $mockDriver = Mockery::mock(GoogleProvider::class);
        $mockDriver->shouldReceive('redirect')
            ->once()
            ->andReturn(redirect('https://accounts.google.com/o/oauth2/auth?mock=1'));

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($mockDriver);

        $response = $this->get('/auth/google/redirect');

        $this->assertContains($response->status(), [301, 302]);
    }

    // ─── callback ──────────────────────────────────────────────────────────

    public function test_callback_creates_new_user_and_returns_token(): void
    {
        $socialiteUser = $this->mockSocialiteUser(
            '12345678901',
            'newuser@gmail.com',
            'New',
            'User'
        );

        $this->mockSocialiteCallback($socialiteUser);

        $response = $this->get('/auth/google/callback');

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.email', 'newuser@gmail.com');

        $this->assertDatabaseHas('users', ['email' => 'newuser@gmail.com']);
    }

    public function test_callback_links_google_id_to_existing_user_with_same_email(): void
    {
        $existing = User::factory()->create([
            'email' => 'existing@gmail.com',
            'google_id' => null,
        ]);

        $socialiteUser = $this->mockSocialiteUser('99999', 'existing@gmail.com', 'Existing', 'User');
        $this->mockSocialiteCallback($socialiteUser);

        $this->get('/auth/google/callback')->assertStatus(200);

        $this->assertEquals($existing->id, User::where('email', 'existing@gmail.com')->first()->id);
    }

    public function test_callback_returns_existing_user_when_google_id_matches(): void
    {
        User::factory()->create([
            'email' => 'returning@gmail.com',
            'google_id' => 'known_google_id_123',
        ]);

        $socialiteUser = $this->mockSocialiteUser('known_google_id_123', 'returning@gmail.com', 'Returning', 'User');
        $this->mockSocialiteCallback($socialiteUser);

        $this->get('/auth/google/callback')->assertStatus(200);

        // No duplicate created
        $this->assertEquals(1, User::where('email', 'returning@gmail.com')->count());
    }

    public function test_callback_response_includes_bearer_token(): void
    {
        $socialiteUser = $this->mockSocialiteUser('777', 'bearer@gmail.com', 'Bearer', 'User');
        $this->mockSocialiteCallback($socialiteUser);

        $response = $this->get('/auth/google/callback');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('token'));
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function mockSocialiteUser(
        string $id,
        string $email,
        string $firstName,
        string $lastName
    ): SocialiteUser {
        $user = Mockery::mock(SocialiteUser::class);
        $user->shouldReceive('getId')->andReturn($id);
        $user->shouldReceive('getEmail')->andReturn($email);
        $user->shouldReceive('getName')->andReturn("{$firstName} {$lastName}");
        $user->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.jpg');
        $user->user = [
            'given_name' => $firstName,
            'family_name' => $lastName,
        ];

        return $user;
    }

    private function mockSocialiteCallback(SocialiteUser $socialiteUser): void
    {
        $mockDriver = Mockery::mock(GoogleProvider::class);
        $mockDriver->shouldReceive('setHttpClient')->andReturnSelf();
        $mockDriver->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($mockDriver);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
