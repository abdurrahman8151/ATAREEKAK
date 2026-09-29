<?php

namespace Tests\Feature\Admin;

use App\Events\UserVerified;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * NationalIdVerificationTest
 *
 * Tests the national-ID uniqueness check that runs during verification approval.
 *
 * Two approval paths:
 *   Staff  → POST /api/staff/verifications/{userId}/approve
 *            Protected by middleware('staff:admin,system_admin')
 *
 *   Admin  → POST /api/admin/verifications/{userId}/approve
 *            Protected by middleware('auth.admin')
 */
class NationalIdVerificationTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // UserVerified is ShouldBroadcastNow; fake it so tests don't need Pusher.
        Event::fake([UserVerified::class]);
    }

    // ── Auth helpers ──────────────────────────────────────────────────────────
    //
    // RV-34: both local helpers are gone; the call sites below now use the
    // shared trait (Tests\Support\Concerns\ActsAsStaff).
    //   - staffToken() re-implemented the Employee + /api/staff/login flow and
    //     is now $this->staffToken(null, 'admin') — same 'admin' role it had.
    //   - adminToken() overrode config('admin.system_admin.email') and minted a
    //     *User* JWT via JwtService. StaffJwtMiddleware no longer accepts user
    //     tokens, so that token could never authenticate; it is now
    //     $this->adminToken(), which mints a real system_admin Employee and logs
    //     in through /api/admin/login with `username`.

    // ── Data helpers ──────────────────────────────────────────────────────────

    /**
     * Create a user with pending verification and a licence photo (→ driver).
     */
    private function pendingDriver(): User
    {
        $user = User::factory()->create([
            'verification_status' => 'pending',
            'status' => 1,
        ]);

        Photo::create([
            'user_id' => $user->id,
            'type' => 'license',
            'path' => 'verifications/license/test.jpg',
        ]);

        return $user;
    }

    private function staffApproveRoute(int $userId): string
    {
        return "/api/staff/verifications/{$userId}/approve";
    }

    private function adminApproveRoute(int $userId): string
    {
        return "/api/admin/verifications/{$userId}/approve";
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /** @test */
    public function staff_approves_verification_with_unique_national_id(): void
    {
        $driver = $this->pendingDriver();

        $response = $this->withToken($this->staffToken(null, 'admin'))
            ->postJson($this->staffApproveRoute($driver->id), [
                'national_id' => 'SY-12345678',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $driver->id,
            'national_id' => 'SY-12345678',
        ]);
    }

    /** @test */
    public function staff_cannot_approve_with_duplicate_national_id(): void
    {
        // Another already-verified user holds this national_id.
        User::factory()->create([
            'national_id' => 'SY-99999999',
            'status' => 1,
        ]);

        $driver = $this->pendingDriver();

        $response = $this->withToken($this->staffToken(null, 'admin'))
            ->postJson($this->staffApproveRoute($driver->id), [
                'national_id' => 'SY-99999999',
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('users', [
            'id' => $driver->id,
            'verification_status' => 'pending',
        ]);
    }

    /** @test */
    public function failed_approval_leaves_pending_status_unchanged(): void
    {
        User::factory()->create([
            'national_id' => 'SY-11111111',
            'status' => 1,
        ]);

        $driver = $this->pendingDriver();

        $this->withToken($this->staffToken(null, 'admin'))
            ->postJson($this->staffApproveRoute($driver->id), [
                'national_id' => 'SY-11111111',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $driver->id,
            'verification_status' => 'pending',
        ]);
    }

    /** @test */
    public function duplicate_check_is_case_insensitive(): void
    {
        // Store in lowercase.
        User::factory()->create([
            'national_id' => 'sy-12345678',
            'status' => 1,
        ]);

        $driver = $this->pendingDriver();

        // Submit in uppercase — MySQL's utf8 collation treats them as equal.
        $response = $this->withToken($this->staffToken(null, 'admin'))
            ->postJson($this->staffApproveRoute($driver->id), [
                'national_id' => 'SY-12345678',
            ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function admin_cannot_approve_with_duplicate_national_id(): void
    {
        User::factory()->create([
            'national_id' => 'SY-12345678',
            'status' => 1,
        ]);

        $driver = $this->pendingDriver();

        $response = $this->withToken($this->adminToken())
            ->postJson($this->adminApproveRoute($driver->id), [
                'national_id' => 'SY-12345678',
            ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function admin_approves_verification_with_unique_national_id(): void
    {
        $driver = $this->pendingDriver();

        $response = $this->withToken($this->adminToken())
            ->postJson($this->adminApproveRoute($driver->id), [
                'national_id' => 'SY-99999999',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $driver->id,
            'national_id' => 'SY-99999999',
        ]);
    }

    /** @test */
    public function approval_without_national_id_fails_validation(): void
    {
        $driver = $this->pendingDriver();

        $response = $this->withToken($this->staffToken(null, 'admin'))
            ->postJson($this->staffApproveRoute($driver->id), []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['national_id']);
    }
}
