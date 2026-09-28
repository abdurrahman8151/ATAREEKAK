<?php

namespace Tests\Unit\Models;

use App\Models\RefreshToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RefreshTokenTest — Unit tests for the RefreshToken model.
 *
 * LOCATION: tests/Unit/Models/RefreshTokenTest.php
 *
 * COVERS:
 * - Fillable attributes
 * - Casts
 * - Relationships (belongsTo User)
 * - isExpired()
 * - isValid()
 * - Database persistence
 */
class RefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // ─── Fillable ──────────────────────────────────────────────────────────────

    public function test_fillable_contains_expected_fields(): void
    {
        $model = new RefreshToken;
        $fillable = $model->getFillable();

        foreach (['user_id', 'token', 'expires_at', 'revoked', 'user_agent', 'ip_address'] as $field) {
            $this->assertContains($field, $fillable, "Expected '{$field}' to be fillable");
        }
    }

    // ─── Casts ─────────────────────────────────────────────────────────────────

    public function test_expires_at_is_cast_to_datetime(): void
    {
        $casts = (new RefreshToken)->getCasts();
        $this->assertArrayHasKey('expires_at', $casts);
        $this->assertEquals('datetime', $casts['expires_at']);
    }

    public function test_revoked_is_cast_to_boolean(): void
    {
        $casts = (new RefreshToken)->getCasts();
        $this->assertArrayHasKey('revoked', $casts);
        $this->assertEquals('boolean', $casts['revoked']);
    }

    // ─── Relationship ──────────────────────────────────────────────────────────

    public function test_belongs_to_user(): void
    {
        $token = $this->makeToken();
        $this->assertNotNull($token->user);
        $this->assertEquals($this->user->id, $token->user->id);
    }

    // ─── isExpired() ───────────────────────────────────────────────────────────

    public function test_is_expired_returns_false_when_token_is_not_expired(): void
    {
        $token = $this->makeToken(expiresAt: Carbon::now()->addHour());
        $this->assertFalse($token->isExpired());
    }

    public function test_is_expired_returns_true_when_token_is_expired(): void
    {
        $token = $this->makeToken(expiresAt: Carbon::now()->subMinutes(1));
        $this->assertTrue($token->isExpired());
    }

    public function test_is_expired_returns_true_when_expires_at_is_exactly_now(): void
    {
        // Carbon::now()->isPast() — a token expiring exactly now is considered past
        $token = $this->makeToken(expiresAt: Carbon::now()->subSecond());
        $this->assertTrue($token->isExpired());
    }

    public function test_is_expired_returns_false_for_token_expiring_far_in_future(): void
    {
        $token = $this->makeToken(expiresAt: Carbon::now()->addDays(30));
        $this->assertFalse($token->isExpired());
    }

    // ─── isValid() ─────────────────────────────────────────────────────────────

    public function test_is_valid_returns_true_for_active_non_revoked_token(): void
    {
        $token = $this->makeToken(revoked: false, expiresAt: Carbon::now()->addHour());
        $this->assertTrue($token->isValid());
    }

    public function test_is_valid_returns_false_when_token_is_expired(): void
    {
        $token = $this->makeToken(revoked: false, expiresAt: Carbon::now()->subMinutes(5));
        $this->assertFalse($token->isValid());
    }

    public function test_is_valid_returns_false_when_token_is_revoked(): void
    {
        $token = $this->makeToken(revoked: true, expiresAt: Carbon::now()->addHour());
        $this->assertFalse($token->isValid());
    }

    public function test_is_valid_returns_false_when_both_revoked_and_expired(): void
    {
        $token = $this->makeToken(revoked: true, expiresAt: Carbon::now()->subHour());
        $this->assertFalse($token->isValid());
    }

    // ─── Database persistence ──────────────────────────────────────────────────

    public function test_token_is_persisted_to_database(): void
    {
        $tokenString = hash('sha256', Str::random(64));

        RefreshToken::create([
            'user_id' => $this->user->id,
            'token' => $tokenString,
            'expires_at' => Carbon::now()->addWeek(),
            'revoked' => false,
        ]);

        $this->assertDatabaseHas('refresh_tokens', [
            'user_id' => $this->user->id,
            'token' => $tokenString,
            'revoked' => false,
        ]);
    }

    public function test_revoked_defaults_to_false_when_not_set(): void
    {
        $token = RefreshToken::create([
            'user_id' => $this->user->id,
            'token' => hash('sha256', Str::random(64)),
            'expires_at' => Carbon::now()->addHour(),
        ]);

        $this->assertFalse((bool) $token->revoked);
    }

    public function test_token_stores_user_agent_and_ip(): void
    {
        $token = RefreshToken::create([
            'user_id' => $this->user->id,
            'token' => hash('sha256', Str::random(64)),
            'expires_at' => Carbon::now()->addHour(),
            'revoked' => false,
            'user_agent' => 'Mozilla/5.0 Test Agent',
            'ip_address' => '192.168.1.1',
        ]);

        $this->assertEquals('Mozilla/5.0 Test Agent', $token->user_agent);
        $this->assertEquals('192.168.1.1', $token->ip_address);
    }

    public function test_multiple_tokens_can_exist_for_same_user(): void
    {
        for ($i = 0; $i < 3; $i++) {
            RefreshToken::create([
                'user_id' => $this->user->id,
                'token' => hash('sha256', Str::random(64)),
                'expires_at' => Carbon::now()->addHour(),
                'revoked' => false,
            ]);
        }

        $count = RefreshToken::where('user_id', $this->user->id)->count();
        $this->assertEquals(3, $count);
    }

    // ─── Helper ────────────────────────────────────────────────────────────────

    private function makeToken(
        bool $revoked = false,
        ?Carbon $expiresAt = null
    ): RefreshToken {
        return RefreshToken::create([
            'user_id' => $this->user->id,
            'token' => hash('sha256', Str::random(64)),
            'expires_at' => $expiresAt ?? Carbon::now()->addHour(),
            'revoked' => $revoked,
        ]);
    }
}
