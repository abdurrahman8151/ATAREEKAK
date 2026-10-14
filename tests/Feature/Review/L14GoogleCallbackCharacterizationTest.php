<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Services\Auth\GoogleUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * L14 (modular-monolith slice 13, AUTH): characterization of the Google user resolution now owned by
 * App\Services\Auth\GoogleUserResolver (extracted from GoogleController::callback).
 *
 * The end-to-end callback is already covered by Auth/GoogleControllerTest (create, link, match, token shape)
 * and Auth/GoogleOauthTokenTest (ban refusal, no credential). This file pins the three resolution rules directly,
 * so a change to the resolver is caught on its own:
 *   1. a known Google id returns that user and refreshes a changed avatar;
 *   2. an unknown Google id with a known email links the Google id and fills a MISSING avatar only;
 *   3. an unknown Google id and email creates the user from the Google profile, with a verified email.
 */
class L14GoogleCallbackCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private GoogleUserResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(GoogleUserResolver::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_known_google_id_returns_the_same_user(): void
    {
        $user = User::factory()->create(['google_id' => 'g-known-1', 'avatar' => 'https://img/old.png']);

        $resolved = $this->resolver->resolve($this->googleUser('g-known-1', 'someone@gmail.com', 'https://img/old.png'));

        $this->assertSame($user->id, $resolved->id);
    }

    public function test_a_known_google_id_refreshes_a_changed_avatar(): void
    {
        $user = User::factory()->create(['google_id' => 'g-known-2', 'avatar' => 'https://img/old.png']);

        $resolved = $this->resolver->resolve($this->googleUser('g-known-2', $user->email, 'https://img/new.png'));

        $this->assertSame('https://img/new.png', $resolved->fresh()->avatar);
    }

    public function test_an_email_match_links_the_google_id_and_keeps_an_existing_avatar(): void
    {
        $user = User::factory()->create([
            'email' => 'linked@gmail.com',
            'google_id' => null,
            'avatar' => 'https://img/mine.png',
        ]);

        $resolved = $this->resolver->resolve($this->googleUser('g-link-1', 'linked@gmail.com', 'https://img/google.png'));

        $this->assertSame($user->id, $resolved->id);
        $this->assertSame('g-link-1', $user->fresh()->google_id, 'the Google id must be linked to the existing user');
        $this->assertSame('https://img/mine.png', $user->fresh()->avatar, 'an existing avatar must not be overwritten');
    }

    public function test_an_email_match_fills_a_missing_avatar(): void
    {
        $user = User::factory()->create(['email' => 'noavatar@gmail.com', 'google_id' => null, 'avatar' => null]);

        $this->resolver->resolve($this->googleUser('g-link-2', 'noavatar@gmail.com', 'https://img/google.png'));

        $this->assertSame('https://img/google.png', $user->fresh()->avatar);
    }

    public function test_an_unknown_user_is_created_from_the_google_profile(): void
    {
        $resolved = $this->resolver->resolve(
            $this->googleUser('g-new-1', 'newcomer@gmail.com', 'https://img/new.png', 'Lina Haddad')
        );

        $this->assertNotNull($resolved->id);
        $this->assertSame('g-new-1', $resolved->google_id);
        $this->assertSame('newcomer@gmail.com', $resolved->email);
        $this->assertSame('https://img/new.png', $resolved->avatar);
        // GAP (reported, not fixed here): the resolver passes 'email_verified_at' => now(), but
        // UserRepository::createUser() writes a fixed field list and drops it. So a Google-created
        // account currently has NO verified-email timestamp. This pins the real behaviour; changing it
        // alters which accounts count as email-verified, which needs an owner decision.
        $this->assertNull($resolved->email_verified_at, 'current behaviour: createUser drops email_verified_at');
        $this->assertSame(1, (int) $resolved->status);
    }

    /** Build a Socialite-like user. The resolver takes it untyped, exactly as the controller passes it. */
    private function googleUser(string $id, string $email, ?string $avatar, ?string $name = null): object
    {
        $given = $name ? explode(' ', $name, 2)[0] : null;
        $family = $name ? (explode(' ', $name, 2)[1] ?? '') : null;

        return new class($id, $email, $avatar, $name, ['given_name' => $given, 'family_name' => $family])
        {
            public array $user;

            public function __construct(
                private string $id,
                private string $email,
                private ?string $avatar,
                private ?string $name,
                array $user,
            ) {
                $this->user = $user;
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function getEmail(): string
            {
                return $this->email;
            }

            public function getAvatar(): ?string
            {
                return $this->avatar;
            }

            public function getName(): ?string
            {
                return $this->name;
            }
        };
    }
}
