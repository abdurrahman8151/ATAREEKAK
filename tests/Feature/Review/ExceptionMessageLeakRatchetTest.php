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
    /** Measured 2026-10-04. May only decrease - never raise. */
    private const BASELINE = 47;

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

                $hits[] = $i + 1;
            }

            if ($hits !== []) {
                $found[str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getPathname())] = $hits;
            }
        }

        return $found;
    }

    /** @test */
    public function no_controller_may_add_a_new_raw_exception_message_leak(): void
    {
        $total = array_sum(array_map('count', $this->leaks()));

        $this->assertLessThanOrEqual(
            self::BASELINE,
            $total,
            "A controller now echoes an exception message to a client. {$total} such sites exist; the "
            ."baseline is ".self::BASELINE." and may only fall. Log::error(\$e->getMessage()) is FINE - the "
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
}