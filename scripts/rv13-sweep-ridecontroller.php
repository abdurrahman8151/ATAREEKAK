<?php

/**
 * RV-13 sweep for RideController: move exception messages out of client responses and into the log.
 *
 * A one-off TRANSFORMATION, not application code. Kept in the repository so the bulk edit is
 * auditable and repeatable rather than 15 hand-made changes nobody can review at a glance.
 *
 * RULES (deliberately narrow - this must not "improve" anything else):
 *   1. Only a BROAD catch (`\Throwable` / `\Exception` / `\Error`) is touched. A named catch means an
 *      app-authored message that must reach the client (R2 sec 64.3).
 *   2. 'App prefix: '.$e->getMessage()  ->  'App prefix.'  - the application's OWN wording is kept,
 *      only the exception text is dropped, so the user still learns what operation failed.
 *   3. A bare 'message' => $e->getMessage() -> a generic sentence. There is no app wording to keep.
 *   4. The STATUS CODE is never changed. This is a message sweep, not a status change; R2 sec 64.4
 *      records the separate question of broad catches answering 422.
 *   5. Every site keeps its diagnostic detail: if the catch does not already log, one is added. The
 *      detail MOVES from the response to the log; it is not destroyed.
 *
 * Usage: php scripts/rv13-sweep-ridecontroller.php <path-to-RideController.php>
 */

$path = $argv[1] ?? null;

if (! $path || ! is_file($path)) {
    fwrite(STDERR, "usage: php rv13-sweep-ridecontroller.php <RideController.php>\n");
    exit(1);
}

$GM = '$e->getMessage()';
$BROAD = ['\throwable', '\exception', '\error'];

$lines = explode("\n", (string) file_get_contents($path));

$changed = 0;
$logsAdded = 0;
$skipped = 0;

/**
 * The nearest preceding `catch (...)`, or null if the line is not inside one.
 *
 * @return array{0: string, 1: int}|null [type, line index]
 */
function enclosingCatch(array $lines, int $i): ?array
{
    for ($j = $i - 1; $j >= 0; $j--) {
        if (preg_match('/catch\s*\(\s*([^)]*?)\s*\$[a-zA-Z_]/', $lines[$j], $m)) {
            return [trim($m[1]), $j];
        }
        if (preg_match('/^\s{4,}(public|private|protected) function/', $lines[$j])) {
            return null;
        }
    }

    return null;
}

/** Does this catch block already write the exception to the log? */
function alreadyLogs(array $lines, int $catchIndex): bool
{
    for ($j = $catchIndex + 1; $j < count($lines); $j++) {
        if (preg_match('/^\s{8}\}/', $lines[$j])) {
            return false;
        }
        if (str_contains($lines[$j], 'Log::')) {
            return true;
        }
    }

    return false;
}

for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];

    if (! str_contains($line, $GM)) {
        continue;
    }
    if (! str_contains($line, "'message'")) {
        continue;
    }
    if (str_contains($line, 'Log::') || str_contains($line, "config('app.debug')")) {
        continue;
    }

    $catch = enclosingCatch($lines, $i);
    if ($catch === null) {
        continue;
    }

    if (! in_array(strtolower($catch[0]), $BROAD, true)) {
        $skipped++;

        continue;
    }

    // Rule 2 first: is there app wording to preserve?
    $prefixPattern = "/'message' => '([^']+): '\." . preg_quote($GM, '/') . '/';
    if (preg_match($prefixPattern, $line, $m) === 1) {
        $needle = "'" . $m[1] . ": '." . $GM;
        $lines[$i] = str_replace($needle, "'" . $m[1] . ".'", $line);
        $changed++;

        // Rule 3: a bare 'message' => $e->getMessage() has no wording to keep.
    } elseif (str_contains($line, "'message' => " . $GM)) {
        $lines[$i] = str_replace(
            "'message' => " . $GM,
            "'message' => 'The request could not be completed. Please try again.'",
            $line
        );
        $changed++;

    } else {
        continue;
    }

    // Rule 5. Insert immediately AFTER the catch line - $i is inside `return response()->json([...])`,
    // so inserting there would put a `]);` in the middle of an array literal.
    if (! alreadyLogs($lines, $catch[1])) {
        array_splice($lines, $catch[1] + 1, 0, [
            '            // RV-13: the exception text goes to the LOG, not to the client - a',
            '            // QueryException carries the SQL and the table names.',
            '            Log::error(\'RideController: request failed\', [',
            '                \'error\' => $e->getMessage(),',
            '            ]);',
            '',
        ]);
        $logsAdded++;
    }
}

file_put_contents($path, implode("\n", $lines));

printf("messages sanitised: %d | logs added: %d | specific catches left alone: %d\n", $changed, $logsAdded, $skipped);
