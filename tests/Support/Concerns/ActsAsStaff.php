<?php

namespace Tests\Support\Concerns;

use App\Enums\StaffRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\JwtService;

/**
 * RV-34 — shared staff/admin/user token helpers.
 *
 * Cause B of the red suite: admin-login helpers posted a config email/password to
 * /api/admin/login, but admin auth now authenticates an Employee BY USERNAME
 * (credentials live in the employees table, seeded at deployment). Every one of
 * those helpers therefore got a null token and errored its whole file (~110-130
 * tests).
 *
 * These helpers mint a real Employee and authenticate through the real endpoints
 * — staff via /api/staff/login with `identifier`, admin via /api/admin/login with
 * `username` — matching the proven reference AdminFinancialSurfaceAuthorizationTest
 * and RV-04's rule that a staff token and a user token are separate audiences.
 */
trait ActsAsStaff
{
    /** Password every test employee is created with (never a real credential). */
    private string $rv34Password = 'Password123!';

    /**
     * Create an active Employee of the given role.
     */
    protected function employee(StaffRole|string $role = StaffRole::SYSTEM_ADMIN): Employee
    {
        $value = $role instanceof StaffRole ? $role->value : $role;
        $suffix = uniqid();

        return Employee::create([
            'username' => "rv34_{$value}_{$suffix}",
            'email' => "rv34_{$value}_{$suffix}@test.com",
            'password' => $this->rv34Password,
            'first_name' => 'Rv34',
            'last_name' => ucfirst(str_replace('_', '', $value)),
            'role' => $value,
            'is_active' => true,
            'token_version' => 0,
        ]);
    }

    /**
     * Staff access token via the staff door (identifier = username).
     */
    protected function staffToken(?Employee $employee = null, StaffRole|string $role = StaffRole::SYSTEM_ADMIN): string
    {
        $employee ??= $this->employee($role);

        return (string) $this->postJson('/api/staff/login', [
            'identifier' => $employee->username,
            'password' => $this->rv34Password,
        ])->json('tokens.access_token');
    }

    /**
     * Admin access token via the admin door (username, NOT email).
     */
    protected function adminToken(?Employee $employee = null, StaffRole|string $role = StaffRole::SYSTEM_ADMIN): string
    {
        $employee ??= $this->employee($role);

        return (string) $this->postJson('/api/admin/login', [
            'username' => $employee->username,
            'password' => $this->rv34Password,
        ])->json('tokens.access_token');
    }

    /**
     * A normal authenticated user access token.
     */
    protected function userToken(?User $user = null): string
    {
        $user ??= User::factory()->create(['status' => 1]);

        return (string) app(JwtService::class)->generateTokenPair($user)['access_token'];
    }
}
