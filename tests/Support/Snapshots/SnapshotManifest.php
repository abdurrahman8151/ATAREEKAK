<?php

namespace Tests\Support\Snapshots;

use RuntimeException;

/**
 * The freeze on the snapshot corpus: one sha256 per committed file under tests/Snapshots.
 *
 * WHY A MANIFEST AND NOT JUST THE SNAPSHOTS THEMSELVES
 *
 * The snapshots already fail when a RESPONSE changes, but only for the cases a run executes, and
 * only when that run's own comparison reaches them. Nothing notices when a file is DELETED, when a
 * case is renamed and leaves its old file orphaned, or when a file is edited by hand - and nothing
 * notices at all if the affected module is not part of the run. A structural commit that quietly
 * drops a snapshot should be a red build, not a smaller corpus nobody counts.
 *
 * The manifest is the corpus's own inventory: every file, its hash, and the total. `SnapshotFreezeTest`
 * compares it in BOTH directions - a changed hash, a missing file and an extra file are each a
 * failure. It never writes: a freeze that repairs itself is not a freeze.
 *
 * REGENERATING IT (after a deliberate, reviewed change to the API or to the fixtures):
 *
 *     SNAPSHOT_UPDATE=1 php vendor/bin/phpunit tests/Feature/Snapshots   # rewrite the corpus
 *     php scripts/refresh-snapshot-manifest.php                          # rewrite the manifest
 *
 * Both commands are printed by the freeze test when it fails, so the author cannot miss the second.
 */
final class SnapshotManifest
{
    /** Relative to the repository root. */
    public const PATH = 'tests/Snapshots/MANIFEST.json';

    /** The corpus directory the manifest freezes, relative to the repository root. */
    public const ROOT = 'tests/Snapshots';

    public static function isUpdating(): bool
    {
        return getenv('SNAPSHOT_UPDATE') === '1';
    }

    public static function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function path(): string
    {
        return self::repositoryRoot().'/'.self::PATH;
    }

    public static function corpusRoot(): string
    {
        return self::repositoryRoot().'/'.self::ROOT;
    }

    /**
     * Every frozen file, relative to the corpus root, with its hash. Sorted by path so the manifest
     * is stable and a diff of it is readable.
     *
     * @return array<string, string>
     */
    public static function build(): array
    {
        $root = self::corpusRoot();
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if ($relative === 'MANIFEST.json') {
                continue;
            }

            $files[$relative] = hash_file('sha256', $file->getPathname());
        }

        ksort($files);

        return $files;
    }

    /**
     * The manifest as committed, or null when there is none (which is itself a failure: an absent
     * manifest freezes nothing).
     *
     * @return array<string, string>|null
     */
    public static function load(): ?array
    {
        $path = self::path();

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! isset($decoded['files']) || ! is_array($decoded['files'])) {
            throw new RuntimeException('The snapshot manifest is unreadable: '.self::PATH);
        }

        /** @var array<string, string> $files */
        $files = $decoded['files'];

        return $files;
    }

    /** Write the manifest for the corpus as it stands. Deliberately reachable only from the script. */
    public static function save(): void
    {
        $files = self::build();

        $payload = [
            'generated_by' => 'scripts/refresh-snapshot-manifest.php',
            'note' => 'Freeze on tests/Snapshots: every corpus file and its sha256. Regenerate with the script after a deliberate corpus change; a changed hash, a missing file or an extra file turns SnapshotFreezeTest red.',
            'count' => count($files),
            'files' => $files,
        ];

        file_put_contents(
            self::path(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );
    }

    /**
     * Compare the committed corpus against the manifest.
     *
     * @return array{missing: array<int, string>, added: array<int, string>, changed: array<int, string>}
     */
    public static function diff(): array
    {
        $frozen = self::load() ?? [];
        $actual = self::build();

        return [
            'missing' => array_values(array_diff(array_keys($frozen), array_keys($actual))),
            'added' => array_values(array_diff(array_keys($actual), array_keys($frozen))),
            'changed' => array_values(array_filter(
                array_keys($frozen),
                static fn (string $path): bool => isset($actual[$path]) && $actual[$path] !== $frozen[$path]
            )),
        ];
    }

    /** The command that repairs a stale manifest, quoted in every failure message. */
    public static function refreshCommand(): string
    {
        return 'php scripts/refresh-snapshot-manifest.php';
    }
}
