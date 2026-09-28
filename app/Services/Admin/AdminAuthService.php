<?php

namespace App\Services\Admin;

use App\Models\Employee;
use App\Services\Staff\StaffJwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * AdminAuthService
 *
 * Handles authentication for system_admin and sycash employees.
 *
 * What changed vs. the old version:
 *   OLD → looked up a User by email, then verified the email existed in
 *         config/admin.php to decide "this person is an admin".
 *   NEW → looks up an Employee by username OR email, then checks the
 *         Employee's role is an admin role. The DB is the source of truth.
 *
 * Token management is delegated entirely to StaffJwtService so admin
 * tokens and staff tokens share the same format and revocation table.
 */
final class AdminAuthService
{
    public function __construct(
        private readonly StaffJwtService $jwtService,
    ) {}

    // =========================================================================
    // AUTH
    // =========================================================================

    /**
     * Authenticate a system_admin or sycash account.
     *
     * @param  string  $identifier  Username or email
     * @return array{admin: array, tokens: array}|null null on failure
     */
    public function authenticate(string $identifier, string $password): ?array
    {
        $employee = Employee::where('username', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        if (! $employee) {
            return null;
        }

        // Only system_admin and sycash may use the admin panel
        if (! $employee->role->isAdminRole()) {
            Log::warning('Non-admin attempted admin login', [
                'identifier' => $identifier,
                'role' => $employee->role->value,
            ]);

            return null;
        }

        if (! $employee->is_active) {
            return null;
        }

        if (! Hash::check($password, $employee->password)) {
            return null;
        }

        return [
            'admin' => $this->formatAdmin($employee),
            'tokens' => $this->jwtService->generateTokenPair($employee),
        ];
    }

    /**
     * Refresh an admin token pair.
     *
     * @return array{access_token: string, refresh_token: string}|null
     */
    public function refresh(string $refreshToken): ?array
    {
        return $this->jwtService->refreshAccessToken($refreshToken);
    }

    /**
     * Revoke all tokens for the given employee (logout).
     */
    public function logout(int $employeeId): void
    {
        $this->jwtService->revokeAllTokens($employeeId);
    }

    // =========================================================================
    // REQUEST HELPERS
    // =========================================================================

    /**
     * Build the "adminConfig" array that AdminWalletService expects.
     *
     * AdminJwtMiddleware sets 'adminEmployee' on every authenticated request,
     * so this is just a projection — no DB hit.
     */
    public function getAdminConfigFromRequest(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $request->attributes->get('staffEmployee');

        // role->value is 'system_admin' or 'sycash' — matches config/admin.php keys exactly
        $roleKey = $employee->role->value;

        return [
            'type' => $roleKey,
            'phone' => config("admin.$roleKey.phone"),
            'email' => $employee->email,
            'employee_id' => $employee->id,
        ];
    }

    // =========================================================================
    // FORMAT
    // =========================================================================

    public function formatAdmin(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'username' => $employee->username,
            'email' => $employee->email,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'role' => $employee->role->value,
            'role_label' => $employee->role->label(),
        ];
    }
}
