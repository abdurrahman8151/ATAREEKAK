<?php

namespace Tests\Feature\AppFuture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * STAGE 2 C2: every `X::class` in app/ must name a class that EXISTS.
 *
 * WHY THIS RATCHET EXISTS. Three times this phase moved a model and the suite - not any scanner - found a
 * kernel file that referenced it by NAMESPACE ADJACENCY, with no `use` line at all:
 *
 *   sec 301  `RefreshToken::user()`        -> `User` resolved implicitly
 *   sec 304  `UserRating`'s two relations  -> declared on `User` with no import
 *   sec 352  `Complaint::assignedAgent()`  -> `Employee`, removed by the attempted move; 56 tests died
 *                                            with `Class "App\Models\Employee" not found`
 *
 * A `use`-line measurement cannot see any of them, and a search for the OLD fully-qualified name cannot
 * either. `DanglingImportTest` (stage 1.5) catches a LITERAL `App\...` token that does not resolve - including
 * one written in a comment - but an UNQUALIFIED `Short::class` is invisible to it. This test closes that hole:
 * it resolves every `X::class` through the file's OWN namespace and import table (token-level, so comments and
 * strings cannot produce a hit) and asserts the resulting `App\...` class, interface, enum or trait exists.
 *
 * SCOPE AND LIMITS, stated because they matter:
 *   - only `App\...` names are checked; a missing third-party class is composer's problem, not a boundary one;
 *   - `self`, `static` and `parent` are skipped, as are `$var::class` and `Foo::{$expr}`;
 *   - a relation declared as a STRING is not visible here - that hole is named, not papered over, and the
 *     string form appears nowhere in app/ today;
 *   - the check runs on Windows, whose filesystem is case-insensitive while Composer's classmap is not, so a
 *     wrong-case reference still fails here because `class_exists()` goes through the autoloader.
 */
class ClassReferenceIntegrityTest extends TestCase
{
    /** Floors, so a broken scan cannot pass vacuously (measured 2026-11-16: 428 files, 332 references). */
    private const MIN_SCANNED_FILES = 400;

    private const MIN_CLASS_REFERENCES = 300;

    public function test_every_class_constant_in_app_resolves_to_a_real_class(): void
    {
        $appDir = dirname(__DIR__, 3).'/app';

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appDir) + 1));
            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_SCANNED_FILES,
            count($files),
            'The scan read only '.count($files).' PHP files under app/ - the floor is the proof it looked.'
        );

        $checked = 0;
        $violations = [];

        foreach ($files as $relative => $source) {
            $namespace = self::namespaceOf($source);
            $imports = self::importsOf($source);

            foreach (self::classReferences($source) as $short) {
                if (in_array($short, ['self', 'static', 'parent'], true)) {
                    continue;
                }

                $fqcn = $imports[$short] ?? ($namespace === '' ? $short : $namespace.'\\'.$short);

                if (! str_starts_with($fqcn, 'App\\')) {
                    continue;
                }

                $checked++;

                if (! self::exists($fqcn)) {
                    $violations[$relative.' -> '.$short.'::class'] = $fqcn;
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_CLASS_REFERENCES,
            $checked,
            'The scan resolved only '.$checked.' App class constants - it is not reading what it thinks it is.'
        );

        ksort($violations);
        $this->assertSame(
            [],
            $violations,
            "An `X::class` in app/ names a class that does not exist:\n  "
            .implode("\n  ", array_map(
                fn (string $key, string $fqcn): string => $key.' -> '.$fqcn,
                array_keys($violations),
                array_values($violations)
            ))
            ."\nIf this is a model that moved, add the import (or move the caller); if the caller is a KERNEL file,"
            .' it may only reach the module through its Contracts.'
        );
    }

    private static function exists(string $fqcn): bool
    {
        return class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn);
    }

    private static function namespaceOf(string $source): string
    {
        if (preg_match('/^\s*namespace\s+([^;{]+)\s*[;{]/m', $source, $matches) !== 1) {
            return '';
        }

        return trim($matches[1], " \t\n\r\\");
    }

    /**
     * The file's own import table: short name => FQCN. `use function` / `use const` and group imports are
     * skipped (the first two have no `::class` form; the third appears nowhere in app/).
     *
     * @return array<string, string>
     */
    private static function importsOf(string $source): array
    {
        preg_match_all('/^use\s+([^;]+);/m', $source, $matches);

        $imports = [];
        foreach ($matches[1] as $statement) {
            $statement = trim($statement);

            if (str_starts_with($statement, 'function ') || str_starts_with($statement, 'const ')) {
                continue;
            }
            if (str_contains($statement, '{')) {
                continue; // group import
            }

            $alias = null;
            if (preg_match('/\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $statement, $aliasMatch) === 1) {
                $alias = $aliasMatch[1];
                $statement = trim(substr($statement, 0, (int) strpos($statement, ' as ')));
            }

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $statement) !== 1) {
                continue;
            }

            $short = $alias ?? substr($statement, (int) strrpos($statement, '\\') + 1);
            $imports[$short] = ltrim($statement, '\\');
        }

        return $imports;
    }

    /**
     * Every `Short::class` in the file, read from TOKENS so a comment or a string can never produce a hit.
     *
     * @return array<int, string>
     */
    private static function classReferences(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $references = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $doubleColon = self::nextMeaningful($tokens, $i + 1);
            if ($doubleColon === null || ! is_array($tokens[$doubleColon]) || $tokens[$doubleColon][0] !== T_DOUBLE_COLON) {
                continue;
            }

            $classKeyword = self::nextMeaningful($tokens, $doubleColon + 1);
            if ($classKeyword === null || ! is_array($tokens[$classKeyword]) || $tokens[$classKeyword][0] !== T_CLASS) {
                continue;
            }

            $references[] = $token[1];
        }

        return $references;
    }

    /** @param  array<int, mixed>  $tokens */
    private static function nextMeaningful(array $tokens, int $index): ?int
    {
        for ($i = $index, $count = count($tokens); $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
