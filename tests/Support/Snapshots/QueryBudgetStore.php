<?php

namespace Tests\Support\Snapshots;

/**
 * The committed query-count baseline for every snapshotted route.
 *
 * WHY THIS EXISTS
 *
 * A snapshot pins the RESPONSE. It cannot see the work behind it: an N+1 introduced in a
 * controller returns the same JSON, only slower and with more load on the database. This store
 * records, per route case, how many queries the call issued, and the test fails when the count
 * RISES above the recorded number.
 *
 * SHRINK-ONLY
 *
 * A count BELOW the baseline passes but is reported so the baseline can be lowered. It is never
 * raised automatically and there is no "update the baseline to make it pass" path in a normal
 * run: recording only happens under SNAPSHOT_UPDATE=1, which rewrites the whole file from the
 * measured counts. That is the same asymmetry as the snapshots themselves.
 */
final class QueryBudgetStore
{
    public static function path(): string
    {
        return SnapshotRepository::root().'/query-budgets.json';
    }

    /**
     * @return array<string, int>
     */
    public static function all(): array
    {
        $path = self::path();

        if (! is_file($path)) {
            return [];
        }

        /** @var array<string, int> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        ksort($decoded);

        return $decoded;
    }

    public static function get(string $case): ?int
    {
        return self::all()[$case] ?? null;
    }

    /**
     * Merge measured counts into the committed baseline.
     *
     * Only ever called from the SNAPSHOT_UPDATE generation run, and it refuses to RAISE an
     * existing entry: even in generation mode a budget may only shrink, because a count that grew
     * is the exact regression this file exists to catch.
     *
     * @param  array<string, int>  $counts
     */
    public static function record(array $counts): void
    {
        $existing = self::all();
        $merged = $existing;

        foreach ($counts as $case => $count) {
            if (! array_key_exists($case, $existing)) {
                $merged[$case] = $count;

                continue;
            }

            // Shrink-only, even during generation.
            $merged[$case] = min($existing[$case], $count);
        }

        ksort($merged);

        file_put_contents(
            self::path(),
            SnapshotRepository::encode($merged)."\n"
        );
    }

    /**
     * Cases present in the baseline that no longer exist in the snapshot tree. Reported as a
     * failure by the ratchet test so a deleted route cannot leave a free pass behind.
     *
     * @param  array<int, string>  $liveCases
     * @return array<int, string>
     */
    public static function orphans(array $liveCases): array
    {
        $live = array_flip($liveCases);

        return array_values(array_filter(
            array_keys(self::all()),
            static fn (string $case): bool => ! isset($live[$case])
        ));
    }
}
