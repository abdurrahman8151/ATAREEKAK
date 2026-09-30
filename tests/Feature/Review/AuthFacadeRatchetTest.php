<?php

namespace Tests\Feature\Review;

use Tests\TestCase;

/**
 * RV-36 — the auth-facade ratchet.
 *
 * R2 §RV-36 asks for a grep ratchet over `auth()->` / `Auth::id()` / `Auth::user()` in
 * app/Http and app/Services, driven to 0.
 *
 * The premise needed correcting before building it. RV-36 as written claimed `auth()->id()`
 * is `null` because "this app's JWT middleware never populates the default guard" — V12
 * REFUTED that: `JwtAuthMiddleware` calls BOTH `setUserResolver()` AND `Auth::setUser()`, so
 * `auth()->id()` resolves and `bulkAction` was working. The real defects in that method were
 * different (an ownership-scoping gap that made it an existence oracle and a silent no-op),
 * fixed in NotificationController directly.
 *
 * So this ratchet is NOT asserting a live bug — it is enforcing the house rule:
 * controllers/services take the user from the injected `$request->user()`, which is the
 * request-scoped identity the JWT middleware guarantees, instead of the stateless-guard
 * `auth()` helper. The two legacy `auth()->id()` sites were the last holdouts and are now
 * `$request->user()->id`. The middleware's own `Auth::setUser()` is a WRITE and is not
 * matched by these patterns; it stays.
 */
class AuthFacadeRatchetTest extends TestCase
{
    /** Directories under the rule (R2 §RV-36 scope). */
    private const SCOPED_DIRS = ['app/Http', 'app/Services'];

    /**
     * Banned forms: reading the authenticated identity through the stateless `auth()`
     * helper / Auth facade instead of the request. `Auth::setUser` (a write) and
     * `Auth::guard(...)->...` (explicit guard use) are deliberately NOT banned.
     */
    private const PATTERNS = [
        'auth()->',
        'Auth::id()',
        'Auth::user()',
    ];

    /**
     * Files allowed to contain a banned form, each with a reason. Empty by design: the
     * two that existed were converted. A new entry must justify why $request->user() is
     * genuinely unavailable there.
     */
    private const ALLOWLIST = [
    ];

    /** @test */
    public function controllers_and_services_take_the_user_from_the_request_not_the_auth_helper(): void
    {
        $violations = [];

        foreach (self::SCOPED_DIRS as $dir) {
            $base = base_path($dir);
            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace(
                    ['/', '\\'],
                    '/',
                    str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())
                );

                if (array_key_exists($relative, self::ALLOWLIST)) {
                    continue;
                }

                foreach ($this->offendingLines((string) file_get_contents($file->getPathname())) as $line) {
                    $violations[] = $relative.': '.$line;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'RV-36 ratchet: these lines read the identity via auth()/Auth facade instead '
            .'of the injected $request->user(). Use $request->user()->id; add an '
            .'ALLOWLIST entry (with a reason) only where a request is genuinely '
            ."unavailable:\n  ".implode("\n  ", $violations)
        );
    }

    /**
     * Return the banned-form lines, skipping pure comment lines.
     *
     * Deliberately line-based rather than regex comment-stripping: a `#`/`//` strip
     * mangles `//` inside URLs and could hide or fake a match. A line whose trimmed
     * form merely *starts* with a comment marker is skipped; a trailing comment on a
     * code line cannot hide a real call, because the call lives in the code prefix that
     * is kept.
     *
     * @return array<int, string>
     */
    private function offendingLines(string $source): array
    {
        $found = [];

        foreach (explode("\n", $source) as $raw) {
            $t = trim($raw);

            // Skip lines that are entirely a comment or a docblock continuation.
            if ($t === ''
                || str_starts_with($t, '//')
                || str_starts_with($t, '#')
                || str_starts_with($t, '/*')
                || str_starts_with($t, '*')) {
                continue;
            }

            foreach (self::PATTERNS as $needle) {
                if (str_contains($t, $needle)) {
                    $found[] = $t;

                    break;
                }
            }
        }

        return $found;
    }
}
