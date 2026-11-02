<?php

declare(strict_types=1);

namespace Tests\Feature\Snapshots;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * STAGE 0.5, step 3: the corpus must not pin `[]` where a list belongs.
 *
 * An empty list is the one snapshot shape that cannot catch a wrong query. `data: []` looks
 * identical whether the filter is right, the join is wrong, or the fixture simply has no matching
 * row - so a case that pinned `[]` proved nothing about the endpoint it was written for. Thirty
 * seven arrays were empty for exactly that reason (the fixture had no candidate rows), and the
 * same emptiness could silently come back the next time a fixture or an ORDER BY changed.
 *
 * This test reads the committed corpus only - no database, no app boot beyond the base test case -
 * and fails in three ways:
 *
 *   1. one of the ten list endpoints carries fewer than three items;
 *   2. one of the nested collections carries no rows;
 *   3. any OTHER empty array appears without an entry in EMPTY_BY_DESIGN carrying the reason.
 *
 * Rule 3 is the important one: a new empty array cannot slip in by being forgotten, because every
 * empty array in the corpus must either be filled or named below with the reason it is correct.
 */
class SnapshotEmptinessTest extends TestCase
{
    /**
     * The ten endpoints that pinned `[]` and now carry at least three rows, with distinct related
     * records behind them.
     *
     * @var array<string, string>
     */
    private const LISTS = [
        'admin/dashboard-recent' => 'data',
        'admin/drivers-activity' => 'data',
        'admin/passenger-wallet-charges' => 'data',
        'admin/trips-live' => 'data',
        'chat/messages-index' => 'data',
        'rides/search-get' => 'data',
        'rides/search-post' => 'data',
        'score/history' => 'data',
        'score/transactions' => 'data',
        'wallet/requests-index' => 'data',
    ];

    /**
     * Nested collections that were empty because the fixture had no rows for them.
     *
     * @var array<string, array<int, string>>
     */
    private const NESTED = [
        'admin/driver-dashboard' => ['data.documents'],
        'admin/driver-profile' => ['data.documents'],
        'admin/drivers-dashboard' => ['data.recent_activity'],
        'admin/passenger-full-profile' => ['data.complaints', 'data.recent_trips', 'data.wallet_charges'],
        'admin/verifications' => ['data.0.documents'],
        'complaints/index' => ['data.0.attachments'],
        'complaints/show' => ['data.attachments'],
        'complaints/store' => ['complaint.attachments'],
        'profile/show' => ['data.comments'],
        'profile/update' => ['data.comments'],
        'profile/verify-status' => ['documents'],
        // `staff/chat-messages` is checked by `at_least_one_message_carries_a_metadata_map` below:
        // the two message lists order in OPPOSITE directions, so "index 0" is the image row in one
        // and the oldest text row in the other.
        'staff/complaints' => ['data.0.attachments'],
        'staff/complaints-escalate' => ['data.attachments'],
        'staff/complaints-respond' => ['data.attachments'],
        'staff/complaints-show' => ['data.attachments'],
        'staff/escalated-complaints' => ['data.0.attachments'],
        'staff/escalated-resolve' => ['data.attachments'],
        'staff/users-show' => ['data.comments_received'],
        'staff/verifications-pending' => ['data.0.documents'],
    ];

    /**
     * Empty arrays that are correct, each with the reason it cannot be filled.
     *
     * @var array<string, string>
     */
    private const EMPTY_BY_DESIGN = [
        'rides/route-options:data.routes.0.geometry' => 'the routing provider polyline; the geocoder/routing stub returns none, and this is a blob rather than a record collection',
        'chat/messages-store:data.metadata' => 'the text message type returns an empty metadata map by design; the populated shape is pinned by the image row in chat/messages-index',
        'staff/chat-send:data.metadata' => 'same as chat/messages-store: metadata is empty for the text type by design',
        'profile/show:data.documents' => 'identity documents are emitted for the OWNER only (ProfileController::ownerDocuments returns [] when isOwner is false), and this case views ANOTHER user\'s profile - the documents are pinned by profile/verify-status instead',
        'profile/update:data.documents' => 'the POST /api/profile response formats through formatProfileData() with the default isOwner = false (ProfileController:348), so the owner\'s own update response omits documents by construction; profile/verify-status pins them',
    ];

