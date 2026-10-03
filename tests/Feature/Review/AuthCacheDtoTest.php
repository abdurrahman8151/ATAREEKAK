<?php

namespace Tests\Feature\Review;

use App\DTOs\Auth\CachedUser;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Decision un11 (owner, 2026-10-02): the auth cache must not hold a password hash.
 *
 * THE FINDING (R2 sec 30 item 3 / RV-29). `JwtService::findUserCached()` cached the whole `User`
 * model. `Cache::remember` serialises whatever it is given, so every bcrypt hash for every active
 * user was written into Redis in plain serialized form on every cache miss — a credential at rest
 * in the cache backend, for the 5-minute TTL, reachable by anything that can read the cache.
 *
 * THE GATE THAT HAD TO BE CLEARED FIRST (R2 sec 30: "the full `$request->user()->password`
 * consumer set cannot be bounded from here"). It IS bounded: grepping every `->password` in app/
 * gives 8 hits — 5 are the separate `Employee` model, 1 is `$request->password` (an input, not a
 * model), and 2 (`LoginController`, `ResetPasswordController`) operate on a FRESHLY queried User,
 * never the cached one. Zero consumers read the password off the request user. That is why the
 * fix is safe to make rather than why it is dangerous to try.
 *
 * These tests assert the cache payload, not the behaviour around it.
 */
class AuthCacheDtoTest extends TestCase
{
    use RefreshDatabase;

    private function seedUser(): User
    {
        $user = User::factory()->create([
            'password' => bcrypt('a-known-password'),
            'status' => 1,
        ]);

        return $user;
    }

    /** @test */
    public function the_auth_cache_payload_contains_no_password_hash(): void
    {
        $user = $this->seedUser();
        $hash = (string) $user->password;

        app(JwtService::class)->findUserCached($user->id);

        // Read the RAW cache entry, exactly as Redis would hold it.
        $payload = Cache::get("auth.user.{$user->id}");

        $this->assertNotNull($payload, 'the auth cache must have been populated');

        $serialized = serialize($payload);

        $this->assertStringNotContainsString(
            $hash,
            $serialized,
            'THE VULNERABILITY: the bcrypt password hash must never reach the cache backend'
        );
        $this->assertStringNotContainsString('a-known-password', $serialized);
        $this->assertStringNotContainsString(
            "'password'",
            $serialized,
            'no password key may exist in the cached payload at all, not even a null one'
        );
    }

    /** @test */
    public function the_cached_dto_still_carries_everything_the_app_actually_reads(): void
    {
        $user = $this->seedUser();

        $dto = CachedUser::fromModel(User::with('profile')->find($user->id));

        $this->assertNotNull($dto);
        // The middleware and controllers read all of these off $request->user().
        foreach (['id', 'status', 'token_version', 'is_verified_driver', 'is_verified_passenger', 'ban_type', 'ban_expires_at'] as $field) {
            $this->assertArrayHasKey($field, $dto->attributes,
                "the auth cache DTO must still carry `{$field}` or the app breaks");
        }

        $this->assertArrayNotHasKey('password', $dto->attributes);
    }

    /** @test */
    public function the_rebuilt_model_serves_the_request_unchanged(): void
    {
        $user = $this->seedUser();

        $rebuilt = app(JwtService::class)->findUserCached($user->id);

        $this->assertNotNull($rebuilt);
        $this->assertTrue($rebuilt->exists);
        $this->assertSame((int) $user->id, (int) $rebuilt->id);
        $this->assertSame((int) $user->status, (int) $rebuilt->status);
        $this->assertSame((int) $user->token_version, (int) $rebuilt->token_version);
        $this->assertSame((bool) $user->is_verified_driver, (bool) $rebuilt->is_verified_driver);

        // The profile relation must be READY (not lazy), because the armed lazy-loading guard
        // rejects a lazy read and several controllers read $request->user()->profile.
        $this->assertTrue($rebuilt->relationLoaded('profile'),
            'profile must arrive pre-loaded from the cache, never lazily');
    }

    /** @test */
    public function a_deleted_user_still_answers_cleanly_rather_than_throwing(): void
    {
        // Before the refactor a missing user made `find()` return null and the middleware answered
        // 401 USER_NOT_FOUND. That must not become a 500.
        $this->assertNull(
            app(JwtService::class)->findUserCached(999999),
            'an unknown user id must resolve to null so the middleware can 401 cleanly'
        );
    }

    /** @test */
    public function the_profile_relation_is_present_and_null_safe(): void
    {
        $user = $this->seedUser();
        $user->profile()->delete();

        $rebuilt = app(JwtService::class)->findUserCached($user->id);

        $this->assertNotNull($rebuilt);
        $this->assertTrue($rebuilt->relationLoaded('profile'));
        $this->assertNull($rebuilt->profile, 'a user with no profile must read null, not lazy-load');
    }
}
