<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The leak detector behind GuardsAgainstCommittedLeaks.
 *
 * WHY
 *
 * `RefreshDatabase` wraps every test in a transaction and rolls it back. That guarantee is only
 * as strong as the test's own DB usage: a single DDL statement (ALTER/DROP/CREATE) commits the
 * transaction implicitly in MySQL, and everything the class has inserted so far becomes permanent.
 * The suite then continues on a database that is no longer empty, and a later class fails
 * somewhere else for a reason it did not cause. That is exactly how the RV45 pollution produced
 * 24 red CashRideFeeService tests from a fixture in an unrelated file.
 *
 * The detector runs AFTER the rollback, on a fresh connection, and names the table - and, through
 * the trait, the test class - that leaked. Zero rows is the only acceptable state.
 *
 * SCOPE: EVERY BASE TABLE (stage 0.5, step 5)
 *
 * Until step 5 the guard watched seven tables - the ones the RV45 pollution happened to damage.
 * A leak into any other table was invisible until something downstream broke, and "these are the
 * tables that matter" is a judgement that goes stale the moment a migration adds one. The list is
 * now DERIVED from the schema at run time: every base table except `migrations`, which the runner
 * populates on purpose, outside any test transaction. A new migration is therefore guarded the
 * moment it exists, and `CommittedRowLeakGuardTest` fails if this class ever stops deriving it.
 *
 * Cost: one extra query per test, with one scalar subquery per table, in a single round trip.
 */
final class CommittedRowLeakDetector
{
    /**
     * Tables that are EXPECTED to hold rows outside a test transaction, with the reason. The list
     * is deliberately tiny: `migrations` is written by the migration runner itself, and its rows
     * say which migrations ran, not which test leaked.
     *
     * @var array<string, string>
     */
    public const EXCLUDED = [
        'migrations' => 'written by the migration runner outside every test transaction, on purpose',
    ];

    /** @var array<int, string>|null resolved once per process */
    private static ?array $tables = null;

    /**
     * The guarded tables, derived from the live schema and sorted.
     *
     * @return array<int, string>
     */
    public static function guardTables(): array
    {
        if (self::$tables !== null) {
            return self::$tables;
        }

        try {
            $tables = array_map(
                static fn (mixed $table): string => is_array($table) ? (string) reset($table) : (string) $table,
                Schema::getTableListing()
            );
        } catch (\Throwable) {
            // A schema that cannot be listed (botched connection) must not become a false demand
            // that every table is empty; the per-table fallback will still guard what it can.
            return self::$tables = [];
        }

        $tables = array_values(array_filter(
            array_unique($tables),
            static fn (string $table): bool => $table !== '' && ! array_key_exists($table, self::EXCLUDED)
        ));

        sort($tables);

        return self::$tables = $tables;
    }

    /**
     * Count rows that survived the test's rollback.
     *
     * @return array<string, int> table => row count, only for tables with rows
     */
    public static function detect(): array
    {
        $tables = self::guardTables();

        if ($tables === []) {
            return [];
        }

        // ONE round trip: the guard runs after every test, so a COUNT per table would add
        // meaningful time to the whole suite. A single SELECT with scalar subqueries answers the
        // same question.
        try {
            return self::countInOneQuery($tables);
        } catch (\Throwable) {
            // Most likely a table in the cached list no longer exists (a class dropped it and
            // committed that DDL). Re-derive once, then give up on the single-query form.
        }

        self::$tables = null;

        $tables = self::guardTables();

        if ($tables === []) {
            return [];
        }

        try {
            return self::countInOneQuery($tables);
        } catch (\Throwable) {
            return self::countPerTable($tables);
        }
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<string, int>
     */
    private static function countInOneQuery(array $tables): array
    {
        $selects = array_map(
            static fn (string $table): string => '(SELECT COUNT(*) FROM `'.$table.'`) AS `'.$table.'`',
            $tables
        );

        $row = (array) DB::selectOne('SELECT '.implode(', ', $selects));

        $leaks = [];
        foreach ($row as $table => $count) {
            if ((int) $count > 0) {
                $leaks[$table] = (int) $count;
            }
        }

        return $leaks;
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<string, int>
     */
    private static function countPerTable(array $tables): array
    {
        $leaks = [];

        foreach ($tables as $table) {
            try {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $count = (int) DB::table($table)->count();
            } catch (\Throwable) {
                // A connection failure here is an infrastructure problem, not a leak. The guard
                // must never turn "the database went away" into a false accusation.
                continue;
            }

            if ($count > 0) {
                $leaks[$table] = $count;
            }
        }

        return $leaks;
    }

    /**
     * @param  array<string, int>  $leaks
     */
    public static function describe(array $leaks): string
    {
        return implode(', ', array_map(
            static fn (string $table, int $count): string => "{$table}={$count}",
            array_keys($leaks),
            array_values($leaks)
        ));
    }

    /** Only for tests that need to observe the derivation happening again. */
    public static function forgetResolvedTables(): void
    {
        self::$tables = null;
    }
}
