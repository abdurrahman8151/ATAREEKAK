<?php

namespace Tests\Feature\Snapshots;

use Tests\Support\Snapshots\SnapshotManifest;
use Tests\TestCase;

/**
 * The freeze on the snapshot corpus (stage 0.5, step 8).
 *
 * The per-case comparisons already fail when a RESPONSE changes - but only for the cases the run
 * executes, and only for the files it reaches. Nothing in the suite notices a snapshot that was
 * DELETED, a case renamed and its old file orphaned, an extra file added, or a file edited by hand
 * in a module this run never touched. This test is that notice: `tests/Snapshots/MANIFEST.json`
 * records every corpus file and its sha256, and any difference in either direction is a failure that
 * names the files.
 *
 * It deliberately does not repair itself. Fixing a red freeze means one of two conversations:
 *
 *   - the API changed on purpose -> regenerate the corpus and the manifest in the same, reviewed
 *     commit (`SNAPSHOT_UPDATE=1 php vendor/bin/phpunit tests/Feature/Snapshots`, then
 *     `php scripts/refresh-snapshot-manifest.php`);
 *   - the API changed by accident -> the freeze just did its job.
 *
 * No database is needed: it reads files.
 */
final class SnapshotFreezeTest extends TestCase
{
    public function test_the_manifest_exists_and_is_not_empty(): void
    {
        $manifest = SnapshotManifest::load();

        $this->assertNotNull(
            $manifest,
            'There is no snapshot manifest. The corpus is unfrozen without it: run '.SnapshotManifest::refreshCommand()
        );

        $this->assertGreaterThan(
            150,
            count($manifest),
            'The manifest freezes '.count($manifest).' files, which is too few to be the whole corpus.'
        );
    }

    public function test_every_committed_snapshot_file_matches_the_manifest_hash(): void
    {
        $diff = SnapshotManifest::diff();

        $this->assertSame([], $diff['changed'], $this->describe($diff['changed'], 'changed since the manifest was written'));
        $this->assertSame([], $diff['missing'], $this->describe($diff['missing'], 'frozen by the manifest but no longer on disk'));
        $this->assertSame([], $diff['added'], $this->describe($diff['added'], 'on disk but not frozen by the manifest'));
    }

    /**
     * A manifest is only a freeze if it covers everything: the count in the header must agree with
     * the number of hashes, or a truncated manifest would silently unfreeze the tail of the corpus.
     */
    public function test_the_manifest_header_count_agrees_with_the_hashes_it_lists(): void
    {
        $decoded = json_decode((string) file_get_contents(SnapshotManifest::path()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            count($decoded['files']),
            (int) $decoded['count'],
            'The manifest count does not match the number of files it lists.'
        );

        foreach ($decoded['files'] as $path => $hash) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{64}$/',
                (string) $hash,
                "The manifest hash for {$path} is not a sha256."
            );
        }
    }

    /** Every corpus file is frozen, and the only exclusion is the manifest itself. */
    public function test_the_frozen_set_is_exactly_the_corpus_minus_the_manifest(): void
    {
        $frozen = array_keys(SnapshotManifest::load() ?? []);

        $this->assertNotContains('MANIFEST.json', $frozen, 'The manifest must not freeze itself.');
        $this->assertContains('query-budgets.json', $frozen, 'The query budgets are part of the corpus contract and must be frozen.');
        $this->assertContains('api/wallet/balance.json', $frozen, 'A known response snapshot is missing from the manifest.');
        $this->assertContains('state/wallet/request-charge.json', $frozen, 'A known state snapshot is missing from the manifest.');

        foreach ($frozen as $path) {
            $this->assertFileExists(
                SnapshotManifest::corpusRoot().'/'.$path,
                "The manifest freezes {$path}, which does not exist."
            );
        }
    }

    /**
     * The freeze must not be satisfied by a manifest that is stale in the one way that matters most:
     * the API's own shape. This pins that the two halves of the corpus are BOTH represented - a
     * response snapshot and its state twin - so a future refactor cannot narrow the freeze to
     * whichever half was easier to regenerate.
     */
    public function test_response_and_state_snapshots_are_both_frozen(): void
    {
        $frozen = array_keys(SnapshotManifest::load() ?? []);

        $responses = array_filter($frozen, static fn (string $path): bool => str_starts_with($path, 'api/'));
        $states = array_filter($frozen, static fn (string $path): bool => str_starts_with($path, 'state/'));

        $this->assertGreaterThan(150, count($responses), 'The response corpus looks truncated.');
        $this->assertGreaterThan(30, count($states), 'The state corpus looks truncated.');
    }

    /** @param array<int, string> $paths */
    private function describe(array $paths, string $what): string
    {
        return "These snapshot files are {$what}:\n  ".implode("\n  ", $paths)
            ."\nIf the change was deliberate, regenerate the corpus (SNAPSHOT_UPDATE=1 php vendor/bin/phpunit tests/Feature/Snapshots)"
            .' and then run '.SnapshotManifest::refreshCommand().'.';
    }
}
