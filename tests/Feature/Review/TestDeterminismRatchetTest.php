<?php

namespace Tests\Feature\Review;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * The hermeticity half of RV-37: the suite must be unable to reach the network.
     *
     * Two independent protections, both checked here so neither can be quietly
     * deleted:
     *
     *  1. `Http::preventStrayRequests()` in `TestCase::setUp` - an outgoing request
     *     that no `Http::fake()` stub matched throws instead of going out.
     *  2. The Guzzle-direct call sites (WhatsApp/TextMeBot OTP, GoogleController)
     *     are outside that guard but credential-gated, so `CreatesApplication`
     *     nulls those keys for the whole test process. This is the assertion that
     *     catches a developer's `.env` re-opening the hole - the owner's
     *     `phpunit.xml` and `.env` are never-committed local files and may pin
     *     nothing at all.
     *
     * @test
     */
    public function the_test_environment_cannot_reach_the_network(): void
    {
        // Strip comments first: TestCase's own docblock NAMES the method (to explain
        // why it is there), and a naive substring check would match the prose and pass
        // even with the call deleted - which is exactly what the first needle did.
        $testCaseCode = $this->stripComments((string) file_get_contents(base_path('tests/TestCase.php')));

        $this->assertMatchesRegularExpression(
            '/Http::preventStrayRequests\s*\(\s*\)\s*;/',
            $testCaseCode,
            'tests/TestCase.php must keep Http::preventStrayRequests() - an un-faked request '
            .'would otherwise leave the process silently'
        );

        foreach ([
            'services.textmebot.api_key',
            'services.callmebot.api_key',
            'services.chatdaddy.api_key',
            'services.google.client_id',
            'services.google.client_secret',
        ] as $key) {
            $this->assertEmpty(
                config($key),
                "{$key} must be empty under the testing environment: its call site builds a "
                .'Guzzle client directly, so Http::preventStrayRequests() cannot see it and a '
                .'credential present in the developer\'s .env would let the suite egress. '
                .'A test needing the configured branch must Config::set it for that test only.'
            );
        }

        // The enable-flag is the same hole one level up: with it true, the controller
        // stops returning "provider disabled" and routes the send to the provider.
        $this->assertFalse(
            (bool) config('services.textmebot.enabled', false),
            'services.textmebot.enabled must be false under testing - otherwise a developer .env '
            .'with TEXTMEBOT_ENABLED=true steers the suite down the real provider path '
            .'(measured: TextMeOtpControllerTest\'s 400-disabled branch turned into 200).'
        );
    }

    /**
     * Traits that make a test class's database writes rollback-able.
     *
     * @var list<class-string>
     */
    private const DB_TRAITS = [
        RefreshDatabase::class,
        DatabaseTransactions::class,
        DatabaseMigrations::class,
        DatabaseTruncation::class,
    ];

    /**
     * Code shapes that persist or read database rows. A class containing any of them
     * without a transactional trait runs outside the suite's rollback, so its rows are
     * COMMITTED for the rest of the PHP process.
     *
     * `::create(` is deliberately NOT here: `Request::create()` (Symfony) and
     * `Path::create()` build an object, not a row. `Model::create(` is matched by the
     * explicit list below instead, and a local needle test (see RV-37 section 39)
     * proves the list still fires on a real `Model::create(`.
     *
     * @var list<string>
     */
    private const DB_WRITE_MARKERS = [
        'factory(',
        '->save(',
        '->push(',
        'assertDatabaseHas',
        'assertDatabaseMissing',
        'assertDatabaseCount',
        'DB::table(',
        'DB::insert',
        'DB::update',
        'DB::delete',
        'DB::statement',
        'DB::transaction',
        'Schema::',
        'RideBuilder::build',
    ];

    /** Eloquent models whose `::create(` writes a row. */
    private const DB_CREATED_MODELS = [
        'User', 'Wallet', 'Ride', 'Booking', 'Complaint', 'Notification', 'UserNotification',
        'Otp', 'WalletRequest', 'WalletTransaction', 'Employee', 'Photo', 'DriverDocument',
        'Conversation', 'Message', 'Rating', 'Commission', 'RefreshToken', 'PasswordResetToken',
        'Driver', 'Passenger', 'System', 'NoShowReport', 'PaymentStrategy',
    ];

    /**
     * RV-37 §23.6's root cause, found by measurement.
     *
     * §23.6 recorded that some class left rows COMMITTED and that later count-style
     * assertions (AdminDriverServiceTest's aggregates) counted them, then ruled that
     * "every TestCase subclass has a database trait - 0 classes without one". That
     * ruling was wrong: 58 classes extend TestCase without a database trait, and five of
     * them write. Measured, per file, against the scratch database (committed rows
     * survive the process, so a leak is directly observable):
     *
     *   Feature/RateLimiting/RateLimiterIdentityKeyTest   2 users
     *   Feature/Review/CreateRideRouteTest                2 users
     *   Feature/Review/RV29UserMassAssignmentRatchetTest 1 user
     *   Unit/Providers/EventServiceProviderTest           1 user
     *   Unit/Providers/RouteServiceProviderTest           1 user
     *
     * This ratchet keeps that set empty. It is the same shape as the putenv ratchet:
     * a structural property of every test file, checked in one place.
     *
     * @test
     */
    public function every_test_class_that_writes_to_the_database_is_transactional(): void
    {
        $violations = [];
        $self = strtolower(str_replace('/', '\\', __FILE__));

        foreach ($this->test_classes_in_tests() as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            if ($file === false || strtolower(str_replace('/', '\\', $file)) === $self) {
                continue;
            }

            $relative = str_replace(
                ['/', '\\'],
                '/',
                str_replace(base_path().DIRECTORY_SEPARATOR, '', $file)
            );

            if ($this->declares_db_trait($class)) {
                continue;
            }

            $code = $this->strip_comments_and_strings((string) file_get_contents($file));
            $marker = $this->first_db_write_marker($code);

            if ($marker !== null) {
                $violations[] = sprintf('%s writes to the database (%s) without %s',
                    $relative, $marker, $this->trait_short_names());
            }
        }

        $this->assertSame(
            [],
            $violations,
            'RV-37 ratchet: these test classes persist rows with no transactional trait, so '
            .'MySQL commits them and every test that runs later in the same process sees them '
            .'(this is the committed-leak order dependence recorded in R2 section 23.6; it is '
            ."why count-style assertions change with test order). Add `use RefreshDatabase;`:\n  "
            .implode("\n  ", $violations)
        );
    }

    /** A trait on the class or any ancestor (an intermediate abstract test base counts). */
    private function declares_db_trait(string $class): bool
    {
        foreach (class_uses_recursive($class) as $trait) {
            if (in_array($trait, self::DB_TRAITS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first marker in $code that proves the class touches rows, or null.
     *
     * `Model::create(` is matched by model name so `Request::create()` (which builds an
     * HTTP request object, not a row) is not a false positive - three real test files
     * would otherwise be flagged for it.
     */
    private function first_db_write_marker(string $code): ?string
    {
        foreach (self::DB_WRITE_MARKERS as $marker) {
            if (str_contains($code, $marker)) {
                return $marker;
            }
        }

        foreach (self::DB_CREATED_MODELS as $model) {
            if (preg_match('/(?<![\w\\\\])'.$model.'::create\s*\(/', $code)) {
                return $model.'::create(';
            }
        }

        return null;
    }

    /** @return list<string> */
    private function trait_short_names(): string
    {
        return implode('|', array_map(
            static fn (string $t): string => class_basename($t),
            self::DB_TRAITS
        ));
    }

    /**
     * Every concrete test class under tests/, discovered from the source files so a
     * class that no longer matches its filename is still seen.
     *
     * @return list<class-string>
     */
    private function test_classes_in_tests(): array
    {
        $classes = [];

        foreach ($this->phpFilesInTests() as $file) {
            if (str_contains(str_replace('\\', '/', $file), '/tests/Support/')) {
                continue;
            }

            $source = (string) file_get_contents($file);
            $namespace = preg_match('/^namespace\s+([^;]+);/m', $source, $ns) ? trim($ns[1]).'\\' : '';
            if (! preg_match('/class\s+(\w+)\s+extends\s+(\w+)/', $source, $m)) {
                continue;
            }

            $fqcn = $namespace.$m[1];
            // The abstract base of the suite itself, and helper classes, are skipped:
            // the base has no trait by design and helpers are not tests.
            if (! class_exists($fqcn)) {
                continue;
            }

            $reflection = new \ReflectionClass($fqcn);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(TestCase::class)) {
                continue;
            }

            $classes[] = $fqcn;
        }

        sort($classes);

        return $classes;
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
     * Comments AND string literals: this file names the markers it searches for, so
     * without stripping strings the ratchet would flag its own allow-list prose.
     */
    private function strip_comments_and_strings(string $source): string
    {
        $withoutComments = $this->stripComments($source);

        // Single- and double-quoted strings, and heredoc/nowdoc bodies.
        $withoutStrings = preg_replace('/\'(?:\\\\.|[^\'\\\\])*\'/', "''", $withoutComments);
        $withoutStrings = preg_replace('/"(?:\\\\.|[^"\\\\])*"/', '""', $withoutStrings);

        return (string) preg_replace('/<<<[A-Z]+.*?^[A-Z]+;/ms', '', $withoutStrings);
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
