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
     */
    private const FORBIDDEN_LOGIN = '/api\/(?:admin|staff)\/login[\'"]?\s*,\s*\[?\s*[\'"]email[\'"]\s*=>\s*\$/';

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