    /**
     * Empty arrays that are correct because of the ROW TYPE, not the collection: every TEXT message
     * carries `metadata: []` by design, and only the image row in the same list carries the map.
     * Written as patterns so an index shift (the two chat lists order in opposite directions) does
     * not turn a correct corpus red.
     *
     * @var array<string, string>
     */
    private const EMPTY_PATTERNS = [
        '#^(chat/messages-index|staff/chat-messages):data\.\d+\.metadata$#' => 'a TEXT message row carries an empty metadata map by design; the image row in the SAME list pins the populated shape (asserted by at_least_one_message_carries_a_metadata_map)',
    ];

    #[Test]
    public function every_list_snapshot_carries_at_least_three_items(): void
    {
        $failures = [];

        foreach (self::LISTS as $case => $path) {
            $json = $this->snapshotJson($case);

            $this->assertIsArray($json, "Snapshot {$case} is missing or unreadable.");

            $list = $this->at($json, $path);

            $this->assertIsArray($list, "Snapshot {$case}: {$path} is not a list.");

            if (count($list) < 3) {
                $failures[] = "{$case}: {$path} carries ".count($list).' item(s), expected at least 3';
            }
        }

        $this->assertSame([], $failures, "These lists are still too thin to police their endpoint:\n  ".implode("\n  ", $failures));
    }

    /**
     * `admin/routes-popular` sorted by ONE key - `trip_count` - with no tiebreaker when this guard
     * was written (`AdminTripService::getPopularRoutes()`), so two destinations with the same count
     * came back in whatever order MySQL's grouping produced.
     *
     * Step 8's order matrix proved it: with Homs, Latakia and Hama at one trip each, four execution
     * orders answered Hama/Latakia/Homs and the reversed order answered Homs first. The fixture now
     * gives the three live trips distinct counts (Aleppo 4, Latakia 2, Homs 1), which makes the
     * ranking deterministic and worth pinning.
     *
     * This test is the guard on that fixture invariant: if a future edit reintroduces a tie, the
     * snapshot becomes arbitrary again, and this fails at the point of regeneration instead of as an
     * intermittent, unreproducible red in someone else's run. The app-side fix landed afterwards
     * (stage 0.6 item 3: `trip_count DESC, destination ASC`), and the tie path is pinned against the
     * app by `tests/Feature/Review/AdminPopularRoutesTieBreakTest.php`; the fixture keeps distinct
     * counts because the committed snapshot is frozen.
     */
    #[Test]
    public function the_popular_routes_snapshot_can_actually_pin_its_ordering(): void
    {
        $json = $this->snapshotJson('admin/routes-popular');

        $this->assertIsArray($json, 'Snapshot admin/routes-popular is missing or unreadable.');

        $rows = $this->at($json, 'data');

        $this->assertIsArray($rows, 'Snapshot admin/routes-popular: data is not a list.');

        $this->assertGreaterThanOrEqual(3, count($rows), 'The popular-routes list is too thin to pin a ranking.');

        $counts = array_map(static fn (array $row): int => (int) $row['trip_count'], $rows);

        $this->assertSame(
            count($counts),
            count(array_unique($counts)),
            'The endpoint sorts by trip_count alone, so two rows with the same trip_count are returned in an arbitrary '
            .'order and the snapshot cannot be green twice. The fixture must give every destination a distinct trip_count '
            .'(see the $liveTrips note in ApiSnapshotTestCase). Counts found: '.implode(', ', $counts)
        );

        // The list is ordered by that key, descending: pinning distinct counts is only worth
        // anything if the snapshot also pins the order they must come back in.
        $sorted = $counts;
        rsort($sorted);

        $this->assertSame($sorted, $counts, 'The popular routes are not ordered by trip_count descending.');
    }

