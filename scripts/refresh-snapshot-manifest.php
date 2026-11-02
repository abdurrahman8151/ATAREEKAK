<?php

/**
 * Refresh the freeze on the snapshot corpus (tests/Snapshots/MANIFEST.json).
 *
 * Run this ONLY after a deliberate, reviewed corpus change - the same rule the SNAPSHOT_UPDATE=1
 * generation run follows:
 *
 *     SNAPSHOT_UPDATE=1 php vendor/bin/phpunit tests/Feature/Snapshots
 *     php scripts/refresh-snapshot-manifest.php
 *
 * It writes nothing else and needs no database: it only hashes the files under tests/Snapshots.
 */
require __DIR__.'/../vendor/autoload.php';

use Tests\Support\Snapshots\SnapshotManifest;

$before = SnapshotManifest::load() ?? [];

SnapshotManifest::save();

$after = SnapshotManifest::build();

$added = array_diff(array_keys($after), array_keys($before));
$removed = array_diff(array_keys($before), array_keys($after));
$changed = array_filter(
    array_keys($before),
    static fn (string $path): bool => isset($after[$path]) && $after[$path] !== $before[$path]
);

printf("Wrote %s\n", SnapshotManifest::PATH);
printf("Frozen files: %d (added %d, removed %d, changed %d)\n", count($after), count($added), count($removed), count($changed));

foreach (['added' => $added, 'removed' => $removed, 'changed' => $changed] as $kind => $paths) {
    foreach ($paths as $path) {
        printf("  %s %s\n", $kind, $path);
    }
}
