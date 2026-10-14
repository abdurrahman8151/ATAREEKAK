<?php

namespace Tests\Feature\Review;

use App\Models\Profile;
use App\Models\User;
use App\Models\UserRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * L8 (modular-monolith slice 7): characterization test for GET /api/staff/users/{userId}.
 *
 * Step 1 of the userProfile extraction. It pins the FULL response shape, which the existing
 * StaffOperationsControllerTest only checks at block level (identity, score, rating, ride history).
 * The comment block and the cache key were not pinned at all. This file changes no application code,
 * and must pass on the unchanged controller before the extraction (step 2) begins.
 */
class L8StaffUserProfileCharacterizationTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;
    use SeedsSystemWallets;

    /** The exact top-level keys of `data`, as the controller returns them today. */
    private const TOP_LEVEL_KEYS = [
        'id', 'full_name', 'email', 'gender', 'address', 'verification_status',
        'is_verified_driver', 'is_verified_passenger', 'account_status', 'joined_at',
        'profile_photo', 'description', 'score', 'rating', 'ride_history', 'comments_received',
    ];

    private User $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSystemWallets(1_000_000);

        $this->subject = User::factory()->create([
            'first_name' => 'Layla',
            'last_name' => 'Hassan',
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
        ]);

        // UserObserver::created already creates this user's profile row and seeds a 3.0 base rating
        // (rater_id NULL). Use those rows as they are; do not create a second profile.
        Profile::where('user_id', $this->subject->id)->firstOrFail()
            ->update(['description' => 'Test profile']);
    }

    public function test_profile_returns_exactly_the_sixteen_top_level_keys(): void
    {
        $data = $this->profile()->json('data');

        $this->assertSame(self::TOP_LEVEL_KEYS, array_keys($data));
    }

    public function test_full_name_and_identity_fields_are_returned(): void
    {
        $data = $this->profile()->json('data');

        $this->assertSame($this->subject->id, $data['id']);
        $this->assertSame('Layla Hassan', $data['full_name']);
        $this->assertSame('Test profile', $data['description']);
        $this->assertIsBool($data['is_verified_passenger']);
        $this->assertIsBool($data['is_verified_driver']);
    }

    public function test_score_block_has_its_five_keys(): void
    {
        $score = $this->profile()->json('data.score');

        $this->assertSame(['score', 'tier', 'cancel_rate', 'total_rides', 'total_cancellations'], array_keys($score));
    }

    public function test_rating_block_counts_only_the_platform_base_rating_when_nobody_has_rated(): void
    {
        // The observer's seeded 3.0 base rating (rater_id NULL) is the only rating.
        $rating = $this->profile()->json('data.rating');

        $this->assertSame(1, (int) $rating['total_ratings']);
        $this->assertEquals(3.0, (float) $rating['average']);
    }

    public function test_rating_block_counts_a_received_rating_on_top_of_the_base_rating(): void
    {
        $rater = User::factory()->create();
        UserRating::create([
            'rated_user_id' => $this->subject->id,
            'rater_id' => $rater->id,
            'rating' => 5,
        ]);

        $rating = $this->profile()->json('data.rating');

        // base 3.0 + received 5.0 => 2 ratings, average 4.0
        $this->assertSame(2, (int) $rating['total_ratings']);
        $this->assertEquals(4.0, (float) $rating['average']);
    }

    public function test_ride_history_is_split_into_driver_and_passenger_with_three_keys_each(): void
    {
        $history = $this->profile()->json('data.ride_history');

        $this->assertSame(['as_driver', 'as_passenger'], array_keys($history));
        $this->assertSame(['total', 'completed', 'cancelled'], array_keys($history['as_driver']));
        $this->assertSame(['total', 'completed', 'cancelled'], array_keys($history['as_passenger']));
    }

    public function test_comments_received_have_the_documented_shape(): void
    {
        $commenter = User::factory()->create(['first_name' => 'Omar', 'last_name' => 'Saleh']);

        $profile = Profile::where('user_id', $this->subject->id)->firstOrFail();
        $profile->comments()->create([
            'user_id' => $commenter->id,
            'comment' => 'Friendly and on time',
        ]);

        $comments = $this->profile()->json('data.comments_received');

        $this->assertCount(1, $comments);
        $this->assertSame(['id', 'comment', 'commenter', 'created_at'], array_keys($comments[0]));
        $this->assertSame(['id', 'name'], array_keys($comments[0]['commenter']));
        $this->assertSame('Omar Saleh', $comments[0]['commenter']['name']);
        $this->assertSame('Friendly and on time', $comments[0]['comment']);
    }

    public function test_the_profile_is_cached_under_the_key_the_admin_flows_bust(): void
    {
        Cache::forget("staff.user-profile.{$this->subject->id}");

        $this->profile()->assertOk();

        $this->assertTrue(
            Cache::has("staff.user-profile.{$this->subject->id}"),
            'The profile must be cached under staff.user-profile.{id}; StaffAdminController busts that key.'
        );
    }

    public function test_unknown_user_returns_404(): void
    {
        $this->withToken($this->staffToken(null, 'support_agent'))
            ->getJson('/api/staff/users/999999')
            ->assertStatus(404)
            ->assertJsonPath('message', 'User not found.');
    }

    public function test_profile_requires_a_staff_token(): void
    {
        $this->getJson("/api/staff/users/{$this->subject->id}")->assertStatus(401);
    }

    private function profile()
    {
        return $this->withToken($this->staffToken(null, 'support_agent'))
            ->getJson("/api/staff/users/{$this->subject->id}")
            ->assertOk();
    }
}