    #[Test]
    public function every_nested_collection_carries_at_least_one_row(): void
    {
        $failures = [];

        foreach (self::NESTED as $case => $paths) {
            $json = $this->snapshotJson($case);

            $this->assertIsArray($json, "Snapshot {$case} is missing or unreadable.");

            foreach ($paths as $path) {
                $value = $this->at($json, $path);

                if (! is_array($value)) {
                    $failures[] = "{$case}: {$path} is not a collection (the parent row is probably missing)";

                    continue;
                }

                if ($value === []) {
                    $failures[] = "{$case}: {$path} is still empty";
                }
            }
        }

        $this->assertSame([], $failures, "These nested collections are still empty:\n  ".implode("\n  ", $failures));
    }

    #[Test]
    public function at_least_one_message_carries_a_metadata_map(): void
    {
        // The image row's metadata map is the only non-empty one (the text handler always writes
        // `[]`), and the two message lists order in opposite directions, so the index it appears at
        // differs between them. Assert it exists SOMEWHERE in each list rather than at a fixed
        // index, so neither case can pass by showing a text-only conversation.
        foreach (['chat/messages-index', 'staff/chat-messages'] as $case) {
            $json = $this->snapshotJson($case);

            $this->assertIsArray($json, "Snapshot {$case} is missing or unreadable.");

            $messages = $json['data'] ?? null;

            $this->assertIsArray($messages, "Snapshot {$case}: data is not a list.");
            $this->assertNotSame([], $messages, "Snapshot {$case}: the conversation carries no messages.");

            $withMetadata = array_filter(
                $messages,
                static fn ($message) => is_array($message) && ($message['metadata'] ?? []) !== []
            );

            $this->assertNotSame(
                [],
                $withMetadata,
                "Snapshot {$case}: no message carries a populated metadata map, so the image shape is unpinned."
            );
        }
    }

    #[Test]
    public function no_other_empty_array_appears_without_a_written_reason(): void
    {
        $unexplained = [];

        foreach ($this->snapshotCases() as $case) {
            $json = $this->snapshotJson($case);

            if (! is_array($json)) {
                continue;
            }

            foreach ($this->emptyPaths($json) as $path) {
                $key = $case.':'.$path;

                if (array_key_exists($key, self::EMPTY_BY_DESIGN)) {
                    continue;
                }

                $explained = false;

                foreach (array_keys(self::EMPTY_PATTERNS) as $pattern) {
                    if (preg_match($pattern, $key) === 1) {
                        $explained = true;

                        break;
                    }
                }

                if (! $explained) {
                    $unexplained[] = $key;
                }
            }
        }

        sort($unexplained);

        $this->assertSame(
            [],
            $unexplained,
            'These arrays are empty and carry no written reason. Fill the fixture, or add the path to '
            ."EMPTY_BY_DESIGN with the reason it is correct:\n  ".implode("\n  ", $unexplained)
        );
    }

    /** @return array<int, string> */
    private function snapshotCases(): array
    {
        $root = $this->corpusRoot();
        $cases = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'json') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $cases[] = substr($path, strlen(str_replace('\\', '/', $root)) + 1, -5);
        }

        sort($cases);

        return $cases;
    }

    /** @return array<int, string> the JSON paths holding `[]`, as dotted paths */
    private function emptyPaths(mixed $value, string $path = ''): array
    {
        if (! is_array($value)) {
            return [];
        }

        if ($value === []) {
            return [$path];
        }

        $found = [];

        foreach ($value as $key => $child) {
            $found = array_merge($found, $this->emptyPaths($child, $path === '' ? (string) $key : $path.'.'.$key));
        }

        return $found;
    }

    private function at(mixed $document, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (! is_array($document) || ! array_key_exists($segment, $document)) {
                return null;
            }

            $document = $document[$segment];
        }

        return $document;
    }

    /** @return array<string, mixed>|null the `json` body of one snapshot case */
    private function snapshotJson(string $case): ?array
    {
        $file = $this->corpusRoot().'/'.$case.'.json';

        if (! is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) && is_array($decoded['json'] ?? null) ? $decoded['json'] : null;
    }

    private function corpusRoot(): string
    {
        return dirname(__DIR__, 2).'/Snapshots/api';
    }
}
