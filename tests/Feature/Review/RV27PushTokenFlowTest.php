<?php

namespace Tests\Feature\Review;

use App\Http\Controllers\API\PushNotificationController;
use App\Models\PushNotificationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * RV-27 — push tokens: the feature must be reachable, type-correct, and ownership-safe.
 *
 * Grounded before fixing (all three were live in current code):
 *  1. ZERO routes on PushNotificationController -> no device could ever register, so FCM
 *     delivery was unreachable end-to-end. The register path also passed $request->user()
 *     (a User model) into PushNotificationService::registerToken(int $userId, ...) — a
 *     TypeError 500 the moment the route existed. Routes + $request->user()->id fix both.
 *  2. IDOR: removeToken deactivated ANY row matching a token string, so any authenticated
 *     user who knew (or guessed) another device's token could unregister it, silently
 *     stopping that user's notifications. The endpoint now goes through
 *     removeTokenForUser(userId, token): foreign tokens answer exactly like missing ones,
 *     so this also stops the endpoint probing which token strings exist.
 *  3. Token strings must never travel in URLs (access logs) — DELETE takes the body.
 */
class RV27PushTokenFlowTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    private function token(User $u): string
    {
        return $this->userToken($u);
    }

    /** @test */
    public function a_device_can_register_and_the_token_is_owned_by_the_caller(): void
    {
        $u = $this->user();

        $r = $this->withToken($this->token($u))->postJson('/api/push-tokens', [
            'token' => 'fcm-token-alice-1',
            'platform' => 'android',
        ]);

        $r->assertStatus(200)->assertJsonPath('success', true);

        $row = PushNotificationToken::where('token', 'fcm-token-alice-1')->first();
        $this->assertNotNull($row, 'the register route must persist the token');
        $this->assertSame($u->id, (int) $row->user_id);
        $this->assertTrue((bool) $row->is_active);
        $this->assertSame('android', $row->device_type);
    }

    /** @test */
    public function registering_the_same_token_again_reassigns_ownership_without_duplicates(): void
    {
        // The manager upserts by token string: a real re-install case. Pin the behaviour
        // so the type fix cannot quietly change it.
        $alice = $this->user();
        $bob = $this->user();

        $this->withToken($this->token($alice))
            ->postJson('/api/push-tokens', ['token' => 'shared-token', 'platform' => 'ios'])
            ->assertStatus(200);

        $this->withToken($this->token($bob))
            ->postJson('/api/push-tokens', ['token' => 'shared-token', 'platform' => 'android'])
            ->assertStatus(200);

        $this->assertSame(
            1,
            PushNotificationToken::where('token', 'shared-token')->count(),
            'one row per token string (upsert), not a duplicate'
        );
        $this->assertSame(
            $bob->id,
            (int) PushNotificationToken::where('token', 'shared-token')->first()->user_id
        );
    }

    /** @test */
    public function the_user_can_list_only_their_own_tokens(): void
    {
        $alice = $this->user();
        $bob = $this->user();

        $this->withToken($this->token($alice))
            ->postJson('/api/push-tokens', ['token' => 'alice-1', 'platform' => 'android'])
            ->assertStatus(200);
        $this->withToken($this->token($bob))
            ->postJson('/api/push-tokens', ['token' => 'bob-1', 'platform' => 'android'])
            ->assertStatus(200);

        $list = $this->withToken($this->token($alice))->getJson('/api/push-tokens');
        $list->assertStatus(200);

        $tokens = array_map(fn ($t) => $t['token'], $list->json('data'));
        $this->assertContains('alice-1', $tokens);
        $this->assertNotContains('bob-1', $tokens, 'listing must be scoped to the caller');
    }

    /** @test */
    public function a_user_can_unregister_their_own_token(): void
    {
        $alice = $this->user();
        $this->withToken($this->token($alice))
            ->postJson('/api/push-tokens', ['token' => 'alice-del', 'platform' => 'web'])
            ->assertStatus(200);

        $this->withToken($this->token($alice))
            ->deleteJson('/api/push-tokens', ['token' => 'alice-del'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $row = PushNotificationToken::where('token', 'alice-del')->first();
        $this->assertNotNull($row, 'deactivation keeps the row (soft)');
        $this->assertFalse((bool) $row->is_active);
    }

    /** @test */
    public function the_idor_is_closed_a_foreign_token_cannot_be_unregistered(): void
    {
        $alice = $this->user();
        $bob = $this->user();

        $this->withToken($this->token($alice))
            ->postJson('/api/push-tokens', ['token' => 'alice-secret-device', 'platform' => 'android'])
            ->assertStatus(200);

        // Bob knows Alice's token string. Pre-fix this deactivated Alice's device (IDOR).
        $r = $this->withToken($this->token($bob))
            ->deleteJson('/api/push-tokens', ['token' => 'alice-secret-device']);

        // Denied with the SAME answer as a nonexistent token (no existence oracle):
        $r->assertStatus(200)->assertJsonPath('success', false);

        $row = PushNotificationToken::where('token', 'alice-secret-device')->first();
        $this->assertTrue((bool) $row->is_active, 'RV-27: a foreign caller must not be able to disable a device');
    }

    /** @test */
    public function unregistering_an_unknown_token_is_not_an_error(): void
    {
        $alice = $this->user();

        $this->withToken($this->token($alice))
            ->deleteJson('/api/push-tokens', ['token' => 'never-existed'])
            ->assertStatus(200)
            ->assertJsonPath('success', false);
    }

    /** @test */
    public function the_endpoints_require_authentication(): void
    {
        $this->postJson('/api/push-tokens', ['token' => 'x', 'platform' => 'android'])
            ->assertStatus(401);
        $this->getJson('/api/push-tokens')->assertStatus(401);
        $this->deleteJson('/api/push-tokens', ['token' => 'x'])->assertStatus(401);
    }

    /** @test */
    public function registration_validates_the_platform(): void
    {
        $alice = $this->user();

        $this->withToken($this->token($alice))
            ->postJson('/api/push-tokens', ['token' => 'x', 'platform' => 'tosaster'])
            ->assertStatus(422);

        $this->withToken($this->token($alice))
            ->postJson('/api/push-tokens', ['platform' => 'android'])   // token missing
            ->assertStatus(422);

        $this->assertSame(0, PushNotificationToken::where('token', 'x')->count(),
            'an invalid payload must not persist anything');
    }

    /** @test */
    public function the_dead_store_duplicate_is_gone(): void
    {
        // RV-27 routed the class; the unrouted store() duplicate (wrong device_type key,
        // drifted from the routed method) is deleted, per R1's RV-27 fix text. Pinning its
        // absence stops it being "recovered" silently.
        $this->assertFalse(
            method_exists(PushNotificationController::class, 'store'),
            'store() was the unrouted drifted duplicate; it must stay deleted'
        );
    }
}
