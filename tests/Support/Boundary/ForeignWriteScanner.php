<?php

namespace Tests\Support\Boundary;

/**
 * Stage 4 A1: the cross-module WRITE scanner.
 *
 * The ownership ratchets (ModelOwnershipTest, BoundaryDependencyTest) police what a module may NAME. None of them
 * can see a module WRITING another module's row through a type it is allowed to name - which is exactly how
 * `Money\Services\BookingCharge::snapshotBookingCharge()` came to force-fill Money's snapshot onto a Ride-owned
 * booking row while every ratchet stayed green: `BookingView` is Ride's own contract, so naming it is legal, and
 * writing through it was invisible.
 *
 * This class finds those writes. It is deliberately a plain class with a PURE entry point (`scanSource`) so the
 * test suite can feed it a synthetic file and prove the detector fires - the probe is a self-test, not a patch to
 * a tracked file.
 *
 * What counts as a foreign write:
 *   1. `DB::table('<owned table>')->update/insert/delete/upsert/increment/decrement/truncate` from a module that
 *      does not own the table;
 *   2. a static write (`create`, `insert`, `updateOrCreate`, `firstOrCreate`, `destroy`) on another module's Model
 *      class;
 *   3. a write verb (`forceFill`, `save`, `update`, `delete`, `increment`, `decrement`) on a parameter typed with
 *      another module's read-only VIEW contract (`BookingView`, `RideView`) - the shape the known site takes.
 *
 * Exempt: `app/Console/Testing/` (ADR 0005 - the dev-only tools fabricate state by design, and they are loaded only
 * in local/testing).
 */
final class ForeignWriteScanner
{
    public const EXEMPT_PREFIX = 'Console/Testing/';

    /** Views a module may name but must not write through, mapped to the module that owns them. */
    private const FOREIGN_WRITE_VIEWS = [
        'BookingView' => 'Ride',
        'RideView' => 'Ride',
    ];

    private const WRITE_VERBS = ['forceFill', 'save', 'update', 'delete', 'increment', 'decrement'];

    /**
     * @param  array<string, string>  $tableOwners  table => owning module
     */
    public function __construct(private readonly array $tableOwners) {}

    /**
     * Walk `app/Modules/**` and return every foreign write, keyed by file relative to `app/`.
     *
     * @return array<string, array<int, string>> file => findings
     */
    public function scanTree(string $appPath): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appPath.'/Modules', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }

        sort($files);

        $found = [];
        foreach ($files as $path) {
            if (! str_ends_with($path, '.php')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($path, strlen($appPath) + 1));
            $module = explode('/', $relative)[1] ?? '';

            if ($module === '' || str_contains($relative, self::EXEMPT_PREFIX)) {
                continue;
            }

            $findings = $this->scanSource((string) file_get_contents($path), $module);
            if ($findings !== []) {
                $found[$relative] = $findings;
            }
        }

        return $found;
    }

    /**
     * The pure detector: one module's source in, findings out. Used by the tree scan and by the self-test.
     *
     * @return array<int, string>
     */
    public function scanSource(string $source, string $module): array
    {
        $findings = [];

        // 1) raw table writes on a table this module does not own
        if (preg_match_all(
            "/DB::table\(\s*'(\w+)'\s*\)\s*->\s*(update|insert|delete|upsert|increment|decrement|truncate)\s*\(/",
            $source,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $match) {
                $table = $match[1];
                $owner = $this->tableOwners[$table] ?? null;
                if ($owner !== null && $owner !== $module) {
                    $findings[] = "DB::table('{$table}')->{$match[2]}() writes {$owner}'s table";
                }
            }
        }

        // 2) a static write on another module's Model class
        if (preg_match_all(
            '/use App\\\\Modules\\\\(\w+)\\\\Models\\\\(\w+);/',
            $source,
            $imports,
            PREG_SET_ORDER,
        )) {
            foreach ($imports as $import) {
                [$all, $owner, $model] = $import;
                if ($owner === $module) {
                    continue;
                }
                if (preg_match_all('/'.$model.'::(create|insert|updateOrCreate|firstOrCreate|destroy)\(/', $source, $writes)) {
                    foreach ($writes[1] as $verb) {
                        $findings[] = "{$model}::{$verb}() writes {$owner}'s model";
                    }
                }
            }
        }

        // 3) a write verb on a parameter typed with another module's read-only view
        foreach (self::FOREIGN_WRITE_VIEWS as $view => $owner) {
            if ($owner === $module) {
                continue;
            }
            if (! preg_match_all('/'.preg_quote($view, '/').'\s+&?\$(\w+)/', $source, $params)) {
                continue;
            }

            foreach (array_unique($params[1]) as $variable) {
                foreach (self::WRITE_VERBS as $verb) {
                    if (preg_match('/\$'.preg_quote($variable, '/').'\s*->\s*'.$verb.'\s*\(/', $source)) {
                        $findings[] = "\${$variable}->{$verb}() writes {$owner}'s row through {$view}";
                    }
                }
            }
        }

        return array_values(array_unique($findings));
    }
}
