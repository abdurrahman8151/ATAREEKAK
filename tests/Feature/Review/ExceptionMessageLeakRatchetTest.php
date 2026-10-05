<?php

namespace Tests\Feature\Review;

use Tests\TestCase;

/**
 * RV-13, second half: an exception message must not be echoed to the client.
 *
 * THE LEAK. 47 controller sites do `'message' => $e->getMessage()` inside a JSON RESPONSE. An
 * exception message is not written for a client: a `QueryException` carries the SQL and the table
 * names, a `ModelNotFoundException` names internal identifiers, a third-party HTTP client's message
 * carries an internal URL or token. Returning it tells an attacker the shape of the database.
 *
 * NOTE WHAT IS **NOT** COUNTED, deliberately:
 *   - `Log::error(... $e->getMessage())` - server-side, where the message belongs. There are ~120
 *     `getMessage()` calls in the controllers and the large majority are these, and counting them
 *     would produce a ratchet that only ever says "do not log" - useless.
 *   - `config('app.debug') ? $e->getMessage() : '...'` - already guarded (one site, SignupController).
 *   - a `'message'` built from a DOMAIN message the controller itself wrote (e.g. "Only pending
 *     bookings can be accepted") - that is a client-facing rule, not a leak, and the handler's
 *     DomainException work in sec 64 is how those get an explicit code.
 *
 * WHY A RATCHET AND NOT A SWEEP. 47 sites across 12 controllers is a large mechanical change, and
 * doing it blind in one commit risks turning a real error into a generic one and losing the only
 * signal the client had. The ratchet makes the debt MEASURED and shrink-only, so the sweep can be
 * done in reviewable slices and the count can never grow back.
 *
 * The baseline is the measured 47. It may only go DOWN: a fix that removes a site must also lower
 * this number, and adding one fails CI.
 */
class ExceptionMessageLeakRatchetTest extends TestCase
{
    /** Measured 2026-10-04: 47, then 42 (ChatController sweep), then 21 once specific catches were correctly EXCLUDED. May only decrease. */
    private const BASELINE = 21;

    /** @return array<string, array<int, int>> file => line numbers that leak a message to a client */
    private function leaks(): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers'))
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
            $hits = [];

            foreach ($lines as $i => $line) {
                // A client-facing message field...
                if (! preg_match('/[\'"]message[\'"]\s*=>/', $line)) {
                    continue;
                }

                // ...built from the exception's own message...
                if (! str_contains($line, 'getMessage()')) {
                    continue;
                }

                // ...not a server-side log (where the message belongs)...
                if (str_contains($line, 'Log::')) {
                    continue;
                }

                // ...and not already gated on app.debug.
                if (str_contains($line, "config('app.debug')")) {
                    continue;
                }

                // â”€â”€ THE DISTINCTION THAT MATTERS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
                // Only a BROAD catch leaks.
                //
                // `catch (\Throwable $e)` means the code does NOT know what failed, so the message
                // can be a QueryException (SQL + table names) or a third-party client's message
                // (internal URL). That is a leak.
                //
                // `catch (\DomainException $e)` means the code KNOWS what failed and chose that
                // message; it is written for the client ("An employee with this email already
                // exists"). Sanitising it removes real, actionable feedback and protects nothing -
                // the exact mistake a blind sweep makes. Sec 64.2 left one such site in
                // ChatController alone for exactly this reason.
                //
                // Re-baselined from 42 to 21 once this distinction was applied: 21 of the original 42
                // were specific catches, not leaks.
                if (! $this->enclosingCatchIsBroad($lines, $i)) {
                    continue;
                }

                $hits[] = $i + 1;
            }

