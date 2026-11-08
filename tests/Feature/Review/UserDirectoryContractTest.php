<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Identity\DTOs\UserSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage 2 C3: the contract that replaces cross-module `users` reads.
 *
 * The point of these tests is not that a lookup works - `User::find()` works. It is that the *batched*
 * form exists, is keyed, and costs ONE query, because the whole reason a module had a relation to
 * `User` was to render a list without a query per row.
 */
class UserDirectoryContractTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: mixed, 1: int} */
    private function measure(callable $callback): array
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = $callback();

        return [$result, $queries];
    }

    public function test_the_batched_lookup_costs_one_query_whatever_the_number_of_ids(): void
    {
        $users = User::factory()->count(3)->create();

        [$summaries, $queries] = $this->measure(
            fn () => app(UserDirectory::class)->summariesFor($users->pluck('id')->all())
        );

        $this->assertCount(3, $summaries);
        $this->assertSame(1, $queries, 'summariesFor() must be a single query, or a list screen is an N+1.');
    }

    public function test_summaries_are_keyed_by_user_id_and_unknown_ids_are_absent(): void
    {
        $users = User::factory()->count(2)->create();

        $summaries = app(UserDirectory::class)->summariesFor([...$users->pluck('id')->all(), 999999]);

        $this->assertSame($users->pluck('id')->sort()->values()->all(), collect($summaries)->keys()->sort()->values()->all());
        $this->assertArrayNotHasKey(999999, $summaries);
        $this->assertContainsOnlyInstancesOf(UserSummary::class, $summaries);
    }

    public function test_an_empty_request_queries_nothing(): void
    {
        [$summaries, $queries] = $this->measure(fn () => app(UserDirectory::class)->summariesFor([]));

        $this->assertSame([], $summaries);
        $this->assertSame(0, $queries);
    }

    public function test_summary_for_returns_null_for_an_unknown_user(): void
    {
        $this->assertNull(app(UserDirectory::class)->summaryFor(999999));
    }

    public function test_the_summary_carries_the_values_the_caller_renders(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Maya',
            'last_name' => 'Haddad',
            'email' => 'maya@example.test',
        ]);

        $summary = app(UserDirectory::class)->summaryFor($user->id);

        $this->assertNotNull($summary);
        $this->assertSame($user->id, $summary->id);
        $this->assertSame('Maya', $summary->firstName);
        $this->assertSame('maya@example.test', $summary->email);
        $this->assertSame('Maya Haddad', $summary->fullName());
        // `User` has no fullName(); the codebase concatenates inline (RideResource, BookingResource).
        $this->assertSame(trim($user->first_name.' '.$user->last_name), $summary->fullName(), 'the DTO must not invent a different name rule');
    }

    public function test_the_dto_cannot_write_the_row_it_came_from(): void
    {
        $this->assertFalse(
            method_exists(UserSummary::class, 'save'),
            'a summary that can save() is a model leaving its owner'
        );
        $this->assertTrue((new \ReflectionClass(UserSummary::class))->isFinal());
    }
}
