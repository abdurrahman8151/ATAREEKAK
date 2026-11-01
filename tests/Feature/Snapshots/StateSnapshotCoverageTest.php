<?php

namespace Tests\Feature\Snapshots;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Snapshots\StateSnapshotRepository;

/**
 * Keeps step 4's coverage honest, without a database.
 *
 * The state snapshots only protect the routes somebody remembered to list, so this test derives the
 * required set from the CASE DEFINITIONS themselves instead of from the list: every non-GET case
 * whose route writes a wallet, a wallet request, a booking or a ride must be listed in
 * `MONEY_AND_RIDE_MUTATION_CASES` and must have a committed state file. A new money route added to
 * any module fails here until it is covered.
 */
final class StateSnapshotCoverageTest extends TestCase
{
    /** Route prefixes whose non-GET methods change money or a ride. */
    private const MUTATING_PREFIXES = [
        'api/wallet',
        'api/admin/wallet',
        'api/admin/passengers/',
        'api/rides',
        'api/bookings',
        'api/staff/bookings',
        'api/staff/trips',
    ];

    #[Test]
    public function every_money_or_ride_mutation_case_pins_the_rows_it_writes(): void
    {
        $required = [];
        $missingFromList = [];
        $missingStateFile = [];

        foreach ($this->caseDefinitions() as $case => $definition) {
            $method = strtoupper((string) ($definition['method'] ?? 'GET'));

            if ($method === 'GET') {
                continue;
            }

            $route = (string) ($definition['route'] ?? '');
            // 'POST /api/rides' -> 'api/rides': the declared routes carry the method and a leading
            // slash, the prefix list is written the way the route table prints them.
            $uri = ltrim((string) preg_replace('#^[A-Z]+\s+#', '', $route), '/');

            if (array_key_exists($case, ApiSnapshotTestCase::READ_ONLY_POST_CASES)) {
                continue;
            }

            if (! $this->isMoneyOrRideUri($uri)) {
                continue;
            }

            $required[] = $case;

            if (! in_array($case, ApiSnapshotTestCase::MONEY_AND_RIDE_MUTATION_CASES, true)) {
                $missingFromList[] = $case.' ('.$route.')';
            }

            if (! StateSnapshotRepository::exists($case)) {
                $missingStateFile[] = $case;
            }
        }

        $this->assertNotSame([], $required, 'No money or ride mutation case was found at all - the detection is broken.');

        sort($missingFromList);
        sort($missingStateFile);

        $this->assertSame(
            [],
            $missingFromList,
            'These money/ride mutation cases do not pin their writes yet. Add them to '
            ."ApiSnapshotTestCase::MONEY_AND_RIDE_MUTATION_CASES and regenerate the state files:\n  "
            .implode("\n  ", $missingFromList)
        );

        $this->assertSame(
            [],
            $missingStateFile,
            'These cases are listed for state snapshots but have no committed file under '
            ."tests/Snapshots/state. Regenerate with SNAPSHOT_UPDATE=1 and commit it:\n  "
            .implode("\n  ", $missingStateFile)
        );
    }

    #[Test]
    public function no_state_file_exists_for_a_case_that_is_not_a_mutation(): void
    {
        // A state file for a GET case would pin rows that the read path is not responsible for, and
        // it would silently rot. Every committed state file must belong to a listed case.
        $orphans = array_values(array_filter(
            StateSnapshotRepository::cases(),
            static fn (string $case): bool => ! in_array($case, ApiSnapshotTestCase::MONEY_AND_RIDE_MUTATION_CASES, true)
        ));

        sort($orphans);

        $this->assertSame([], $orphans, 'State files with no listed mutation case: '.implode(', ', $orphans));
    }

    private function isMoneyOrRideUri(string $uri): bool
    {
        foreach (self::MUTATING_PREFIXES as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }

    #[Test]
    public function every_read_only_post_exemption_names_a_real_non_get_case(): void
    {
        // Without this, the exemption map is an unchecked way to silence the coverage test: a typo
        // (or a route deleted from a module) would look like coverage, and a route that was exempt
        // while it was read-only could start writing without anybody noticing.
        $definitions = $this->caseDefinitions();

        foreach (array_keys(ApiSnapshotTestCase::READ_ONLY_POST_CASES) as $case) {
            $this->assertArrayHasKey($case, $definitions, "READ_ONLY_POST_CASES names '{$case}', which is not a snapshot case.");

            $this->assertNotSame(
                'GET',
                strtoupper((string) ($definitions[$case]['method'] ?? 'GET')),
                "READ_ONLY_POST_CASES names '{$case}' but it is a GET case - the exemption is pointless."
            );
        }
    }

    /**
     * Every case definition from every snapshot module, keyed by case name.
     *
     * `cases()` is static and DB-free by design, so this costs nothing.
     *
     * @return array<string, array<string, mixed>>
     */
    private function caseDefinitions(): array
    {
        $definitions = [];

        foreach (glob(__DIR__.'/*SnapshotTest.php') ?: [] as $file) {
            $class = __NAMESPACE__.'\\'.basename($file, '.php');

            if (! is_subclass_of($class, ApiSnapshotTestCase::class)) {
                continue;
            }

            foreach ($class::cases() as $case => $definition) {
                $definitions[$case] = $definition;
            }
        }

        return $definitions;
    }
}
