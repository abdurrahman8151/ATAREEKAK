<?php

namespace Tests\Feature\Review;

use App\Models\Employee;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T3-4 - `wallet_requests.processed_by` references `users`; the acting principal is an `Employee`.
 *
 * WHY THIS EXISTS. T2-1 fixed "the admin is never recorded" by binding a user resolver in
 * `StaffJwtMiddleware`. The resolver cannot return the employee, because these columns carry real
 * foreign keys to `users` and an Employee id there raises SQLSTATE[23000] 1452 and aborts the
 * money-moving transaction. So it returns a SHADOW user, resolved by
 * `EmployeeManagementService::ensureShadowUser()`, which looks up `User::where('email', $employee-
 * email)->first()` and RETURNS WHATEVER IT FINDS.
 *
 * That is the defect T3-4 names, and this file proves it is real rather than theoretical: when a
 * real customer already holds the admin's email address, the "shadow" IS that customer, and the
 * financial audit trail records a real customer's id as the acting admin. Nothing surfaces it -
 * the value resolves in `users`, so every FK and every read looks healthy.
 *
 * Owner decision D8 = A: add nullable `processed_by_employee_id` -> `employees.id` and write the
 * real actor at both admin write sites. `processed_by` is KEPT and still written, so no historical
 * row changes meaning.
 *
 * Every test here drives the REAL authenticated HTTP endpoint (staff login -> admin route), not the
 * service layer, so the middleware path is genuinely exercised.
 */
class RVWalletRequestEmployeeAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Decision un3: the admin approve path posts an EXTERNAL inflow against the External
        // Capital account and fails loud if it is missing. Seeded here like the sibling T2-1 suite.
        Wallet::firstOrCreate(
            ['phone_number' => config('admin.external.phone')],
            [
                'user_id' => null,
                'wallet_number' => 'EXT'.random_int(1000000000, 9999999999),
                'balance' => 0,
                'kind' => 'system',
            ],
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{0: Employee, 1: string} */
    private function staff(string $role = 'system_admin', ?string $email = null): array
    {
        $employee = Employee::create([
            'username' => 't34_'.uniqid(),
            'email' => $email ?? ('t34_'.uniqid().'@test.com'),
            'password' => 'Password123!',
            'first_name' => 'Ops',
            'last_name' => 'Tester',
            'role' => $role,
            'is_active' => true,
            'token_version' => 0,
        ]);

        $token = $this->postJson('/api/staff/login', [
            'identifier' => $employee->username,
            'password' => 'Password123!',
        ])->json('tokens.access_token');

        $this->assertNotEmpty($token, 'staff login must return an access token');

        return [$employee, $token];
    }

    private function pendingRequest(User $user, float $amount = 50000): WalletRequest
    {
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'phone_number' => '09'.random_int(10000000, 99999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 0,
        ]);

        return WalletRequest::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => 'charge',
            'amount' => $amount,
            'status' => 'pending',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 1. The defect itself, demonstrated rather than asserted
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function approving_records_the_employee_even_when_a_real_customer_holds_the_admin_email(): void
    {
        // A REAL customer, not a shadow. This is the collision T3-4 is about.
        $customer = User::factory()->create([
            'email' => 'collision@admin.test',
            'status' => 1,
        ]);

        // An admin whose email is that same address, so ensureShadowUser() finds the customer.
        [$employee, $token] = $this->staff('system_admin', 'collision@admin.test');

        $passenger = User::factory()->create(['status' => 1]);
        $walletRequest = $this->pendingRequest($passenger);

        $this->withToken($token)
            ->postJson("/api/admin/wallet/requests/{$walletRequest->id}/approve", [
                'admin_notes' => 'receipt verified',
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $walletRequest->refresh()->load('processorEmployee');

        // The FIX: the decision is attributed to the employee who actually made it.
        $this->assertSame(
            $employee->id,
            $walletRequest->processed_by_employee_id,
            'the wallet request must record the acting EMPLOYEE'
        );
        $this->assertSame(
            $employee->id,
            $walletRequest->processorEmployee->id,
            'processorEmployee must resolve to the acting employee'
        );

        // THE DEFECT, made visible: the legacy column alone would have named the CUSTOMER.
        // This is what D8 = A keeps for history and the new column now overrides.
        $this->assertSame(
            $customer->id,
            $walletRequest->processed_by,
            'ensureShadowUser returns the pre-existing user by email - the exact id confusion T3-4 closes'
        );
    }

    /**
     * @test
     */
    public function the_recorded_employee_is_the_one_who_clicked_not_a_constant(): void
    {
        [$first, $firstToken] = $this->staff('system_admin');
        [$second, $secondToken] = $this->staff('system_admin');

        $passenger = User::factory()->create(['status' => 1]);

        $a = $this->pendingRequest($passenger);
        $this->withToken($firstToken)
            ->postJson("/api/admin/wallet/requests/{$a->id}/approve")->assertStatus(200);

        $b = $this->pendingRequest($passenger);
        $this->withToken($secondToken)
            ->postJson("/api/admin/wallet/requests/{$b->id}/reject")->assertStatus(200);

        $this->assertSame($first->id, $a->refresh()->processed_by_employee_id);
        $this->assertSame($second->id, $b->refresh()->processed_by_employee_id);
        $this->assertNotSame($first->id, $second->id, 'the two employees must be distinct');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 2. Both write sites
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function rejecting_also_records_the_employee(): void
    {
        [$employee, $token] = $this->staff('system_admin');
        $passenger = User::factory()->create(['status' => 1]);
        $walletRequest = $this->pendingRequest($passenger, 25000);

        $this->withToken($token)
            ->postJson("/api/admin/wallet/requests/{$walletRequest->id}/reject", [
                'admin_notes' => 'no matching receipt',
            ])
            ->assertStatus(200);

        $walletRequest->refresh()->load('processorEmployee');

        $this->assertSame('rejected', $walletRequest->status);
        $this->assertSame($employee->id, $walletRequest->processed_by_employee_id);
        $this->assertSame($employee->email, $walletRequest->processorEmployee->email);
    }

    /**
     * @test
     */
    public function processed_by_is_still_written_so_no_historical_row_loses_its_actor(): void
    {
        [$employee, $token] = $this->staff('system_admin');
        $passenger = User::factory()->create(['status' => 1]);
        $walletRequest = $this->pendingRequest($passenger);

        $this->withToken($token)
            ->postJson("/api/admin/wallet/requests/{$walletRequest->id}/approve")->assertStatus(200);

        $walletRequest->refresh();

        $this->assertNotNull(
            $walletRequest->processed_by,
            'D8 = A keeps processed_by; dropping it would orphan every historical row'
        );
        $this->assertTrue(
            User::whereKey($walletRequest->processed_by)->exists(),
            'processed_by must still satisfy its FK to users'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 3. Denied paths must stay denied AND stay unattributed
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function a_forbidden_role_still_cannot_process_and_records_nobody(): void
    {
        [, $token] = $this->staff('support_agent');
        $passenger = User::factory()->create(['status' => 1]);
        $walletRequest = $this->pendingRequest($passenger);

        $this->withToken($token)
            ->postJson("/api/admin/wallet/requests/{$walletRequest->id}/approve")
            ->assertStatus(403);

        $walletRequest->refresh();

        $this->assertSame('pending', $walletRequest->status, 'a forbidden actor must not change status');
        $this->assertNull($walletRequest->processed_by_employee_id, 'nobody processed it, so nobody is recorded');
        $this->assertNull($walletRequest->processed_by);
    }

    /**
     * @test
     */
    public function an_unauthenticated_caller_is_rejected_and_records_nobody(): void
    {
        $passenger = User::factory()->create(['status' => 1]);
        $walletRequest = $this->pendingRequest($passenger);

        $this->postJson("/api/admin/wallet/requests/{$walletRequest->id}/approve")
            ->assertStatus(401);

        $walletRequest->refresh();

        $this->assertSame('pending', $walletRequest->status);
        $this->assertNull($walletRequest->processed_by_employee_id);
    }

    /**
     * @test
     */
    public function a_pending_request_records_nobody(): void
    {
        $passenger = User::factory()->create(['status' => 1]);
        $walletRequest = $this->pendingRequest($passenger);

        $this->assertNull($walletRequest->processed_by_employee_id);
        $this->assertNull($walletRequest->processed_at);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // 4. The column must not be able to destroy financial records
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * @test
     */
    public function removing_an_employee_nulls_the_reference_and_keeps_the_request(): void
    {
        [$employee, $token] = $this->staff('system_admin');
        $passenger = User::factory()->create(['status' => 1]);
        $walletRequest = $this->pendingRequest($passenger);

        $this->withToken($token)
            ->postJson("/api/admin/wallet/requests/{$walletRequest->id}/approve")->assertStatus(200);
        $this->assertSame($employee->id, $walletRequest->fresh()->processed_by_employee_id);

        // ON DELETE SET NULL, matching processed_by. Deleting an employee must NOT cascade into
        // deleting an approved financial request.
        $employee->delete();

        $walletRequest->refresh();
        $this->assertModelExists($walletRequest, 'the approved request must survive its processor');
        $this->assertSame('approved', $walletRequest->status);
        $this->assertNull($walletRequest->processed_by_employee_id);
    }
}
