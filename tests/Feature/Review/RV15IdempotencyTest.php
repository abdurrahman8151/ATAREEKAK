<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-15 — booking idempotency must be durable, atomic and USER-SCOPED.
 *
 * The old `bookRide` deduped on a Redis key `booking:idem:{key}`. Three defects,
 * all measured before touching it:
 *   1. CROSS-TENANT LEAK — the key was NOT scoped by user, so if user A booked with
 *      key K and user B later replayed K, B got A's booking returned (including A's
 *      communication_number / phone). The DB query in the fix scopes on user_id, so
 *      this cannot happen.
 *   2. NOT DURABLE — the key lived only in Redis. A cache flush erased the dedup and a
 *      later replay created a second booking. The fix persists idempotency_key on the
 *      booking row with a unique(user_id, idempotency_key) index (from RV-40).
 *   3. RACE — the check ran OUTSIDE the transaction, so two concurrent same-key requests
 *      both passed the "not found" check and created two bookings. The re-check now runs
 *      inside the transaction and the unique index is the atomic backstop.
 *
 * Uses a CASH ride so booking is a pure row insert with no wallet side-effects — the
 * idempotency logic is what's under test, not the money path.
 */
class RV15IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: the unique(user_id,idempotency_key) index semantics.');
        }
        parent::setUp();
    }

    private function passenger(): User
    {
        return User::factory()->create([
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);
    }

    private function cashRide(User $driver): Ride
    {
        return RideBuilder::for($driver)->paymentMethod('cash')->create();
    }

    /** @test */
    public function the_idempotency_key_is_persisted_on_the_booking_so_it_survives_a_cache_flush(): void
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);
        $ride = $this->cashRide($driver);
        $passenger = $this->passenger();
        $token = $this->withToken($this->loginToken($passenger));

        $key = Str::uuid()->toString();
        $token->postJson("/api/rides/{$ride->id}/book", [
            'seats' => 1, 'communication_number' => '0912345678', 'idempotency_key' => $key,
        ])->assertStatus(201);

        $this->assertDatabaseHas('bookings', [
            'ride_id' => $ride->id,
            'user_id' => $passenger->id,
            'idempotency_key' => $key,
        ], 'mysql');
    }

    /** @test */
    public function a_replay_by_the_same_user_returns_the_same_booking(): void
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);
        $ride = $this->cashRide($driver);
        $passenger = $this->passenger();
        $token = $this->loginToken($passenger);
        $key = Str::uuid()->toString();

        $r1 = $this->withToken($token)->postJson("/api/rides/{$ride->id}/book", [
            'seats' => 1, 'communication_number' => '0912345678', 'idempotency_key' => $key,
        ]);
        $r2 = $this->withToken($token)->postJson("/api/rides/{$ride->id}/book", [
            'seats' => 1, 'communication_number' => '0912345678', 'idempotency_key' => $key,
        ]);

        $r1->assertStatus(201);
        $r2->assertStatus(201);
        $this->assertSame($r1->json('data.id'), $r2->json('data.id'), 'same key -> same booking');

        $this->assertSame(
            1,
            Booking::where('user_id', $passenger->id)->where('idempotency_key', $key)->count(),
            'exactly one booking for one key'
        );
    }

    /** @test */
    public function a_different_user_replaying_the_same_key_gets_their_ow_n_booking_not_the_first_users(): void
    {
        $driver = User::factory()->create(['is_verified_driver' => true]);
        $ride = $this->cashRide($driver);

        $userA = $this->passenger();
        $userB = $this->passenger();

        // Both use the SAME idempotency key (realistic when a shared device / misbehaving
        // client reuses a request id), but they are different tenants.
        $key = Str::uuid()->toString();

        $a = $this->withToken($this->loginToken($userA))->postJson("/api/rides/{$ride->id}/book", [
            'seats' => 1, 'communication_number' => '0912345678', 'idempotency_key' => $key,
        ]);
        $b = $this->withToken($this->loginToken($userB))->postJson("/api/rides/{$ride->id}/book", [
            'seats' => 1, 'communication_number' => '0987654321', 'idempotency_key' => $key,
        ]);

        $a->assertStatus(201);
        $b->assertStatus(201);

        // RV-15 core: B must get a DIFFERENT booking. The old non-user-scoped Redis key
        // would have returned A's booking here (cross-tenant leak).
        $this->assertNotSame(
            $a->json('data.id'),
            $b->json('data.id'),
            'RV-15: a replay by a different user must NOT return the first user\'s booking'
        );

        // And B must NOT see A's phone number.
        $this->assertNotSame(
            $a->json('data.communication_number'),
            $b->json('data.communication_number'),
            'the returned booking must carry the CALLER\'s data, not another tenant\'s'
        );

        // Two rows, one per user, both keyed the same — which the unique(user_id,
        // idempotency_key) index permits because the users differ.
        $this->assertSame(2, Booking::where('idempotency_key', $key)->count());
    }

    /** @test */
    public function the_unique_user_id_idempotency_key_index_exists(): void
    {
        // RV-40 added this index; RV-15 depends on it as the atomic backstop. Pinning it
        // means neither can silently drop without the other failing.
        $has = collect(DB::select(
            "SHOW INDEX FROM bookings WHERE Key_name = 'bookings_user_id_idempotency_key_unique'"
        ))->count();

        $this->assertGreaterThan(0, $has, 'unique(user_id, idempotency_key) must exist for RV-15');
    }

    private function loginToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'password123',
        ])->json('tokens.access_token');
    }
}
