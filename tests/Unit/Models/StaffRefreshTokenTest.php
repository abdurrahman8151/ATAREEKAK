<?php

namespace Tests\Unit\Models;

use App\Models\Employee;
use App\Models\StaffRefreshToken;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * StaffRefreshTokenTest — Unit tests for the StaffRefreshToken model.
 *
 * COVERS:
 * - Fillable attributes
 * - Casts (expires_at → datetime, revoked → boolean)
 * - Relationship (belongsTo Employee)
 * - isExpired()
 * - isValid()
 * - Database persistence
 */
class StaffRefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = Employee::create([
            'username' => 'staff_token_tester',
            'email' => 'staff_token@test.com',
            'password' => bcrypt('password123'),
            'first_name' => 'Staff',
            'last_name' => 'Token',
            'role' => 'support_agent',
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    // ─── Fillable ─────────────────────────────────────────────────────────────────

    public function test_fillable_contains_expected_fields(): void
    {
        $fillable = (new StaffRefreshToken)->getFillable();

        foreach (['employee_id', 'token', 'expires_at', 'revoked', 'user_agent', 'ip_address'] as $field) {
            $this->assertContains($field, $fillable, "Expected '{$field}' to be fillable");
        }
    }

    // ─── Casts ────────────────────────────────────────────────────────────────────

    public function test_expires_at_is_cast_to_datetime(): void
    {
        $casts = (new StaffRefreshToken)->getCasts();
        $this->assertArrayHasKey('expires_at', $casts);
        $this->assertEquals('datetime', $casts['expires_at']);
    }

    public function test_revoked_is_cast_to_boolean(): void
    {
        $casts = (new StaffRefreshToken)->getCasts();
        $this->assertArrayHasKey('revoked', $casts);
        $this->assertEquals('boolean', $casts['revoked']);
    }

    // ─── Relationship ─────────────────────────────────────────────────────────────

    public function test_belongs_to_employee(): void
    {
        $token = $this->makeToken();
        $this->assertNotNull($token->employee);
        $this->assertEquals($this->employee->id, $token->employee->id);
    }

    // ─── isExpired() ──────────────────────────────────────────────────────────────

    public function test_is_expired_returns_false_when_token_is_not_expired(): void
    {
        $token = $this->makeToken(expiresAt: Carbon::now()->addHour());
        $this->assertFalse($token->isExpired());
    }

    public function test_is_expired_returns_true_when_token_is_past(): void
    {
        $token = $this->makeToken(expiresAt: Carbon::now()->subMinutes(1));
        $this->assertTrue($token->isExpired());
    }

    public function test_is_expired_returns_false_for_far_future_token(): void
    {
        $token = $this->makeToken(expiresAt: Carbon::now()->addDays(30));
        $this->assertFalse($token->isExpired());
    }

    public function test_is_expired_returns_true_when_expired_by_one_second(): void
    {
        $token = $this->makeToken(expiresAt: Carbon::now()->subSecond());
        $this->assertTrue($token->isExpired());
    }

    // ─── isValid() ────────────────────────────────────────────────────────────────

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

    // ─── Database persistence ──────────────────────────────────────────────────────

    public function test_token_persists_to_database(): void
    {
        $tokenString = hash('sha256', Str::random(64));

        StaffRefreshToken::create([
            'employee_id' => $this->employee->id,
            'token' => $tokenString,
            'expires_at' => Carbon::now()->addWeek(),
            'revoked' => false,
        ]);

        $this->assertDatabaseHas('staff_refresh_tokens', [
            'employee_id' => $this->employee->id,
            'token' => $tokenString,
            'revoked' => false,
        ]);
    }

    public function test_revoked_defaults_to_false_when_not_provided(): void
    {
        $token = StaffRefreshToken::create([
            'employee_id' => $this->employee->id,
            'token' => hash('sha256', Str::random(64)),
            'expires_at' => Carbon::now()->addHour(),
        ]);

        $this->assertFalse((bool) $token->revoked);
    }

    public function test_stores_user_agent_and_ip_address(): void
    {
        $token = StaffRefreshToken::create([
            'employee_id' => $this->employee->id,
            'token' => hash('sha256', Str::random(64)),
            'expires_at' => Carbon::now()->addHour(),
            'revoked' => false,
            'user_agent' => 'Mozilla/5.0 Staff Browser',
            'ip_address' => '10.0.0.1',
        ]);

        $this->assertEquals('Mozilla/5.0 Staff Browser', $token->user_agent);
        $this->assertEquals('10.0.0.1', $token->ip_address);
    }

    public function test_multiple_tokens_can_exist_for_same_employee(): void
    {
        for ($i = 0; $i < 3; $i++) {
            StaffRefreshToken::create([
                'employee_id' => $this->employee->id,
                'token' => hash('sha256', Str::random(64)),
                'expires_at' => Carbon::now()->addHour(),
                'revoked' => false,
            ]);
        }

        $this->assertEquals(
            3,
            StaffRefreshToken::where('employee_id', $this->employee->id)->count()
        );
    }

    // ─── Helper ───────────────────────────────────────────────────────────────────

    private function makeToken(bool $revoked = false, ?Carbon $expiresAt = null): StaffRefreshToken
    {
        return StaffRefreshToken::create([
            'employee_id' => $this->employee->id,
            'token' => hash('sha256', Str::random(64)),
            'expires_at' => $expiresAt ?? Carbon::now()->addHour(),
            'revoked' => $revoked,
        ]);
    }
}