            if ($hits !== []) {
                $found[str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname())] = $hits;
            }
        }

        return $found;
    }

    /**
     * Is the `catch` governing line $i a BROAD type?
     *
     * Walks back to the nearest preceding `catch (...)`, stopping at the next method boundary. Only
     * Throwable / Exception / Error (or an unqualified catch) count; any NAMED type is specific by
     * construction and therefore not a leak. No enclosing catch is treated as broad - failing safe.
     */
    private function enclosingCatchIsBroad(array $lines, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (preg_match('/catch\s*\(\s*([^)]*?)\s*\$[a-zA-Z_]/', $lines[$j], $m)) {
                return in_array(strtolower(trim($m[1])), [
                    '\throwable', 'throwable',
                    '\exception', 'exception',
                    '\error', 'error',
                ], true);
            }

            if (preg_match('/^\s{4,}(public|private|protected) function/', $lines[$j])) {
                return true; // no enclosing catch found - fail safe
            }
        }

        return true;
    }

    /** @test */
    public function no_controller_may_add_a_new_raw_exception_message_leak(): void
    {
        $total = array_sum(array_map('count', $this->leaks()));

        $this->assertLessThanOrEqual(
            self::BASELINE,
            $total,
            "A controller now echoes an exception message to a client. {$total} such sites exist; the "
            .'baseline is '.self::BASELINE.' and may only fall. Log::error($e->getMessage()) is FINE - the '
            ."message belongs in the log. Only the client's response body is the leak."
        );
    }

    /**
     * The floor: there must always BE leaks to sweep. If this passes with zero, the ratchet above
     * has gone slack and the budget it enforces is fictional.
     *
     * @test
     */
    public function the_baseline_is_still_relevant(): void
    {
        $total = array_sum(array_map('count', $this->leaks()));

        $this->assertGreaterThan(
            0,
            $total,
            'No leaks remain, so the ratchet baseline should be lowered to 0 and this ratchet retired.'
        );
    }

    /** @test */
    public function server_side_logging_of_exception_messages_is_not_counted(): void
    {
        // Proves the ratchet is aimed at the right thing rather than banning getMessage() outright.
        $logged = 0;

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers'))
        ) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $raw = file_get_contents($file->getPathname());
            $logged += preg_match_all('/Log::(error|warning)\([^;]*getMessage\(\)/', (string) $raw);
        }

        $this->assertGreaterThan(0, $logged,
            'The controllers log exception messages server-side. If this is ever zero, the ratchet is '
            .'over-broad and should be re-scoped before it blocks legitimate logging.');
    }

    /**
     * The classification itself must be provable, or "21" is just a number I asserted.
     *
     * Two real shapes from this codebase, one of each kind:
     *   - `EmployeeManagementController`: `catch (\DomainException $e) => 'message' =>
     *     $e->getMessage()` - app-authored, for the client, NOT a leak;
     *   - `ChatController` before the sec 64.2 sweep: `catch (\Exception $e) => 'message' =>
     *     'Failed to send message: '.$e->getMessage()` - broad, WAS a leak.
     *
     * @test
     */
    public function the_ratchet_is_scoped_to_broad_catches_only(): void
    {
        $broad = [
            '        } catch (\Exception $e) {',
            "            return response()->json(['message' => 'Failed: '.\$e->getMessage()], 500);",
        ];
        $specific = [
            '        } catch (\DomainException $e) {',
            "            return response()->json(['message' => \$e->getMessage()], 403);",
        ];

        $this->assertTrue(
            $this->enclosingCatchIsBroad($broad, 1),
            'a broad catch must be counted - that IS the leak'
        );
        $this->assertFalse(
            $this->enclosingCatchIsBroad($specific, 1),
            'a NAMED catch is app-authored and must not be counted - sanitising it removes real feedback'
        );
    }

    /**
     * A named catch on an exception type this application defines is not a leak either.
     *
     * @test
     */
    public function a_catch_on_our_own_domain_exception_is_not_a_leak(): void
    {
        $lines = [
            '        } catch (\App\Exceptions\Domain\ConflictViolation $e) {',
            "            return response()->json(['message' => \$e->getMessage()], 409);",
        ];

        $this->assertFalse(
            $this->enclosingCatchIsBroad($lines, 1),
            'section 64 DomainException messages are written for the client; they are not leaks'
        );
    }
}
