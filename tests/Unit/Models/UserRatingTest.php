<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Modules\Identity\Models\UserRating;
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

    public function test_rating_is_not_cast_so_the_column_value_is_kept(): void
    {
        // Batch 229 - current truth: UserRating declares no $casts at all, so `rating` is read
        // back as the decimal column value instead of being cast to float.
        $this->assertArrayNotHasKey('rating', (new UserRating)->getCasts());
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
