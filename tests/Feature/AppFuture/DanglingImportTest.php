<?php

declare(strict_types=1);

namespace Tests\Feature\AppFuture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Stage 1.5 item 1: no FQCN written in the repository may name a class that no longer exists.
 *
 * The stage 1 moves rewrote imports by path, and one of them was missed: `RideServiceProvider`
 * still imported `app/Services/Score/ScorePolicyFactory` (the class is
 * `App\Modules\Ride\Domain\ScorePolicyFactory`), so `$this->app->singleton(ScorePolicyFactory::class)`
 * registered a string that can never resolve. A path-based move cannot notice that by itself, which
 * is why the check is a name check: every `App\...` token in the scanned trees must resolve to a real
 * class, interface, trait or enum through the autoloader - the same autoloader the container uses.
 *
 * Two rules keep it honest rather than noisy:
 *
 *   - a token that is a declared NAMESPACE in the scanned tree is not a class reference, and neither
 *     is a token that is a PREFIX of one (the character after it is a backslash, `<`, `.` or a
 *     backtick). `App\Modules\<Name>\Services\...` in a comment is prose, not a class name.
 *   - only `App\...` tokens count. Vendor and global names are not this ratchet's business.
 *
 * Baseline: 0 dangling tokens. Floors: the walk must reach the measured file count and the token
 * count, so deleting the tree (or breaking the tokenizer) fails instead of passing vacuously.
 */
final class DanglingImportTest extends TestCase
{
    /** Measured 2026-11-03: 776 files, 2337 `App\` tokens. Floors are the measured value minus slack. */
    private const MIN_SCANNED_FILES = 750;

    private const MIN_TOKENS_CHECKED = 2000;

    /** @var array<int, string> */
    private const TREES = ['app', 'tests', 'routes', 'config', 'database', 'scripts'];

    public function test_every_app_fqcn_in_the_scanned_trees_resolves_to_a_real_type(): void
    {
        $sources = [];
        foreach (self::TREES as $tree) {
            $dir = base_path($tree);
            if (! is_dir($dir)) {
                continue;
            }

            $walk = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($walk as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        ksort($sources);

        $this->assertGreaterThanOrEqual(
            self::MIN_SCANNED_FILES,
            count($sources),
            'The dangling-import scan reached '.count($sources).' files, below the floor - it is not '
            .'looking at the tree any more (a move must not make this ratchet pass vacuously).'
        );

        $namespaces = [];
        foreach ($sources as $source) {
            if (preg_match_all('/^\s*namespace\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*[;{]/m', $source, $ns)) {
                foreach ($ns[1] as $declared) {
                    $namespaces[$declared] = true;
                }
            }
        }

        $checked = 0;
        $dangling = [];
        foreach ($sources as $path => $source) {
            [$tokens, $found] = $this->appTokens($source, $namespaces);
            $checked += $found;
            foreach ($tokens as $fqcn) {
                $dangling[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' -> '.$fqcn;
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_TOKENS_CHECKED,
            $checked,
            'Only '.$checked.' `App\\` tokens were checked, below the floor - the tokenizer lost its '
            .'grip on the tree, so "0 dangling" would prove nothing.'
        );

        sort($dangling);

        $this->assertSame(
            [],
            $dangling,
            "These names do not resolve to a class, interface, trait or enum:\n  ".implode("\n  ", $dangling)
        );
    }

    public function test_the_scan_finds_a_dangling_name_and_accepts_a_real_one(): void
    {
        // Non-vacuity, proved on a source string instead of on the tree: if the checker cannot see a
        // class that does not exist, the "0 dangling" assertion above means nothing.
        // The two bogus names are assembled at runtime: written literally they would be dangling
        // tokens in THIS file, and the ratchet above scans this file too.
        $missing = 'App'.'\\'.'NoSuchNamespace'.'\\'.'MissingThing';
        $gone = 'App'.'\\'.'AlsoMissing'.'\\'.'Gone';

        $source = str_replace(
            ['__MISSING__', '__GONE__'],
            [$missing, $gone],
            <<<'PHP'
            <?php
            namespace App\Example;
            use App\Models\User;
            use __MISSING__;
            final class Example
            {
                public function run(): void
                {
                    new \__GONE__();
                    User::query();
                }
            }
            PHP
        );

        [$dangling] = $this->appTokens($source, []);

        $this->assertContains($missing, $dangling);
        $this->assertContains($gone, $dangling);
        $this->assertNotContains('App\Models\User', $dangling, 'A real class must not be reported.');
    }

    /**
     * Every `App\...` token in the source that is a class reference and does not resolve.
     *
     * @param  array<string, bool>  $namespaces  declared namespaces, which are legitimate prefixes
     * @return array{0: array<int, string>, 1: int} the dangling tokens and how many were checked
     */
    private function appTokens(string $source, array $namespaces): array
    {
        if (! preg_match_all(
            '/App\\\\[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return [[], 0];
        }

        $dangling = [];
        $checked = 0;
        foreach ($matches[0] as [$fqcn, $offset]) {
            $after = substr($source, $offset + strlen($fqcn), 1);
            if ($after === '{' || $after === '\\') {
                continue; // `use App\Foo\{Bar, Baz};` or a `<Name>` placeholder - a prefix, not a name
            }

            $before = $offset > 0 ? substr($source, $offset - 9, 9) : '';
            if (preg_match('/namespace\s*$/', $before)) {
                continue; // the declaration itself
            }

            $checked++;

            if (isset($namespaces[$fqcn])) {
                continue;
            }
            if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn)) {
                continue;
            }

            $dangling[] = $fqcn;
        }

        return [array_values(array_unique($dangling)), $checked];
    }
}
