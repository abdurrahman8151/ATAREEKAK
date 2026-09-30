<?php

namespace Tests\Feature\Review;

use Tests\TestCase;

/**
 * RV-37 — test determinism ratchets.
 *
 * `putenv()` writes to the WHOLE PHP process, and PHPUnit runs every test in one
 * process. So a single `putenv('EMAIL_OTP_MODE=testing')` in a setUp changed the
 * behaviour of every test that ran afterwards, in whatever order the runner chose.
 *
 * That was measured, not assumed:
 *   - V13 found seven test files calling `putenv()`;
 *   - V14 ran the suite twice and got 53 failures in default order versus 55 under
 *     `--order-by=random`.
 *
 * The root fix was to move the affected settings behind `config()` (see config/otp.php),
 * which Laravel rebuilds per test. This ratchet stops the raw-environment pattern from
 * coming back, including under a name this audit did not think of.
 */
class TestDeterminismRatchetTest extends TestCase
{
    /**
     * Files allowed to call putenv(), each with the reason it cannot be avoided.
     *
     * The bar is: the thing under test must BE the resolution of an environment
     * variable. These three qualify; everything else is a config value in disguise
     * and must use Config::set.
     */
    private const PUTENV_ALLOWLIST = [
        'tests/Feature/Config/PusherCredentialFallbackTest.php' => 'Tests the fallback of a raw config FILE: freshConfig() does `require '
            .'config/broadcasting.php`, whose env() calls read the process environment. '
            .'Config::set would not affect a file that is re-required. Saves and restores '
            .'each variable.',

        'tests/Feature/Security/SessionCookieAndCorsTest.php' => 'Same shape: configWithEnv() re-requires a config FILE with one variable set or '
            .'cleared to prove the file reacts. Config::set cannot influence a require of '
            .'the raw file. Saves and restores the variable in a finally block.',

        'tests/Feature/T3Batch/SeedCredentialsBatchTest.php' => 'The subject under test IS env resolution — the trait resolves a value from an '
            .'environment variable and must generate one when absent. putenv is the only way '
            .'to express that expectation.',
    ];

    /** @test */
    public function no_test_file_writes_to_the_process_environment(): void
    {
        $violations = [];
        $self = strtolower(str_replace('/', '\\', __FILE__));

        foreach ($this->phpFilesInTests() as $file) {
            if (strtolower(str_replace('/', '\\', $file)) === $self) {
                continue;
            }

            $relative = str_replace(
                ['/', '\\'],
                '/',
                str_replace(base_path().DIRECTORY_SEPARATOR, '', $file)
            );
            // The allowlist is keyed by path (the reason is the value), so this must be
            // array_key_exists() — in_array() would search the reasons and never match.
            if (array_key_exists($relative, self::PUTENV_ALLOWLIST)) {
                continue;
            }

            $source = (string) file_get_contents($file);

            // Ignore the word inside comments and docblocks — several files explain
            // why they used to need putenv, and that prose is not a violation.
            $code = $this->stripComments($source);

            if (preg_match('/(?<![\w$])putenv\s*\(/', $code)) {
                $violations[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'RV-37 ratchet: these test files call putenv(), which mutates the whole PHP '
            .'process and leaks into every test that runs afterwards (this is the order '
            .'dependence V14 measured: 53 failures in default order, 55 under random). Use '
            ."Config::set() instead, which Laravel rebuilds per test:\n  "
            .implode("\n  ", $violations)
        );
    }

    /**
     * Every test must run with a real mailer, so a forgotten MAIL_MAILER cannot reach
     * a live SMTP server and hang the suite.
     *
     * @test
     */
    public function the_test_environment_never_uses_a_real_mailer(): void
    {
        $this->assertContains(
            config('mail.default'),
            ['array', 'in-memory', 'null', 'log'],
            'tests must not send real mail; set MAIL_MAILER=array in phpunit.xml'
        );
    }

    /**
     * Remove comments so a docblock mentioning putenv() is not counted as a call.
     */
    private function stripComments(string $source): string
    {
        $withoutBlock = preg_replace('#/\*.*?\*/#s', '', $source);
        $withoutLine = preg_replace('#(^|\s)//[^\n]*#', '$1', $withoutBlock);

        return (string) $withoutLine;
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesInTests(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('tests'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
