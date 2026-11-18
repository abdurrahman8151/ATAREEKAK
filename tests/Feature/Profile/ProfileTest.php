<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Modules\Identity\Models\UserRating;
use App\Modules\Ride\Models\Booking;
use App\Modules\Ride\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RideBuilder;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['password' => bcrypt('password123')]);
        $this->otherUser = User::factory()->create(['password' => bcrypt('password123')]);
        $this->token = $this->getToken($this->user);
    }

    public function test_can_view_own_profile(): void
    {
        $response = $this->withToken($this->token)
            ->getJson("/api/profile/{$this->user->id}");
        $response->assertStatus(200);
    }

    public function test_can_view_other_user_profile(): void
    {
        $response = $this->withToken($this->token)
            ->getJson("/api/profile/{$this->otherUser->id}");
        $response->assertStatus(200);
    }

    public function test_view_profile_requires_auth(): void
    {
        $this->getJson("/api/profile/{$this->user->id}")->assertStatus(401);
    }

    public function test_can_update_profile_description(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/profile', [
                'description' => 'I am a friendly driver',
            ]);
        $response->assertStatus(200);
    }

    public function test_profile_update_accepts_valid_fields(): void
    {
        // The app accepts a description update and returns 200
        $response = $this->withToken($this->token)
            ->postJson('/api/profile', [
                'description' => 'Updated description',
            ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('profiles', [
            'user_id' => $this->user->id,
            'description' => 'Updated description',
        ]);
    }

    public function test_can_comment_on_another_users_profile(): void
    {
        // RESTORED (batch 230): this was parked in 229 because profile_comments.ride_id was required
        // by ProfileInteractionService and created by no migration in this repository. The
        // 2026_10_13_000001 migration restores the column, its index and its ON DELETE SET NULL
        // foreign key to production's shape, so the case is live again with its original assertion.

        $response = $this->withToken($this->token)
            ->postJson("/api/profile/{$this->otherUser->id}/comments", [
                'comment' => 'Great driver, very punctual!',
                // Batch 229 - current truth: a comment must name the ride it belongs to.
                'ride_id' => $this->completedRideWithOtherUser()->id,
            ]);
        $response->assertStatus(201);
    }

    public function test_cannot_comment_on_own_profile(): void
    {
        $response = $this->withToken($this->token)
            ->postJson("/api/profile/{$this->user->id}/comments", [
                'comment' => 'I am great!',
            ]);
        $this->assertNotEquals(201, $response->status());
    }

    public function test_comment_cannot_be_empty(): void
    {
        $response = $this->withToken($this->token)
            ->postJson("/api/profile/{$this->otherUser->id}/comments", [
                'comment' => '',
            ]);
        $response->assertStatus(422);
    }

    public function test_can_rate_another_user(): void
    {
        // RESTORED (batch 230): parked in 229 because user_ratings.ride_id was read and written by
        // the model and the service but created by no migration here. The 2026_10_13_000001
        // migration restores the column, its index, its ON DELETE SET NULL foreign key and the
        // per-ride unique key to production's shape.

        $response = $this->withToken($this->token)
            ->postJson("/api/profile/{$this->otherUser->id}/rate", [
                'rating' => 4.5,
                // Batch 229 - current truth: a rating must name the ride it belongs to.
                'ride_id' => $this->completedRideWithOtherUser()->id,
            ]);
        $response->assertStatus(200);
    }

    public function test_cannot_rate_self(): void
    {
        $response = $this->withToken($this->token)
            ->postJson("/api/profile/{$this->user->id}/rate", [
                'rating' => 5,
            ]);
        $this->assertNotEquals(200, $response->status());
    }

    public function test_rating_must_be_between_1_and_5(): void
    {
        $r1 = $this->withToken($this->token)
            ->postJson("/api/profile/{$this->otherUser->id}/rate", ['rating' => 6]);
        $this->assertNotEquals(200, $r1->status());

        $r2 = $this->withToken($this->token)
            ->postJson("/api/profile/{$this->otherUser->id}/rate", ['rating' => 0]);
        $this->assertNotEquals(200, $r2->status());
    }

    public function test_a_second_rating_for_the_same_ride_is_refused_and_keeps_one_row(): void
    {
        // RESTORED (batch 230): the per-ride unique key now exists (2026_10_13_000001), so the
        // one-rating-per-ride rule can finally be exercised; the assertions are unchanged.

        // A rating is one-per-ride. ProfileInteractionService refuses a repeat for the same ride_id
        // with 409, so the old "updates on second submission" behaviour no longer exists for a
        // single ride.
        $ride = $this->completedRideWithOtherUser();

        $this->withToken($this->token)
            ->postJson("/api/profile/{$this->otherUser->id}/rate", ['rating' => 3, 'ride_id' => $ride->id])
            ->assertStatus(200);

        // Batch 231: ProfileInteractionHandler now passes the service's deliberate 409 refusal
        // through (the duplicate rating is matched by exact code, not by the exception's code in
        // general), so a repeat rating answers 409 while the one-row invariant below still holds.
        $this->withToken($this->token)
            ->postJson("/api/profile/{$this->otherUser->id}/rate", ['rating' => 5, 'ride_id' => $ride->id])
            ->assertStatus(409);

        $this->assertEquals(1, UserRating::where('rater_id', $this->user->id)
            ->where('rated_user_id', $this->otherUser->id)->count());
    }

    /**
     * A completed ride driven by the other user, with a completed booking for this user.
     *
     * Batch 229: comments and ratings are only allowed after a completed ride, and
     * ProfileInteractionService::assertEligible reads exactly this pair - a completed booking on a
     * ride whose driver is the profile owner. RideBuilder is the suite's ride fixture.
     */
    private function completedRideWithOtherUser(): Ride
    {
        $ride = RideBuilder::for($this->otherUser)
            ->withAttributes([
                'available_seats' => 4,
                'price_per_seat' => 50000,
                'payment_method' => 'cash',
                'booking_type' => 'direct',
                'distance' => 320.5,
                'duration' => 240,
                'communication_number' => '0911000000',
            ])
            ->status('completed')
            ->departureTime(now()->subHour())
            ->create();

        Booking::create([
            'user_id' => $this->user->id,
            'ride_id' => $ride->id,
            'seats' => 1,
            'status' => 'completed',
            'communication_number' => '0911000000',
        ]);

        return $ride;
    }

    private function getToken(User $user): string
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        return $response->json('tokens.access_token');
    }
}
