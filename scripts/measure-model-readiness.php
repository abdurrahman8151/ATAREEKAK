<?php

/**
 * Stage 2 C2 measurement tool (not a test): what would a move of each remaining kernel model touch?
 *
 * For every model still in app/Models it reports:
 *   - the relations it declares, flagging targets that would become ILLEGAL module->kernel references once
 *     the model lives in its owning module (only `User`, the principal, is always allowed);
 *   - every file in app/ that USES the model, split into files that import it (a mechanical rewrite) and
 *     files that use it BARE - no import, resolved by namespace adjacency. Bare uses are the hazard that has
 *     caused three rollbacks in this phase (secs 301, 304, 352), and they are concentrated in app/Models
 *     itself: a kernel model referencing another kernel model that moves away;
 *   - how many test files reference it.
 *
 * Run from the repository root:  php scripts/measure-model-readiness.php
 *
 * It is a READ-ONLY analysis: it writes nothing and asserts nothing. The numbers it prints are recorded in
 * docs/audit/App future audit review r2.md (sec 354) and are the input to the remaining owner decisions.
 */
$repo = dirname(__DIR__);

$targets = [
    'Ride' => 'Ride', 'Booking' => 'Ride', 'NoshowReport' => 'Ride',
    'LedgerEntry' => 'Money', 'Wallet' => 'Money', 'WalletRequest' => 'Money', 'WalletTransaction' => 'Money',
    'Complaint' => 'Console', 'ComplaintAttachment' => 'Console',
    'Profile' => 'Identity', 'ProfileComment' => 'Identity', 'UserRating' => 'Identity',
];

function phpFiles(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }
    sort($out);

    return $out;
}

function namespaceOf(string $source): string
{
    return preg_match('/^\s*namespace\s+([^;{]+)\s*[;{]/m', $source, $m) === 1 ? trim($m[1], " \t\n\r\\") : '';
}

/** @return array<string,string> short => fqcn */
function importsOf(string $source): array
{
    preg_match_all('/^use\s+([^;]+);/m', $source, $matches);
    $imports = [];
    foreach ($matches[1] as $statement) {
        $statement = trim($statement);
        if (str_starts_with($statement, 'function ') || str_starts_with($statement, 'const ') || str_contains($statement, '{')) {
            continue;
        }
        $alias = null;
        if (preg_match('/\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $statement, $am) === 1) {
            $alias = $am[1];
            $statement = trim(substr($statement, 0, (int) strpos($statement, ' as ')));
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $statement) !== 1) {
            continue;
        }
        $imports[$alias ?? substr($statement, (int) strrpos($statement, '\\') + 1)] = ltrim($statement, '\\');
    }

    return $imports;
}

/** Does the source use $short as a CODE identifier (T_STRING)? Comments and strings cannot match. */
function usesShortName(string $source, string $short): bool
{
    // Drop the file's own namespace and use declarations first: their qualified-name tokens would
    // otherwise match a model whose name is the namespace's last segment (App\DTOs\Ride, ...\Wallet).
    $body = (string) preg_replace('/^\s*namespace\s+[^;{]+[;{]/m', '', $source);
    $body = (string) preg_replace('/^use\s+[^;]+;/m', '', $body);

    foreach (token_get_all($body) as $token) {
        if (is_array($token) && $token[0] === T_STRING && $token[1] === $short) {
            return true;
        }
        // A qualified name token (PHP 8) still contains the short name at its end.
        if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && substr($token[1], (int) strrpos($token[1], '\\') + 1) === $short) {
            return true;
        }
    }

    return false;
}

$appFiles = phpFiles($repo.'/app');
$testFiles = phpFiles($repo.'/tests');

$totals = ['import' => 0, 'bare' => 0];

foreach ($targets as $model => $owner) {
    $modelPath = $repo.'/app/Models/'.$model.'.php';
    $source = is_file($modelPath) ? (string) file_get_contents($modelPath) : '';
    $ns = namespaceOf($source);

    preg_match_all('/->(?:belongsTo|hasMany|hasOne|belongsToMany|morphMany|morphTo)\(\s*([A-Za-z0-9_]+)::class/', $source, $rel);
    $imports = importsOf($source);
    $relations = [];
    foreach ($rel[1] as $short) {
        $fqcn = $imports[$short] ?? ($ns === '' ? $short : $ns.'\\'.$short);
        if ($fqcn === 'App\\Models\\User') {
            $flag = 'User';
        } elseif (preg_match('#^App\\\\Modules\\\\(\w+)\\\\Models\\\\#', $fqcn, $m) === 1 && $m[1] === $owner) {
            $flag = 'own-module';
        } else {
            $flag = 'FOREIGN';
        }
        $relations[] = $short.' ['.$flag.']';
    }

    $importFiles = [];
    $bareFiles = [];
    foreach ($appFiles as $path) {
        $relative = str_replace('\\', '/', substr($path, strlen($repo.'/app') + 1));
        if ($relative === 'Models/'.$model.'.php') {
            continue;
        }
        $fileSource = (string) file_get_contents($path);
        if (! usesShortName($fileSource, $model)) {
            continue;
        }
        $fileImports = importsOf($fileSource);
        $imported = isset($fileImports[$model]) && $fileImports[$model] === 'App\\Models\\'.$model;
        if (! $imported) {
            // also true when the model is imported under an alias - then the short alias differs and
            // $model would be absent from the import map
            foreach ($fileImports as $fqcn) {
                if ($fqcn === 'App\\Models\\'.$model) {
                    $imported = true;
                    break;
                }
            }
        }
        if ($imported) {
            $importFiles[] = $relative;
        } else {
            $bareFiles[] = $relative;
        }
    }

    $testRefs = 0;
    foreach ($testFiles as $path) {
        if (usesShortName((string) file_get_contents($path), $model)) {
            $testRefs++;
        }
    }

    $totals['import'] += count($importFiles);
    $totals['bare'] += count($bareFiles);

    echo "=== {$model} (owner {$owner})\n";
    echo '  relations: '.(count($relations) ? implode(', ', $relations) : 'NONE')."\n";
    echo '  app files using it: '.count($importFiles).' by import, '.count($bareFiles).' BARE'."\n";
    foreach ($bareFiles as $b) {
        echo "    BARE: {$b}\n";
    }
    echo "  test files using it: {$testRefs}\n\n";
}

echo 'TOTALS: '.$totals['import'].' import rewrites, '.$totals['bare'].' bare references to inspect'."\n";
