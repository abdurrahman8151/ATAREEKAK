<?php

namespace Tests\Feature\Review;

use Tests\Support\Concerns\ActsAsStaff;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\GeoPoint;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-34 ratchet — the duplicated test-fixture helpers must not come back.
 *
 * RV-34's acceptance criterion is partly a ratchet: after this task no test file
 * may declare its own `insertRide()`, `seedAdminWallets()` or `primaryToken()`,
 * because those copies are the two mechanical causes of the red suite (Cause A read
 * removed config keys; Cause B logged in by a removed email). Each new copy
 * reintroduces the same rot, so this test fails the moment one reappears.
 *
 * It is a ratchet, not a permanent ban: legitimate one-off local helpers are fine as
 * long as they are not these three, duplicated-shape declarations. If a future task
 * genuinely needs a file-local helper of one of these names, this test is the place
 * to consciously update (and say why), not a file to quietly edit.
 */
class NoDuplicatedFixtureHelpersTest extends TestCase
{
    /** The duplicated helper declarations RV-34 consolidates away. */
    private const FORBIDDEN = [
        'insertRide' => '/function\s+insertRide\s*\(/',
        'seedAdminWallets' => '/function\s+seedAdminWallets\s*\(/',
        'primaryToken' => '/function\s+primaryToken\s*\(/',
    ];

    /**
     * NOTE (RV-35): `adminToken` / `staffToken` were deliberately NOT added to the
     * name list above. They are a legitimate helper name — the shared trait itself
     * defines them, and three currently-green files have their own working versions
     * — so banning the name would forbid correct code. The substantive rule below
     * bans the *defect* instead, and is what actually catches a bad copy under any
     * name, which is how StaffAdminControllerTest slipped through RV-34 in the first
     * place.
     */

    /**
     * The real invariant behind the name ban: a test file must not hand-roll its own
     * `INSERT INTO rides`. Renaming the helper would otherwise satisfy the name check
     * while leaving the duplicated raw SQL — the thing that actually drifted between
     * files — in place. Every ride insert must go through Tests\Support\RideBuilder.
     */
    private const FORBIDDEN_SQL = '/INSERT\s+INTO\s+`?rides`?/i';

    /**
     * The real invariant behind the token-helper name ban, and the reason a name list
     * alone was not enough.
     *
     * Logging in by EMAIL is the defect itself: admin auth authenticates an Employee
     * by USERNAME, so any test posting an email to an auth door gets a null token and
     * errors its whole file. Banning the pattern catches a future copy under ANY name,
     * which is exactly how StaffAdminControllerTest got missed in the first place.
     *
     * RV-43: the trailing `\$` was a HOLE. It required the value to be a variable, so
     * the pattern missed the literal shape - `'email' => 'primary@admin.test'` - which
     * is precisely how `AdminDashboardControllerTest` slipped through. That file then sat
     * red (9 tests) while this ratchet stayed green. The `\$` is gone: an `'email' =>`
     * key followed by ANY value is now a violation.
     */
    private const FORBIDDEN_LOGIN = '/api\/(?:admin|staff)\/login[\'"]?\s*,\s*\[?\s*[\'"]email[\'"]\s*=>/';

    /**
     * Files allowed to post an email to an auth door, each with the reason it must.
     *
     * One entry only. It does not log in with a config email that matches no Employee
     * (the defect this ratchet exists for) - it asserts that an auth door ACCEPTS an
     * email identifier for an Employee that exists, because `AdminAuthService:51-53`
     * does `where('username', $identifier)->orWhere('email', $identifier)`. That is a
     * supported capability, not a stale fixture.
     *
     * `test_the_email_login_exemptions_are_still_needed` fails if an entry stops matching,
     * so this list cannot rot into a silent blanket exemption.
     */
    private const FORBIDDEN_LOGIN_EXEMPT = [
        'tests\Feature\Review\RV43AdminLoginEmployeeTest.php' => 'RV-43: asserts that an auth door accepts an email identifier for an Employee '
            .'that exists (AdminAuthService:51-53). Not a config-email fixture.',
        'tests\Feature\Admin\AdminDashboardControllerTest.php' => 'RV-43: the only email logins left here are the deliberate NEGATIVE ones '
            .'(unknown email, malformed email, missing fields) that assert 401/422. The '
            .'positive logins were converted to real Employees.',
    ];

