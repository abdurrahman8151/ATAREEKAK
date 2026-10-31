<?php

namespace Tests\Feature\Snapshots;

use Illuminate\Routing\Route;
use Tests\Support\Snapshots\QueryBudgetStore;
use Tests\Support\Snapshots\SnapshotRepository;
use Tests\TestCase;

/**
 * The route inventory: every `api/*` route is EITHER snapshotted by a named case OR parked with a
 * written-down reason. Nothing is allowed to be quietly absent.
 *
 * `tests/Snapshots/route-inventory.json` is committed and generated from `route:list --json`, so a
 * new route makes this test red until its case (or its parking reason) is added, and a deleted
 * route makes it red until the stale row is removed. This is the guard against the failure mode
 * the snapshot suite is meant to prevent: a route nobody noticed was never covered.
 *
 * The inventory is data, not a test, so the numbers in its `totals` block are re-derived here from
 * the live router rather than trusted.
 */
final class RouteInventoryTest extends TestCase
{
    /** Routes whose body is not JSON (HTML or raw file bytes) or that cannot answer at all. */
    private const PARKED = [
        'GET api/admin/export/pdf',
        'GET api/documentation',
        'GET api/oauth2-callback',
        'GET api/staff/documents/{photoId}',
        'GET api/staff/documents/{photoId}/identity',
    ];

    /** @return array<string, array<string, mixed>> */
    private function inventory(): array
    {
        $path = base_path('tests/Snapshots/route-inventory.json');

        $this->assertFileExists($path, 'The committed route inventory is missing.');

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, 'tests/Snapshots/route-inventory.json is not valid JSON.');
        $this->assertArrayHasKey('routes', $decoded);

        $byKey = [];

        foreach ($decoded['routes'] as $row) {
            $key = $row['method'].' '.$row['uri'];

            $this->assertArrayNotHasKey($key, $byKey, "The route inventory lists {$key} twice.");

            $byKey[$key] = $row;
        }

        return $byKey;
    }

    /** @return array<int, string> */
    private function liveApiRoutes(): array
    {
        $live = [];

        /** @var Route $route */
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $live[] = $method.' '.$route->uri();
            }
        }

        sort($live);

        return array_values(array_unique($live));
    }

    public function test_the_inventory_covers_every_live_api_route_and_nothing_else(): void
    {
        $inventory = $this->inventory();
        $live = $this->liveApiRoutes();

        $missing = array_values(array_diff($live, array_keys($inventory)));
        $stale = array_values(array_diff(array_keys($inventory), $live));

        $this->assertSame([], $missing, sprintf(
            "These api/* routes exist but are neither snapshotted nor parked:\n  %s\n"
            .'Add a case to a SnapshotTest, or a parked row with a reason in '
            .'tests/Snapshots/route-inventory.json.',
            implode("\n  ", $missing)
        ));

        $this->assertSame([], $stale, sprintf(
            "These routes are in the inventory but no longer exist in the router:\n  %s\n"
            .'Regenerate tests/Snapshots/route-inventory.json.',
            implode("\n  ", $stale)
        ));

        $parked = array_values(array_filter($inventory, static fn (array $row) => $row['status'] === 'parked'));

        $this->assertSame(self::PARKED, array_map(
            static fn (array $row) => $row['method'].' '.$row['uri'],
            array_values(array_filter(
                $inventory,
                static fn (array $row) => in_array($row['method'].' '.$row['uri'], self::PARKED, true)
            ))
        ), 'The set of parked routes changed. Add or remove the route here AND in the inventory, with a reason.');

        $this->assertCount(count($live), $inventory);
        $this->assertCount(count($inventory) - count($parked), $this->snapshottedRows($inventory));
    }

    public function test_every_snapshotted_route_has_a_case_a_committed_snapshot_and_a_query_budget(): void
    {
        $inventory = $this->inventory();
        $missingSnapshot = [];
        $missingBudget = [];
        $unknownCase = [];

        foreach ($this->snapshottedRows($inventory) as $row) {
            $case = (string) $row['case'];
            $test = (string) $row['test'];
            $class = 'Tests\\Feature\\Snapshots\\'.$test;

            if (! class_exists($class) || ! array_key_exists($case, $class::cases())) {
                $unknownCase[] = $row['method'].' '.$row['uri'].' -> '.$test.'::'.$case;

                continue;
            }

            if (! is_file(SnapshotRepository::path($case))) {
                $missingSnapshot[] = $case;

                continue;
            }

            if (! array_key_exists($case, QueryBudgetStore::all())) {
                $missingBudget[] = $case;
            }
        }

        $this->assertSame([], $unknownCase, "The inventory names cases that no test class defines:\n  ".implode("\n  ", $unknownCase));
        $this->assertSame([], $missingSnapshot, "These cases have no committed snapshot file:\n  ".implode("\n  ", $missingSnapshot));
        $this->assertSame([], $missingBudget, "These cases have no committed query budget:\n  ".implode("\n  ", $missingBudget));
    }

    public function test_every_parked_route_says_why_in_writing(): void
    {
        $inventory = $this->inventory();

        $parked = array_values(array_filter($inventory, static fn (array $row) => $row['status'] === 'parked'));

        $this->assertNotEmpty($parked, 'Nothing is parked - if that is now true, empty the PARKED list.');

        foreach ($parked as $row) {
            $key = $row['method'].' '.$row['uri'];

            $this->assertIsString($row['reason'] ?? null, "Parked route {$key} has no reason.");
            $this->assertGreaterThanOrEqual(40, strlen((string) $row['reason']), "Parked route {$key} needs a real reason, not a placeholder.");
            $this->assertNull($row['case'], "Parked route {$key} must not name a case.");
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $inventory
     * @return array<int, array<string, mixed>>
     */
    private function snapshottedRows(array $inventory): array
    {
        return array_values(array_filter($inventory, static fn (array $row) => $row['status'] === 'snapshot'));
    }
}
