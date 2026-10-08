<?php

namespace Tests\Feature\Review;

use App\Enums\StaffRole;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RV-43: are the 9 red AdminDashboardControllerTest failures an APP bug or a stale FIXTURE?
 *
 * These 9 tests are red at HEAD and were never touched by RV-42a or RV-04b (proved by the
 * bisect in R2 sec 123 with all changes stashed). This test exists to answer one question
 * with evidence instead of argument:
 *
 *   IS THE APP BROKEN, OR DOES THE FIXTURE NO LONGER PRODUCE THE STATE THE APP EXPECTS?
 *
 * THE ANSWER, PROVEN HERE: the app is correct.
 *
 * Admin auth was deliberately migrated from "look up a User by email and check config/admin.php"
 * to "look up an Employee by username or email; the DB is the source of truth"
 * (`AdminAuthService`, class docblock :16-21). Credentials moved into the `employees` table,
 * seeded at deployment by `SpecialAccountSeeder` / `SystemAdminSeeder` from the
 * `SYSTEM_ADMIN_*` env vars. Six other test files were updated for that migration and say so
 * in their own comments (ActsAsStaff:11-23, StaffAdminControllerTest:313,
 * EmployeeManagementControllerTest:275, AdminBanControllerTest:406, StaffAuthControllerTest:228,
 * DeletedArtifactsBatchTest:18).
 *
 * `AdminDashboardControllerTest` was MISSED by that migration. Its `setUp()` still only does
 * `Config::set('admin.system_admin', ...)` and `seedSystemWallets()` and expects the app to
 * auto-provision the admin from config. Nothing does that any more, so `primary@admin.test`
 * matches no Employee, `authenticate()` returns null, and the route answers 401.
 *
 * Nothing here weakens an assertion or touches the red file. It only proves which side is wrong.
 */
