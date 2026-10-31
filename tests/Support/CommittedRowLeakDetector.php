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
 * Scope, stated plainly: these are the money/identity/ride tables whose leaked rows change what
 * other tests see. Catalogues and lead tables are not counted, because a leaked row there cannot
 * corrupt a booking, a balance or a login.
 */
final class CommittedRowLeakDetector
{
    /** @var array<int, string> */
    public const TABLES = [
        'wallets',
        'users',
        'rides',
        'bookings',
        'user_ratings',
        'profile_comments',
        'refresh_tokens',
    ];

    /**
     * Count rows that survived the test's rollback.
     *
     * @return array<string, int> table => row count, only for tables with rows
     */
    public static function detect(): array
    {
        // ONE round trip: the guard runs after every test, so seven separate COUNTs would add
        // meaningful time to the whole suite. A single SELECT with scalar subqueries answers the
        // same question; the per-table fallback below covers a schema where one table is absent.
        try {
            $selects = array_map(
                static fn (string $table): string => "(SELECT COUNT(*) FROM `{$table}`) AS `{$table}`",
                self::TABLES
            );

            $row = (array) DB::selectOne('SELECT '.implode(', ', $selects));

            $leaks = [];
            foreach ($row as $table => $count) {
                if ((int) $count > 0) {
                    $leaks[$table] = (int) $count;
                }
            }

            return $leaks;
        } catch (\Throwable) {
            // Fall through to the per-table form, which can skip a table that does not exist.
        }

        $leaks = [];

        foreach (self::TABLES as $table) {
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
}
