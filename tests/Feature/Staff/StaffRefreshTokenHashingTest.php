<?php

namespace Tests\Feature\Staff;

use App\Enums\StaffRole;
use App\Models\Employee;
use App\Models\StaffRefreshToken;
use App\Services\Staff\StaffJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T2-11 regression — staff/admin refresh tokens must be stored as a SHA-256
 * digest and looked up by digest, mirroring the user path (JwtService), so a
 * database disclosure does not hand over directly usable privileged sessions.
 *
 * Before the fix, StaffJwtService::generateRefreshToken() wrote Str::random(64)
 * VERBATIM and refreshAccessToken() matched it by equality. The user path had
 * always stored hash('sha256', $raw). A leaked staff_refresh_tokens.token was
 * therefore a live 30-day admin credential with nothing to crack.
 *
 * No real credential appears below — only synthetic employee passwords and the
 * runtime-generated tokens, asserted by SHAPE (64-char hex, != raw) never value.
 */
class StaffRefreshTokenHashingTest extends TestCase
{
    use RefreshDatabase;

    private StaffJwtService $service;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(StaffJwtService::class);

        $this->employee = Employee::create([
            'username' => 'hashing_admin',
            'email' => 'hashing_admin@staff.test',
            'password' => bcrypt('hashed-pass-123'),
            'first_name' => 'Hash',
            'last_name' => 'Admin',
            'role' => StaffRole::SYSTEM_ADMIN->value,
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    // ── the stored value is a digest, not the secret ────────────────────────────

    public function test_the_stored_token_is_a_sha256_digest_not_the_raw_value(): void
    {
        $pair = $this->service->generateTokenPair($this->employee);
        $raw = $pair['refresh_token'];

        $stored = StaffRefreshToken::where('employee_id', $this->employee->id)
            ->latest('id')
            ->value('token');

        // The whole point: the DB must not contain the string the client holds.
        $this->assertNotSame($raw, $stored, 'the raw refresh token must never be persisted');
        $this->assertSame(64, strlen($stored), 'a sha256 hex digest is 64 chars');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stored);
        $this->assertSame(hash('sha256', $raw), $stored);

        // And it must NOT be findable by the raw value (only by the digest).
        $this->assertNull(StaffRefreshToken::where('token', $raw)->first());
    }

    public function test_the_client_still_receives_a_usable_raw_token(): void
    {
        $raw = $this->service->generateTokenPair($this->employee)['refresh_token'];

        // 64 chars, not a hex digest — i.e. the client got the pre-image, the DB
        // the digest. They must differ.
        $this->assertSame(64, strlen($raw));
        $this->assertNotSame(hash('sha256', $raw), $raw);
    }

    public function test_a_real_staff_login_stores_only_the_digest(): void
    {
        // End-to-end through the actual endpoint (POST /api/staff/login), not
        // just the service unit, so the production write path is exercised.
        $raw = $this->postJson('/api/staff/login', [
            'identifier' => 'hashing_admin',
            'password' => 'hashed-pass-123',
        ])->assertStatus(200)->json('tokens.refresh_token');

        $this->assertNotEmpty($raw);

        $stored = StaffRefreshToken::where('employee_id', $this->employee->id)
            ->latest('id')
            ->value('token');

        $this->assertNotSame($raw, $stored);
        $this->assertSame(hash('sha256', $raw), $stored);
    }

    // ── the round trip still works ──────────────────────────────────────────────

    public function test_a_refresh_token_presented_in_plaintext_still_refreshes(): void
    {
        $raw = $this->service->generateTokenPair($this->employee)['refresh_token'];

        $new = $this->service->refreshAccessToken($raw);

        $this->assertNotNull($new, 'a valid raw token must resolve via its stored digest');
        $this->assertArrayHasKey('access_token', $new);
        $this->assertArrayHasKey('refresh_token', $new);
    }

    public function test_refresh_rotates_and_revokes_the_consumed_row(): void
    {
        $raw = $this->service->generateTokenPair($this->employee)['refresh_token'];

        $this->service->refreshAccessToken($raw);

        $consumed = StaffRefreshToken::where('token', hash('sha256', $raw))->first();
        $this->assertNotNull($consumed);
        $this->assertTrue((bool) $consumed->revoked, 'the used token must be revoked');
    }

    public function test_a_consumed_refresh_token_cannot_be_replayed(): void
    {
        $raw = $this->service->generateTokenPair($this->employee)['refresh_token'];

        $this->assertNotNull($this->service->refreshAccessToken($raw));
        // Rotation + single-use: the second use must fail.
        $this->assertNull($this->service->refreshAccessToken($raw));
    }

    public function test_an_expired_digest_row_cannot_refresh(): void
    {
        $raw = $this->service->generateTokenPair($this->employee)['refresh_token'];
        StaffRefreshToken::where('token', hash('sha256', $raw))
            ->update(['expires_at' => now()->subDay()]);

        $this->assertNull($this->service->refreshAccessToken($raw));
    }

    public function test_an_inactive_employee_cannot_refresh(): void
    {
        $raw = $this->service->generateTokenPair($this->employee)['refresh_token'];
        $this->employee->update(['is_active' => false]);

        $this->assertNull($this->service->refreshAccessToken($raw));
    }

    public function test_a_garbage_token_returns_null_and_creates_no_row(): void
    {
        $before = StaffRefreshToken::count();

        $this->assertNull($this->service->refreshAccessToken('definitely-not-a-real-token'));
        $this->assertSame($before, StaffRefreshToken::count(), 'a failed refresh must not mint rows');
    }

    // ── revocation still finds rows by employee (unaffected by hashing) ──────────

    public function test_revoke_all_tokens_still_works_after_hashing(): void
    {
        $this->service->generateTokenPair($this->employee);
        $this->service->generateTokenPair($this->employee);

        $this->service->revokeAllTokens($this->employee->id);

        $this->assertSame(
            0,
            StaffRefreshToken::where('employee_id', $this->employee->id)
                ->where('revoked', false)
                ->count()
        );
    }

    // ── the migration purges legacy plaintext rows ───────────────────────────────

    public function test_the_purge_migration_removes_legacy_plaintext_rows(): void
    {
        // Simulate the pre-fix state: a raw (64-char, non-digest) token stored
        // verbatim, as StaffJwtService used to write.
        $legacy = DB::table('staff_refresh_tokens')->insertGetId([
            'employee_id' => $this->employee->id,
            'token' => str_repeat('a', 64), // raw, not a digest
            'expires_at' => now()->addDays(30),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run just this migration's up() against the live scratch DB.
        $migration = require database_path(
            'migrations/2026_09_25_000001_purge_plaintext_staff_refresh_tokens.php'
        );
        $migration->up();

        $this->assertSame(0, StaffRefreshToken::where('employee_id', $this->employee->id)->count());
    }

    public function test_new_tokens_survive_after_the_purge_runs(): void
    {
        // Prove the purge is a one-time invalidation, not a permanent block: the
        // digest-based write path continues to persist usable rows.
        $migration = require database_path(
            'migrations/2026_09_25_000001_purge_plaintext_staff_refresh_tokens.php'
        );
        $migration->up();

        $raw = $this->service->generateTokenPair($this->employee)['refresh_token'];
        $this->assertNotNull($this->service->refreshAccessToken($raw));
    }
}
