<?php

namespace Tests\Support\Snapshots;

/**
 * Reads and writes the committed STATE snapshots under tests/Snapshots/state.
 *
 * A response snapshot pins what a mutation route RETURNED. It cannot see the write: a route that
 * reports "wallet charged" and writes nothing, or writes the wallet but not the ledger, returns the
 * same JSON body as one that does all three. This store pins the rows instead - balances, ledger
 * legs, wallet transactions, booking status and escrow, ride seats - captured AFTER the call, in the
 * same RequestAnimation-free way for a generation run and a verifying run:
 *
 *   tests/Snapshots/state/<case>.json
 *
 * Same asymmetry as the response snapshots: written only under SNAPSHOT_UPDATE=1, compared
 * byte-for-byte otherwise, so a test can never revise its own expectation.
 */
final class StateSnapshotRepository
{
    public static function root(): string
    {
        return dirname(__DIR__, 3).'/tests/Snapshots/state';
    }

    public static function path(string $case): string
    {
        return self::root().'/'.$case.'.json';
    }

    public static function exists(string $case): bool
    {
        return is_file(self::path($case));
    }

    public static function load(string $case): mixed
    {
        $path = self::path($case);

        if (! is_file($path)) {
            return null;
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function save(string $case, mixed $state): void
    {
        $path = self::path($case);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create state snapshot directory: {$directory}");
        }

        file_put_contents($path, self::encode($state)."\n");
    }

    /**
     * The SAME canonical encoder the response snapshots use: object keys sorted, array order kept,
     * pretty-printed, so a state diff is readable and a comparison is byte-exact.
     */
    public static function encode(mixed $state): string
    {
        return SnapshotRepository::encode($state);
    }

    public static function isUpdating(): bool
    {
        return SnapshotRepository::isUpdating();
    }

    /** Every case that has a committed state snapshot, for inventory checks. */
    public static function cases(): array
    {
        $files = glob(self::root().'/*.json') ?: [];

        return array_map(
            static fn (string $path): string => basename($path, '.json'),
            $files
        );
    }
}
