<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_fillable_contains_expected_fields(): void
    {
        $model = new UserRating;

        $this->assertContains('rater_id', $model->getFillable());
        $this->assertContains('rated_user_id', $model->getFillable());
        $this->assertContains('rating', $model->getFillable());
    }

    public function test_rating_is_cast_to_float(): void
    {
        $this->assertEquals('float', (new UserRating)->getCasts()['rating']);
    }

    public function test_rater_relationship_returns_correct_user(): void
    {
        $rater = User::factory()->create();
        $rated = User::factory()->create();

        $rating = UserRating::create([
            'rater_id' => $rater->id,
            'rated_user_id' => $rated->id,
            'rating' => 4.5,
        ]);

        $this->assertEquals($rater->id, $rating->rater->id);
    }

    public function test_rated_user_relationship_returns_correct_user(): void
    {
        $rater = User::factory()->create();
        $rated = User::factory()->create();

        $rating = UserRating::create([
            'rater_id' => $rater->id,
            'rated_user_id' => $rated->id,
            'rating' => 3.0,
        ]);

        $this->assertEquals($rated->id, $rating->ratedUser->id);
    }
}
