<?php

namespace Tests\Support\Snapshots;

/**
 * Reads and writes the committed route snapshots under tests/Snapshots/api.
 *
 * One file per case, named after the case key (`auth/login-success`), holding exactly:
 *
 *   {"status": 200, "json": { ...normalised body... }}
 *
 * Files are committed. They are only (re)written when SNAPSHOT_UPDATE=1 is set in the
 * environment, which the batch author runs once per module to record the CURRENT behaviour.
 * A normal run never writes: it compares. That asymmetry is deliberate - a test that regenerates
 * its own expectation cannot fail, which is the whole failure mode snapshots exist to avoid.
 */
final class SnapshotRepository
{
    public static function root(): string
    {
        return dirname(__DIR__, 3).'/tests/Snapshots';
    }

    public static function path(string $case): string
    {
        return self::root().'/api/'.$case.'.json';
    }

    public static function exists(string $case): bool
    {
        return is_file(self::path($case));
    }

    /**
     * @return array{status: int, json: mixed}|null
     */
    public static function load(string $case): ?array
    {
        $path = self::path($case);

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return ['status' => (int) $decoded['status'], 'json' => $decoded['json']];
    }

    public static function save(string $case, int $status, mixed $json): void
    {
        $path = self::path($case);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create snapshot directory: {$directory}");
        }

        $payload = ['status' => $status, 'json' => $json];

        file_put_contents(
            $path,
            self::encode($payload)."\n"
        );
    }

    /**
     * Canonical encoding used for BOTH the stored file and the comparison, so the assertion is
     * byte-exact rather than structurally approximate ({} and [] stay distinguishable, arrays keep
     * the order the response gave them, floats keep their scale).
     *
     * OBJECT KEYS ARE SORTED, array order is not. A JSON object is unordered by definition, and
     * this app legitimately emits the same object with a different key order depending on how the
     * model was hydrated (a `create()`d model keeps its insert order, a re-read one keeps the table
     * column order - which is why `available_seats` moved when the full suite ran instead of just
     * this directory). Pinning that accidental order would make snapshots fail on a difference no
     * client can observe, while ARRAY order (a list of rides, a page of notifications) is a real
     * fact of the response and stays compared exactly.
     */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::sortObjectKeys($value),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Recursively sort the keys of every JSON OBJECT, leaving JSON ARRAYS in their own order.
     */
    private static function sortObjectKeys(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $copy = (array) $value;

            if (array_is_list($copy)) {
                return array_map(self::sortObjectKeys(...), $copy);
            }

            ksort($copy);

            return (object) array_map(self::sortObjectKeys(...), $copy);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::sortObjectKeys(...), $value);
            }

            ksort($value);

            return array_map(self::sortObjectKeys(...), $value);
        }

        return $value;
    }

    public static function isUpdating(): bool
    {
        return getenv('SNAPSHOT_UPDATE') === '1';
    }
}
