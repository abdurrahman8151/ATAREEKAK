<?php

namespace Tests\Support\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Snapshots\QueryBudgetStore;
use Tests\Support\Snapshots\SnapshotNormaliser;
use Tests\Support\Snapshots\SnapshotRepository;
use Tests\Support\Snapshots\StateSnapshotRepository;

/**
 * The snapshot assertion every module batch uses.
 *
 * A case is a stable key (`auth/login-success`). It owns two committed artefacts:
 *
 *   - tests/Snapshots/api/<case>.json      - status + normalised JSON body
 *   - an entry in tests/Snapshots/query-budgets.json - the query count the call issued
 *
 * Generation and verification are deliberately different code paths. With SNAPSHOT_UPDATE=1 the
 * call writes both artefacts and asserts only that the call happened. Without it the call is
 * compared byte-for-byte and the query count may not exceed the recorded budget. A normal run can
 * therefore never revise its own expectation.
 */
trait AssertsApiSnapshot
{
    /** Cases measured in an update run, so the whole set can be sanity-checked at the end. */
    private array $snapshotCasesSeen = [];

    /**
     * Run one route call, snapshot it, and check its query budget.
     *
     * @param  callable():TestResponse  $call
     * @param  int|null  $expectedStatus  the status the case documents. In an update run a
     *                                    mismatch fails the case WITHOUT writing anything: a red
     *                                    generation run must never bake a 500 body or a short
     *                                    refusal's query count into the committed baseline. That
     *                                    is how the first generation pass recorded a budget of 3
     *                                    for a route that really issues 18 queries (it had been
     *                                    measured on a 422 short-circuit), and because the store is
     *                                    shrink-only the poisoned value could not be corrected by a
     *                                    later green run.
     */
    protected function snapshotCall(string $case, callable $call, ?int $expectedStatus = null, ?string $expectedRoute = null): TestResponse
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $response = $call();
        } finally {
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        if ($expectedRoute !== null) {
            $this->assertMatchedTheDeclaredRoute($case, $expectedRoute);
        }

        $this->recordSnapshotCase($case, $response, $queries, $expectedStatus);

        return $response;
    }

    /**
     * The route the request ACTUALLY reached, as "METHOD uri-pattern", or null when nothing matched.
     *
     * A snapshot pins a response body; this pins WHICH route produced it. Without it a case whose
     * placeholder resolved to nothing (an empty complaint id collapsed `/api/complaints//show` to
     * the index route) or whose URI was subtly wrong pinned the wrong route's body under the right
     * route's name - the case stayed green while covering something else entirely.
     */
    private function assertMatchedTheDeclaredRoute(string $case, string $declaredRoute): void
    {
        $route = app('router')->current();

        $expected = (string) preg_replace('#^([A-Z]+)\s*/?#', '$1 ', trim($declaredRoute));

        if ($route === null) {
            $this->fail(
                "Snapshot case '{$case}' declares {$expected} but NO route matched: the request was "
                .'not routed at all (an unresolved placeholder can collapse the URI to another route '
                .'or to a 404). Nothing was snapshotted for this case.'
            );
        }

        $methods = array_values(array_diff($route->methods(), ['HEAD']));
        $method = $methods[0] ?? 'GET';
        $matched = $method.' '.$route->uri();

        $this->assertSame(
            $expected,
            $matched,
            "Snapshot case '{$case}' declares {$expected} but the request matched {$matched}. The "
            .'body would have been pinned under the wrong route name.'
        );
    }

    /**
     * Snapshot an already-made call. For cases that must make more than one request (a confirm
     * followed by a read) and snapshot the LAST response.
     */
    protected function snapshotResponse(string $case, TestResponse $response, int $queries = 0): void
    {
        $this->recordSnapshotCase($case, $response, $queries, null);
    }

    private function recordSnapshotCase(string $case, TestResponse $response, int $queries, ?int $expectedStatus): void
    {
        $this->snapshotCasesSeen[] = $case;

        $status = $response->getStatusCode();
        $json = SnapshotNormaliser::normalise($response->json());

        if (SnapshotRepository::isUpdating()) {
            if ($expectedStatus !== null && $status !== $expectedStatus) {
                $this->fail(
                    "Refusing to snapshot '{$case}': the case documents status {$expectedStatus} but the "
                    ."call returned {$status}. ".$this->snapshotBodyHint($response)
                );
            }

            SnapshotRepository::save($case, $status, $json);
            QueryBudgetStore::record([$case => $queries]);

            return;
        }

        $stored = SnapshotRepository::load($case);

        if ($stored === null) {
            $this->fail(
                "No committed snapshot for '{$case}'. "
                .'Generate it with SNAPSHOT_UPDATE=1 and commit the file under tests/Snapshots/api.'
            );
        }

        $this->assertSame(
            $stored['status'],
            $status,
            "Snapshot '{$case}': HTTP status changed from the committed {$stored['status']} to {$status}. "
            .$this->snapshotBodyHint($response)
        );

        // Byte-exact: the committed file and the fresh response are both encoded through the same
        // canonical encoder, so key order, empty-object-vs-list and number formatting all count.
        $expected = SnapshotRepository::encode($stored['json']);
        $actual = SnapshotRepository::encode($json);

        $this->assertSame(
            $expected,
            $actual,
            "Snapshot '{$case}' no longer matches the committed JSON. "
            .'If the change is intended, review the diff and regenerate with SNAPSHOT_UPDATE=1; '
            .'do not hand-edit the snapshot to match the app.'
        );

        $budget = QueryBudgetStore::get($case);

        if ($budget === null) {
            $this->fail(
                "No committed query budget for '{$case}'. "
                .'Record it with SNAPSHOT_UPDATE=1 so the route cannot silently start issuing more queries.'
            );
        }

        $this->assertLessThanOrEqual(
            $budget,
            $queries,
            "Query budget broken for '{$case}': {$queries} queries issued, committed budget is {$budget}. "
            .'A rise means new work behind an unchanged response (an N+1, a missing eager-load, a '
            .'repeated lookup). Fix the query, never raise the budget.'
        );

        if ($queries < $budget) {
            // A shrink is allowed and is the whole point of a one-way ratchet. Printed rather than
            // asserted so a legitimate small drop cannot turn a normal run red.
            fwrite(
                STDERR,
                "  [query-budget] {$case}: {$queries} < {$budget} - lower the committed baseline.\n"
            );
        }
    }

    private function snapshotBodyHint(TestResponse $response): string
    {
        $body = substr((string) $response->getContent(), 0, 400);

        return 'Body starts: '.$body;
    }

    /**
     * Pin the ROWS a mutation route wrote, not just the body it returned.
     *
     * `$capture` returns the state document (see `ApiSnapshotTestCase::captureMoneyAndRideState()`).
     * The captured state is normalised with the same normaliser the response snapshots use, so ids
     * become `<id:N>` aliases by first appearance and timestamps are masked; the rest - balances,
     * ledger legs, statuses, seat counts, escrow - is compared byte-for-byte.
     *
     * Written only under SNAPSHOT_UPDATE=1, and it writes only the CASES it is called for, so one
     * module can regenerate without deleting another module's state snapshots.
     */
    protected function assertStateSnapshot(string $case, callable $capture): void
    {
        $state = SnapshotNormaliser::normalise($capture());

        if (StateSnapshotRepository::isUpdating()) {
            StateSnapshotRepository::save($case, $state);

            return;
        }

        $stored = StateSnapshotRepository::load($case);

        if ($stored === null) {
            $this->fail(
                "No committed state snapshot for '{$case}'. "
                .'Generate it with SNAPSHOT_UPDATE=1 and commit the file under tests/Snapshots/state.'
            );
        }

        $expected = StateSnapshotRepository::encode($stored);
        $actual = StateSnapshotRepository::encode($state);

        $this->assertSame(
            $expected,
            $actual,
            "State snapshot '{$case}' no longer matches the committed rows: the write behind this "
            .'route changed even though the response may not have. If the change is intended, review '
            .'the diff and regenerate with SNAPSHOT_UPDATE=1; never hand-edit the state file.'
        );
    }

    /**
     * @return array<int, string>
     */
    protected function snapshotCasesSeen(): array
    {
        return $this->snapshotCasesSeen;
    }
}
