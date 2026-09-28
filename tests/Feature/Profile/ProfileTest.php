<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Models\UserRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $response = $this->withToken($this->token)
            ->postJson("/api/profile/{$this->otherUser->id}/comments", [
                'comment' => 'Great driver, very punctual!',
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
        $response = $this->withToken($this->token)
            ->postJson("/api/profile/{$this->otherUser->id}/rate", [
                'rating' => 4.5,
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

    public function test_rating_updates_on_second_submission(): void
    {
        $this->withToken($this->token)
            ->postJson("/api/profile/{$this->otherUser->id}/rate", ['rating' => 3]);

        $response = $this->withToken($this->token)
            ->postJson("/api/profile/{$this->otherUser->id}/rate", ['rating' => 5]);

        $response->assertStatus(200);
        $this->assertEquals(1, UserRating::where('rater_id', $this->user->id)
            ->where('rated_user_id', $this->otherUser->id)->count());
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
