<?php

namespace Tests\Feature\Admin;

use App\Models\Employee;
use App\Models\Photo;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // UserVerified is ShouldBroadcastNow; fake it so tests don't need Pusher.
        Event::fake([\App\Events\UserVerified::class]);
    }

    // ── Auth helpers ──────────────────────────────────────────────────────────

    /**
     * Create an Employee with the given role, log in via the staff login
     * endpoint, and return the access token.
     */
    private function staffToken(string $role = 'admin'): string
    {
        $employee = Employee::create([
            'username'      => 'teststaff_' . uniqid(),
            'email'         => 'staff_' . uniqid() . '@test.com',
            'password'      => 'Password123!',
            'first_name'    => 'Test',
            'last_name'     => 'Staff',
            'role'          => $role,
            'is_active'     => true,
            'token_version' => 0,
        ]);

        $response = $this->postJson('/api/staff/login', [
            'identifier' => $employee->username,
            'password'   => 'Password123!',
        ]);

        return $response->json('tokens.access_token');
    }

    /**
     * Override the admin config to a test e-mail, create a matching User in the
     * DB, then mint a JwtService access token for it.
     */
    private function adminToken(): string
    {
        $email = 'testadmin_' . uniqid() . '@sysride.test';

        config(['admin.system_admin.email' => $email]);

        $admin  = User::factory()->create(['email' => $email, 'status' => 1]);
        $tokens = app(JwtService::class)->generateTokenPair($admin);

        return $tokens['access_token'];
    }

    // ── Data helpers ──────────────────────────────────────────────────────────

    /**
     * Create a user with pending verification and a licence photo (→ driver).
     */
    private function pendingDriver(): User
    {
        $user = User::factory()->create([
            'verification_status' => 'pending',
            'status'              => 1,
        ]);

        Photo::create([
            'user_id' => $user->id,
            'type'    => 'license',
            'path'    => 'verifications/license/test.jpg',
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

        $response = $this->withToken($this->staffToken())
            ->postJson($this->staffApproveRoute($driver->id), [
                'national_id' => '1234567890',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'id'          => $driver->id,
            'national_id' => '1234567890',
        ]);
    }

    /** @test */
    public function staff_cannot_approve_with_duplicate_national_id(): void
    {
        // Another already-verified user holds this national_id.
        User::factory()->create([
            'national_id' => '9999999999',
            'status'      => 1,
        ]);

        $driver = $this->pendingDriver();

        $response = $this->withToken($this->staffToken())
            ->postJson($this->staffApproveRoute($driver->id), [
                'national_id' => '9999999999',
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('users', [
            'id'                  => $driver->id,
            'verification_status' => 'pending',
        ]);
    }

    /** @test */
    public function failed_approval_leaves_pending_status_unchanged(): void
    {
        User::factory()->create([
            'national_id' => '1111111111',
            'status'      => 1,
        ]);

        $driver = $this->pendingDriver();

        $this->withToken($this->staffToken())
            ->postJson($this->staffApproveRoute($driver->id), [
                'national_id' => '1111111111',
            ]);

        $this->assertDatabaseHas('users', [
            'id'                  => $driver->id,
            'verification_status' => 'pending',
        ]);
    }

    /** @test */
    public function admin_cannot_approve_with_duplicate_national_id(): void
    {
        User::factory()->create([
            'national_id' => '1234567890',
            'status'      => 1,
        ]);

        $driver = $this->pendingDriver();

        $response = $this->withToken($this->adminToken())
            ->postJson($this->adminApproveRoute($driver->id), [
                'national_id' => '1234567890',
            ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function admin_approves_verification_with_unique_national_id(): void
    {
        $driver = $this->pendingDriver();

        $response = $this->withToken($this->adminToken())
            ->postJson($this->adminApproveRoute($driver->id), [
                'national_id' => '9999999999',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'id'          => $driver->id,
            'national_id' => '9999999999',
        ]);
    }

    /** @test */
    public function national_id_must_be_exactly_10_digits(): void
    {
        $driver = $this->pendingDriver();

        // 9 digits
        $response = $this->withToken($this->staffToken())
            ->postJson($this->staffApproveRoute($driver->id), [
                'national_id' => '123456789',
            ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['national_id']);

        // 11 digits
        $response = $this->withToken($this->staffToken())
            ->postJson($this->staffApproveRoute($driver->id), [
                'national_id' => '12345678901',
            ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['national_id']);
    }

    /** @test */
    public function approval_without_national_id_fails_validation(): void
    {
        $driver = $this->pendingDriver();

        $response = $this->withToken($this->staffToken())
            ->postJson($this->staffApproveRoute($driver->id), []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['national_id']);
    }
}