    /** @test */
    public function test_no_test_file_declares_its_own_fixture_helper(): void
    {
        $violations = [];

        foreach ($this->phpFilesInTests() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::FORBIDDEN as $name => $pattern) {
                if (preg_match($pattern, $source)) {
                    $violations[] = $name.'() in '.$this->relative($file);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'RV-34 ratchet: these files still declare a duplicated fixture helper. '
            .'Use tests/Support/RideBuilder (rides), SeedsSystemWallets (system wallets) '
            ."and ActsAsStaff (staff/admin/user tokens) instead:\n  "
            .implode("\n  ", $violations)
        );
    }

    /** @test */
    public function test_no_test_file_hand_rolls_a_ride_insert(): void
    {
        $violations = [];

        $self = strtolower(str_replace('/', '\\', __FILE__));

        foreach ($this->phpFilesInTests() as $file) {
            // Skip this file: it necessarily contains the pattern literal itself.
            if (strtolower(str_replace('/', '\\', $file)) === $self) {
                continue;
            }
            $source = (string) file_get_contents($file);
            if (preg_match(self::FORBIDDEN_SQL, $source)) {
                $violations[] = $this->relative($file);
            }
        }

        $this->assertSame(
            [],
            $violations,
            'RV-34 ratchet: these files still hand-roll an INSERT INTO rides instead of '
            .'using Tests\\Support\\RideBuilder (which pins the SRID and the axis order '
            ."explicitly, the two things that drifted between the old copies):\n  "
            .implode("\n  ", $violations)
        );
    }

    /** @test */
    public function test_no_test_file_logs_in_to_a_staff_or_admin_door_with_an_email(): void
    {
        $violations = [];
        $self = strtolower(str_replace('/', '\\', __FILE__));

        foreach ($this->phpFilesInTests() as $file) {
            if (strtolower(str_replace('/', '\\', $file)) === $self) {
                continue;
            }
            // RV-43: skip only the explicitly justified exemptions. `$file` is absolute
            // here, so compare on the relative form the exemption keys are written in.
            if (array_key_exists($this->relative($file), self::FORBIDDEN_LOGIN_EXEMPT)) {
                continue;
            }
            $source = (string) file_get_contents($file);
            if (preg_match(self::FORBIDDEN_LOGIN, $source)) {
                $violations[] = $this->relative($file);
            }
        }

        $this->assertSame(
            [],
            $violations,
            "RV-35 ratchet: these files post an 'email' to /api/admin/login or /api/staff/login. "
            ."Admin/staff auth authenticates an Employee by USERNAME (or 'identifier'), so an "
            .'email login returns a null token and errors the whole file. Use '
            ."Tests\\Support\\Concerns\\ActsAsStaff (adminToken / staffToken):\n  "
            .implode("\n  ", $violations)
        );
    }

    /**
     * RV-43: the exemption list must not rot. If an exempt file stops containing the
     * pattern (someone removed its email login, or renamed it away), the exemption is
     * dead weight and this fails rather than letting the list grow unnoticed.
     */
    /** @test */
    public function test_the_email_login_exemptions_are_still_needed(): void
    {
        $this->assertNotEmpty(self::FORBIDDEN_LOGIN_EXEMPT, 'the exemption list must not be emptied silently');

        foreach (array_keys(self::FORBIDDEN_LOGIN_EXEMPT) as $file) {
            $path = base_path(str_replace('\\', '/', $file));
            $this->assertFileExists($path, "exempted file no longer exists: $file");

            $this->assertSame(
                1,
                preg_match(self::FORBIDDEN_LOGIN, (string) file_get_contents($path)),
                "exemption for $file is STALE - the file no longer posts an email to an auth "
                .'door. Remove the entry from FORBIDDEN_LOGIN_EXEMPT.'
            );
        }
    }

    /** @test */
    public function test_the_shared_support_layer_exists_and_is_the_single_source(): void
    {
        // The ratchet above is only meaningful if the replacements are real, so
        // assert the support layer is present and autoloadable.
        $this->assertTrue(class_exists(RideBuilder::class), 'RideBuilder must exist');
        $this->assertTrue(class_exists(GeoPoint::class), 'GeoPoint must exist');
        $this->assertTrue(trait_exists(SeedsSystemWallets::class), 'SeedsSystemWallets must exist');
        $this->assertTrue(trait_exists(ActsAsStaff::class), 'ActsAsStaff must exist');
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

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
