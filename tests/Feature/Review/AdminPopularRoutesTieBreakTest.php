<?php

namespace Tests\Feature\Review;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Concerns\ActsAsStaff;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * Stage 0.6 item 3 - the tiebreaker in `AdminTripService::getPopularRoutes()`.
 *
 * The corpus case (`admin/routes-popular`) cannot protect this. Step 8's order matrix caught the
 * endpoint answering tied destinations in an arbitrary order, and the snapshot world now gives every
 * destination a DISTINCT `trip_count` precisely so the corpus stops depending on the engine's mood -
 * which also means no committed snapshot can ever exercise the tie path.
 *
 * So the tie path is pinned here: with three destinations at one trip each, the route must answer
 * `trip_count DESC, destination ASC`, and it must answer the SAME thing twice.
 */
final class AdminPopularRoutesTieBreakTest extends TestCase
{
    use ActsAsStaff;
    use RefreshDatabase;

    #[Test]
    public function tied_routes_come_back_in_destination_order_and_the_same_order_twice(): void
    {
        $driver = User::factory()->create(['status' => 1, 'is_verified_driver' => true]);

        // One clear leader (Aleppo, twice) and a THREE-way tie at one trip each. The tie is the
        // point: before the tiebreaker these three came back in whatever order the grouping
        // produced - the order matrix saw Hama/Latakia/Homs four times and Homs-first the fifth.
        foreach (['Aleppo', 'Aleppo', 'Homs', 'Latakia', 'Hama'] as $destination) {
            RideBuilder::for($driver)->withAttributes([
                'pickup_address' => 'Damascus',
                'destination_address' => $destination,
            ])->create();
        }

        $token = $this->adminToken();

        $first = $this->withToken($token)->getJson('/api/admin/routes/popular');
        $first->assertOk();

        $this->assertSame(
            ['Aleppo', 'Hama', 'Homs', 'Latakia'],
            array_column($first->json('data'), 'to'),
            'the ranking must be trip_count DESC and then destination ASC, so tied rows cannot swap'
        );

        $this->assertSame([2, 1, 1, 1], array_column($first->json('data'), 'trip_count'));

        $second = $this->withToken($token)->getJson('/api/admin/routes/popular');
        $second->assertOk();

        $this->assertSame(
            $first->json('data'),
            $second->json('data'),
            'the same request must produce the same order'
        );
    }
}
