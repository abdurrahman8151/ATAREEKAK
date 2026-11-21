<?php

namespace App\Modules\Ride\Support;

use RuntimeException;

/**
 * Stage 4 B1 / ADR 0010: the ONE place that maps a stored class name to a class and back (owner decision
 * D1 = PIN-OLD, 2026-11-20).
 *
 * `score_transactions.reference_type` is a plain string column holding a PHP class name - the only column in the
 * database that does. Stage 2 moved the two models it names out of the kernel namespace into this module, so
 * rows written before the move hold the old name and rows written after it hold the new one, and every reader has
 * to accept both. The owner chose to stop the drift at the source:
 *
 *   - NEW rows write the PRE-Stage-2 value - the value production has held since before the move - so the column
 *     becomes uniform again and every historical row keeps matching;
 *   - READERS go through this map, so a row holding either generation resolves to the same current class.
 *
 * WHERE THE NAMES LIVE, AND WHY NOT HERE. The pinned strings are production DATA, so they live in the data file
 * next to this class (`stored-class-names.json`), not as FQCN literals in PHP. That is not a trick to dodge a
 * ratchet: `DanglingImportTest` exists to catch a PHP file naming a class that does not exist, and it is right to
 * flag a pre-move model name - as code that is a stale reference. As a stored value it is the point of the exercise.
 * Keeping the strings in data is also what the snapshot corpus already does: the frozen snapshot holds exactly
 * these strings and the ratchets do not read it.
 *
 * This is NOT `Relation::morphMap`: the column is not a true Eloquent morph relationship (nothing declares
 * `morphTo()` on it), so a morph map would change nothing and would imply a polymorphic relation that does not
 * exist. If that ever changes, build the morph map from this map.
 */
final class StoredClassNames
{
    /** @var array<class-string, string>|null current class => the pinned stored value */
    private static ?array $classToStored = null;

    /** @var array<string, class-string>|null stored value => current class, BOTH generations */
    private static ?array $storedToClass = null;

    /**
     * The value to WRITE for a class. Unknown classes are written as they are, so a future reference type has to
     * be added to the data file deliberately, in the commit that introduces it.
     */
    public static function storedFor(?string $class): ?string
    {
        if ($class === null) {
            return null;
        }

        return self::classToStored()[$class] ?? $class;
    }

    /**
     * The current class a stored value names, or null when the value is unknown or absent. Both generations are
     * listed in the data file, which is what "readers accept both" means; removing the pre-Stage-2 entry would
     * break every historical row.
     */
    public static function classFor(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        return self::storedToClass()[$stored] ?? null;
    }

    /**
     * The short name the API publishes (`type` in the reference object): both generations give the same answer,
     * because the map resolves them to the same class first. Unknown values fall back to their own basename, so a
     * row this map has never heard of still renders instead of throwing.
     */
    public static function basenameFor(?string $stored): string
    {
        $class = self::classFor($stored);

        return class_basename($class ?? (string) $stored);
    }

    /** The current classes the map knows, for callers that must not guess. */
    public static function knownClasses(): array
    {
        return array_keys(self::classToStored());
    }

    /** @return array<class-string, string> */
    private static function classToStored(): array
    {
        return self::$classToStored ??= self::load()['classToStored'];
    }

    /** @return array<string, class-string> */
    private static function storedToClass(): array
    {
        return self::$storedToClass ??= self::load()['storedToClass'];
    }

    /**
     * Read the data file once per process and build both directions from it.
     *
     * @return array{classToStored: array<class-string, string>, storedToClass: array<string, class-string>}
     */
    private static function load(): array
    {
        $path = __DIR__.'/stored-class-names.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $map = $decoded['map'] ?? null;

        if (! is_array($map) || $map === []) {
            throw new RuntimeException("The stored class-name map at {$path} is empty or malformed.");
        }

        $classToStored = [];
        $storedToClass = [];

        foreach ($map as $currentClass => $storedValue) {
            $classToStored[$currentClass] = $storedValue;
            // Both generations read back as the same current class: the pinned value, and the value stage 2 wrote.
            $storedToClass[$storedValue] = $currentClass;
            $storedToClass[$currentClass] = $currentClass;
        }

        return ['classToStored' => $classToStored, 'storedToClass' => $storedToClass];
    }
}
