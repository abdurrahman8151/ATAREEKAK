<?php

namespace Tests\Feature\Verification;

use App\Interfaces\VerificationRepositoryInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerificationRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private VerificationRepositoryInterface $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = app(VerificationRepositoryInterface::class);
    }

    // ── verifyPassenger ───────────────────────────────────────────────────────

    public function test_verify_passenger_sets_is_verified_passenger_true(): void
    {
        $user = User::factory()->create([
            'verification_status' => 'pending',
            'is_verified_passenger' => false,
        ]);

        $result = $this->repo->verifyPassenger($user->id);

        $this->assertTrue((bool) $result->is_verified_passenger);
    }

    public function test_verify_passenger_sets_status_to_approved(): void
    {
        $user = User::factory()->create(['verification_status' => 'pending']);

        $result = $this->repo->verifyPassenger($user->id);

        $this->assertEquals('approved', $result->verification_status);
    }

    public function test_verify_passenger_persists_changes_to_database(): void
    {
        $user = User::factory()->create(['verification_status' => 'pending']);

        $this->repo->verifyPassenger($user->id);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
        ]);
    }

    public function test_verify_passenger_throws_if_status_is_not_pending(): void
    {
        $user = User::factory()->create(['verification_status' => 'none']);

        $this->expectException(\Exception::class);
        $this->repo->verifyPassenger($user->id);
    }

    public function test_verify_passenger_throws_if_status_is_rejected(): void
    {
        $user = User::factory()->create(['verification_status' => 'rejected']);

        $this->expectException(\Exception::class);
        $this->repo->verifyPassenger($user->id);
    }

    // ── verifyDriver ──────────────────────────────────────────────────────────

    public function test_verify_driver_sets_is_verified_driver_true(): void
    {
        $user = User::factory()->create([
            'verification_status' => 'pending',
            'is_verified_driver' => false,
        ]);

        $result = $this->repo->verifyDriver($user->id);

        $this->assertTrue((bool) $result->is_verified_driver);
    }

    public function test_verify_driver_also_sets_is_verified_passenger_true(): void
    {
        $user = User::factory()->create([
            'verification_status' => 'pending',
            'is_verified_passenger' => false,
        ]);

        $result = $this->repo->verifyDriver($user->id);

        $this->assertTrue((bool) $result->is_verified_passenger);
    }

    public function test_verify_driver_sets_status_to_approved(): void
    {
        $user = User::factory()->create(['verification_status' => 'pending']);

        $result = $this->repo->verifyDriver($user->id);

        $this->assertEquals('approved', $result->verification_status);
    }

    public function test_verify_driver_persists_changes_to_database(): void
    {
        $user = User::factory()->create(['verification_status' => 'pending']);

        $this->repo->verifyDriver($user->id);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'is_verified_driver' => true,
            'verification_status' => 'approved',
        ]);
    }

    public function test_verify_driver_throws_if_status_is_not_pending(): void
    {
        $user = User::factory()->create(['verification_status' => 'approved']);

        $this->expectException(\Exception::class);
        $this->repo->verifyDriver($user->id);
    }

    public function test_verify_driver_throws_if_status_is_none(): void
    {
        $user = User::factory()->create(['verification_status' => 'none']);

        $this->expectException(\Exception::class);
        $this->repo->verifyDriver($user->id);
    }
}