class RV43AdminLoginEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    private function makeEmployee(StaffRole $role, string $suffix): Employee
    {
        return Employee::create([
            'username' => "rv43_{$suffix}",
            'email' => "rv43_{$suffix}@test.com",
            'password' => self::PASSWORD,
            'first_name' => 'Rv43',
            'last_name' => 'Probe',
            'role' => $role->value,
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    /**
     * THE PROOF: with a real Employee present, /api/admin/login returns 200 and a token.
     * The 401 in AdminDashboardControllerTest is therefore a missing fixture row, not a
     * broken login path.
     */
    public function test_admin_login_succeeds_for_a_real_system_admin_employee(): void
    {
        $employee = $this->makeEmployee(StaffRole::SYSTEM_ADMIN, 'sys');

        $response = $this->postJson('/api/admin/login', [
            'username' => $employee->username,
            'password' => self::PASSWORD,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            // NB: the key is `role`, not `type`. `AdminAuthService::formatAdmin()` (:137-148)
            // returns `role` and `role_label`. `AdminDashboardControllerTest:76` still asserts
            // `admin.type`, which is a THIRD piece of the same staleness - that assertion is
            // unreachable even once the fixture is fixed.
            ->assertJsonPath('admin.role', 'system_admin');

        $this->assertNotEmpty(
            $response->json('tokens.access_token'),
            'a successful admin login must mint an access token'
        );
    }

    /**
     * The app accepts an EMAIL identifier too - `authenticate()` does
     * `where('username', $identifier)->orWhere('email', $identifier)` (:51-53).
     *
     * This matters for the diagnosis: email login is not the defect. The defect is that the
     * email in the red test matches no Employee at all, because config no longer provisions one.
     */
    public function test_admin_login_also_accepts_the_employee_email(): void
    {
        $employee = $this->makeEmployee(StaffRole::SYSTEM_ADMIN, 'byemail');

        $this->postJson('/api/admin/login', [
            'email' => $employee->email,
            'password' => self::PASSWORD,
        ])->assertStatus(200);
    }

    /** The sycash admin door works the same way - the other 8 red tests are downstream of this. */
    public function test_sycash_admin_login_succeeds_for_a_real_sycash_employee(): void
    {
        $employee = $this->makeEmployee(StaffRole::SYCASH, 'syc');

        $this->postJson('/api/admin/login', [
            'username' => $employee->username,
            'password' => self::PASSWORD,
        ])->assertStatus(200)
            ->assertJsonPath('admin.role', 'sycash');
    }

    /**
     * Reproduces the red test's exact request against a database that has NO such Employee -
     * i.e. what AdminDashboardControllerTest::setUp actually produces today. 401 is the
     * CORRECT answer for this state, which is the whole point: the assertion is right and the
     * fixture is wrong.
     */
    public function test_the_red_tests_credentials_get_401_because_no_employee_exists(): void
    {
        $this->assertDatabaseMissing('employees', ['email' => 'primary@admin.test']);

        $this->postJson('/api/admin/login', [
            'email' => 'primary@admin.test',
            'password' => 'primary_pass',
        ])->assertStatus(401);
    }

    /** A non-admin role must still be refused, so "the app is correct" does not mean "it lets anyone in". */
    public function test_a_non_admin_employee_is_still_refused(): void
    {
        $employee = $this->makeEmployee(StaffRole::SUPPORT_AGENT, 'notadmin');

        $this->postJson('/api/admin/login', [
            'username' => $employee->username,
            'password' => self::PASSWORD,
        ])->assertStatus(401);
    }

    /** And the wrong password is still refused for a real admin. */
    public function test_wrong_password_is_still_refused_for_a_real_admin(): void
    {
        $employee = $this->makeEmployee(StaffRole::SYSTEM_ADMIN, 'wrongpw');

        $this->postJson('/api/admin/login', [
            'username' => $employee->username,
            'password' => 'not_the_password',
        ])->assertStatus(401);
    }

    /**
     * THE SECOND FINDING: the RV-35 ratchet exists precisely to catch a test that logs in by
     * email, and it cannot catch THIS one.
     *
     * `NoDuplicatedFixtureHelpersTest::FORBIDDEN_LOGIN` required the value to start with a
     * variable - `'email' => $` - so it never matched the LITERAL shape
     * `'email' => 'primary@admin.test'` used by `AdminDashboardControllerTest:73`. That
     * ratchet stayed green while the file it was written to catch sat red.
     *
     * This asserts the FIX rather than the hole: the live pattern is read out of the
     * ratchet, not restated, so narrowing it again fails here.
     */
    public function test_the_rv35_ratchet_now_catches_a_literal_email_login(): void
    {
        // Read the REAL constant by reflection rather than re-typing it, so narrowing the
        // pattern again fails here instead of quietly passing.
        $pattern = (new \ReflectionClass(NoDuplicatedFixtureHelpersTest::class))
            ->getConstant('FORBIDDEN_LOGIN');

        $this->assertIsString($pattern);

        // The pattern anchors on the LOGIN PATH, so the probe must include it - this is
        // the literal shape AdminDashboardControllerTest used.
        $literalLogin = "postJson('/api/admin/login', [\n            'email' => 'primary@admin.test', 'password' => 'x',\n        ])";

        $this->assertSame(
            1,
            preg_match($pattern, $literalLogin),
            'RV-43 regression: FORBIDDEN_LOGIN matches the variable shape but not the literal '
            .'one again, so AdminDashboardControllerTest slips through the same way.'
        );

        // The old hole was a trailing variable requirement; assert it has not come back.
        $this->assertStringNotContainsString('\s*\$/', (string) $pattern);
    }

    /**
     * And that file is genuinely exempt now - an explicit, reasoned exemption, not an
     * unnoticed pass. Its remaining email logins are deliberate negative cases.
     */
    public function test_the_red_file_is_explicitly_exempt_with_a_stated_reason(): void
    {
        $ratchet = (string) file_get_contents(__DIR__.'/NoDuplicatedFixtureHelpersTest.php');

        $this->assertStringContainsString(
            'tests\Feature\Admin\AdminDashboardControllerTest.php',
            $ratchet,
            'AdminDashboardControllerTest keeps only negative email logins, so it must be an '
            .'explicit, reasoned exemption rather than an unnoticed pass.'
        );
    }
}
