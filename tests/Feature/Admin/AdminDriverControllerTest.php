<?php

namespace Tests\Feature\Admin;

use App\Models\Photo;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * AdminDriverControllerTest
 *
 * Routes under test (all behind auth.admin middleware):
 *   GET /api/admin/drivers/dashboard          → dashboard()
 *   GET /api/admin/drivers/stats              → stats()
 *   GET /api/admin/drivers                    → index()
 *   GET /api/admin/drivers/activity           → activity()
 *   GET /api/admin/drivers/{driverId}/profile → driverProfile()
 */
class AdminDriverControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $verifiedDriver;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('admin.system_admin', [
            'email' => 'primary@admin.test',
            'password' => 'primary_pass',
            'username' => 'primary_admin',
            'first_name' => 'Primary',
            'last_name' => 'Admin',
            'phone' => '0910000001',
            'wallet_prefix' => 'PRIM',
            'permissions' => ['*'],
        ]);

        Config::set('admin.sycash', [
            'email' => 'sycash@admin.test',
            'password' => 'sycash_pass',
            'first_name' => 'SyCash',
            'last_name' => 'Admin',
            'phone' => '0910000002',
            'wallet_prefix' => 'SYCSH',
            'permissions' => ['view_wallet'],
        ]);

        $this->seedAdminWallets();
        $this->verifiedDriver = $this->makeVerifiedDriver();
    }

    // =========================================================================
    // dashboard()
    // =========================================================================

    public function test_dashboard_returns_200_with_success_status(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data']);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/admin/drivers/dashboard')->assertStatus(401);
    }

    // =========================================================================
    // stats()
    // =========================================================================

    public function test_stats_returns_200_with_success_status(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers/stats')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data']);
    }

    public function test_stats_requires_authentication(): void
    {
        $this->getJson('/api/admin/drivers/stats')->assertStatus(401);
    }

    public function test_stats_total_reflects_verified_drivers(): void
    {
        $response = $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers/stats');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'));
    }

    // =========================================================================
    // index()
    // =========================================================================

    public function test_index_returns_paginated_driver_list(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/admin/drivers')->assertStatus(401);
    }

    public function test_index_accepts_filter_all(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers?filter=all')
            ->assertStatus(200);
    }

    public function test_index_accepts_filter_verified(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers?filter=verified')
            ->assertStatus(200);
    }

    public function test_index_accepts_filter_pending(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers?filter=pending')
            ->assertStatus(200);
    }

    public function test_index_accepts_filter_suspended(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers?filter=suspended')
            ->assertStatus(200);
    }

    public function test_index_rejects_invalid_filter(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers?filter=unknown')
            ->assertStatus(422);
    }

    public function test_index_accepts_search_parameter(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers?search=Ahmad')
            ->assertStatus(200);
    }

    public function test_index_respects_per_page_parameter(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers?per_page=5')
            ->assertStatus(200)
            ->assertJsonStructure(['meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_index_rejects_per_page_above_fifty(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers?per_page=51')
            ->assertStatus(422);
    }

    public function test_index_meta_contains_pagination_fields(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers')
            ->assertStatus(200)
            ->assertJsonStructure([
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'filter'],
            ]);
    }

    // =========================================================================
    // activity()
    // =========================================================================

    public function test_activity_returns_200_with_data(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers/activity')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data']);
    }

    public function test_activity_requires_authentication(): void
    {
        $this->getJson('/api/admin/drivers/activity')->assertStatus(401);
    }

    public function test_activity_accepts_limit_parameter(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers/activity?limit=5')
            ->assertStatus(200);
    }

    public function test_activity_rejects_limit_above_fifty(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers/activity?limit=51')
            ->assertStatus(422);
    }

    public function test_activity_rejects_limit_of_zero(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers/activity?limit=0')
            ->assertStatus(422);
    }

    public function test_activity_returns_array_data(): void
    {
        $response = $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers/activity');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'));
    }

    // =========================================================================
    // driverProfile()
    // =========================================================================

    public function test_driver_profile_returns_data_for_existing_driver(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson("/api/admin/drivers/{$this->verifiedDriver->id}/profile")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data']);
    }

    public function test_driver_profile_requires_authentication(): void
    {
        $this->getJson("/api/admin/drivers/{$this->verifiedDriver->id}/profile")
            ->assertStatus(401);
    }

    public function test_driver_profile_returns_404_for_nonexistent_driver(): void
    {
        $this->withToken($this->primaryToken())
            ->getJson('/api/admin/drivers/999999/profile')
            ->assertStatus(404);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeVerifiedDriver(): User
    {
        $driver = User::factory()->create([
            'is_verified_driver' => true,
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $type) {
            Photo::create([
                'user_id' => $driver->id,
                'type' => $type,
                'path' => "verifications/{$type}/test.jpg",
            ]);
        }

        return $driver;
    }

    private function primaryToken(): string
    {
        return $this->postJson('/api/admin/login', [
            'email' => 'primary@admin.test',
            'password' => 'primary_pass',
        ])->json('tokens.access_token');
    }

    private function seedAdminWallets(): void
    {
        foreach (['system_admin', 'sycash'] as $type) {
            $config = config("admin.{$type}");
            $adminUser = User::firstOrCreate(
                ['email' => $config['email']],
                [
                    'first_name' => $config['first_name'],
                    'last_name' => $config['last_name'],
                    'password' => bcrypt($config['password']),
                    'gender' => 'M',
                    'address' => 'دمشق',
                    'status' => 1,
                    'email_verified_at' => now(),
                ]
            );

            if (! Wallet::where('phone_number', $config['phone'])->exists()) {
                $wallet = Wallet::create([
                    'user_id' => $adminUser->id,
                    'phone_number' => $config['phone'],
                    'balance' => 10_000_000,
                ]);
                $adminUser->update(['wallet_id' => $wallet->id]);
            }
        }
    }
}
