<?php

namespace Tests\Feature\Review;

use App\Models\Employee;
use App\Models\User;
use App\Services\JwtService;
use App\Services\Staff\StaffJwtService;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RV-04 - the staff/user token audiences stay separated, proven rather than asserted in a comment.
 *
 * `JwtAuthMiddleware:53` rejects any token carrying a `sub_type` claim, and its comment states why:
 * a staff token's `sub` is an EMPLOYEE id, so without that check a staff token presented at a user
 * endpoint would look up the user with that id and accept it if that user's `ver` happened to match.
 * `StaffJwtService:193` does emit `sub_type => 'employee'`, so the mechanism is real.
 *
 * **But no test referenced `sub_type` anywhere in the suite.** The separation was carried entirely by
 * a comment in the middleware explaining a guard it also did not test - and the same comment records
 * the guard's history ("the reverse ... was never possible", "Without this check"). A security control
 * whose only documentation is its own source comment is one refactor away from being deleted.
 *
 * So this file pins it in both directions with REAL tokens from the real services, not hand-built
 * payloads.
 */
class RV04TokenAudienceTest extends TestCase
{
    use RefreshDatabase;

    private StaffJwtService $staffJwt;

    private JwtService $userJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staffJwt = app(StaffJwtService::class);
        $this->userJwt = app(JwtService::class);
    }

    /**
     * THE criterion: a real staff token at `/api/user` must be refused, not silently accepted as the
     * user whose id happens to equal the employee's.
     *
     * @test
     */
    public function a_real_staff_token_is_refused_at_a_user_endpoint(): void
    {
        $employee = $this->makeEmployee();

        // The dangerous shape, made concrete: a USER exists at the same id as the employee. Without
        // the `sub_type` check the staff token would resolve to THIS user and be accepted.
        $user = User::factory()->create();
        $employee->forceFill(['id' => $user->id])->save();
        $employee = $employee->fresh();

        $token = $this->staffJwt->generateTokenPair($employee)['access_token'];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user');

        $this->assertSame(
            401,
            $response->status(),
            'a staff token must never authenticate a user endpoint, even when a user shares its id'
        );
    }

    /**
     * The reverse direction: a user token must not authenticate a staff endpoint. `StaffJwtService`
     * carries its own check for exactly this (`:63`).
     *
     * @test
     */
    public function a_real_user_token_is_refused_at_a_staff_endpoint(): void
    {
        $user = User::factory()->create();
        $token = $this->userJwt->generateTokenPair($user)['access_token'];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/dashboard');

        $this->assertContains(
            $response->status(),
            [401, 403],
            'a user token must not authenticate a staff endpoint'
        );
    }

    /**
     * The guard is claim-based, so pin that the claim is actually emitted - otherwise the middleware
     * check could be silently dead code, which is precisely the failure it was written to prevent.
     *
     * @test
     */
    public function a_staff_token_carries_the_sub_type_claim_the_user_middleware_rejects(): void
    {
        $employee = $this->makeEmployee();

        $payload = $this->decode($this->staffJwt->generateTokenPair($employee)['access_token']);

        $this->assertSame(
            'employee',
            $payload['sub_type'] ?? null,
            'StaffJwtService must emit sub_type, or JwtAuthMiddleware\'s guard never fires'
        );
    }

    /**
     * And a user token must NOT carry it - otherwise the guard would refuse legitimate users, and the
     * "cannot affect legitimate user tokens" claim in the middleware comment would be false.
     *
     * @test
     */
    public function a_user_token_carries_no_sub_type_claim(): void
    {
        $user = User::factory()->create();

        $payload = $this->decode($this->userJwt->generateTokenPair($user)['access_token']);

        $this->assertArrayNotHasKey(
            'sub_type',
            $payload,
            'user tokens must stay free of sub_type or the audience guard would refuse every user'
        );
    }

    /**
     * CONTRACT CHANGE (`R2 sec 119`, owner decision 2026-10-11) - this test used to be named
     * `token_version_parity_across_schema_factory_and_staff_path`, asserted that a factory user and a
     * fresh employee start on the SAME `token_version`, and then `markTestIncomplete`d to record the
     * divergence it could not close.
     *
     * **Both halves of that are now wrong, deliberately.** The owner ruled that the FACTORY is wrong and
     * the migrations are right: `users.token_version` defaults 1, `employees.token_version` defaults 0,
     * and the two tables are not supposed to agree. `JwtService:87` fails closed on a missing `ver` and
     * `:91` compares against the claim minted at `:270`, so each table is self-consistent on its own
     * default - parity was never the invariant, and asserting it would have pinned a false one.
     *
     * So the parity assertion is REPLACED by two per-schema assertions, which are STRICTER rather than
     * weaker: previously nothing held the factory to the schema at all, and now it does, on both tables.
     *
     * @test
     */
    public function each_table_matches_its_own_schema_default(): void
    {
        $userSchemaDefault = $this->schemaDefault('users', 'token_version');
        $employeeSchemaDefault = $this->schemaDefault('employees', 'token_version');

        $this->assertSame(
            $userSchemaDefault,
            $this->factoryDefault(),
            'UserFactory must produce the value the users column actually defaults to'
        );

        $employee = $this->makeEmployee();
        $staffActual = (int) Employee::where('id', $employee->id)->value('token_version');

        $this->assertSame(
            $employeeSchemaDefault,
            $staffActual,
            'a fresh employee must start on the employees column default'
        );
    }

    /**
     * The property the change could have broken, and the one that actually matters: a token minted for
     * a factory user must validate, whatever the starting value is. This is why `token_version` is
     * self-consistent rather than globally uniform, and it is asserted against the REAL row rather than
     * a literal so it keeps holding if the schema default ever moves again.
     *
     * @test
     */
    public function a_factory_users_token_round_trips_at_whichever_version_the_row_holds(): void
    {
        $user = User::factory()->create();
        $rowVersion = (int) $user->fresh()->token_version;

        $payload = $this->decode($this->userJwt->generateTokenPair($user)['access_token']);

        $this->assertArrayHasKey('ver', $payload, 'a user token must always carry the version claim');
        $this->assertSame(
            $rowVersion,
            (int) $payload['ver'],
            'the claim is minted from the row, not from a constant'
        );
        $this->assertTrue(
            $this->userJwt->validateTokenVersion($payload, $user),
            'and the token therefore validates against its own user'
        );
    }

    // -- Helpers ------------------------------------------------------------------

    private function makeEmployee(): Employee
    {
        return Employee::create([
            'username' => 'rv04_'.uniqid(),
            'email' => 'rv04'.uniqid().'@test.com',
            'password' => 'Password123!',
            'first_name' => 'RV',
            'last_name' => 'Four',
            'role' => 'system_admin',
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $token): array
    {
        $parts = explode('.', $token);

        return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?: [];
    }

    private function schemaDefault(string $table, string $column): int
    {
        $row = DB::selectOne(
            'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return (int) ($row->COLUMN_DEFAULT ?? -1);
    }

    private function factoryDefault(): int
    {
        $row = (new \ReflectionClass(UserFactory::class))->newInstance();

        return (int) $row->definition()['token_version'];
    }
}
