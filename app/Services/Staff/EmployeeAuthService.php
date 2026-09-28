<?php

namespace App\Services\Staff;

use App\Interfaces\EmployeeRepositoryInterface;
use App\Models\Employee;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * EmployeeAuthService
 *
 * Handles authentication concerns (login / refresh / logout / me).
 * Authorization (who can do what) lives in EmployeeManagementService.
 */
final class EmployeeAuthService
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $employeeRepository,
        private readonly StaffJwtService $jwtService,
    ) {}

    // ── Login ─────────────────────────────────────────────────────────────────

    /**
     * Validate credentials and return a token pair + employee data.
     * Accepts username OR email as identifier.
     *
     * @return array{tokens: array, employee: array}|null
     */
    public function authenticate(string $identifier, string $password): ?array
    {
        $employee = $this->resolveEmployee($identifier);

        if (! $employee) {
            Log::warning('Staff login: unknown identifier', ['identifier' => $identifier]);

            return null;
        }

        if (! Hash::check($password, $employee->password)) {
            Log::warning('Staff login: bad password', ['employee_id' => $employee->id]);

            return null;
        }

        if (! $employee->is_active) {
            Log::warning('Staff login: inactive account', ['employee_id' => $employee->id]);

            return null;
        }

        $employee->update(['last_login_at' => now()]);

        $tokens = $this->jwtService->generateTokenPair($employee);

        Log::info('Staff login successful', [
            'employee_id' => $employee->id,
            'role' => $employee->role->value,
        ]);

        return [
            'tokens' => $tokens,
            'employee' => $this->formatEmployee($employee),
        ];
    }

    // ── Refresh ───────────────────────────────────────────────────────────────

    public function refresh(string $refreshToken): ?array
    {
        return $this->jwtService->refreshAccessToken($refreshToken);
    }

    // ── Logout ────────────────────────────────────────────────────────────────

    public function logout(int $employeeId): void
    {
        $this->jwtService->revokeAllTokens($employeeId);
        Log::info('Staff logout', ['employee_id' => $employeeId]);
    }

    // ── Profile ───────────────────────────────────────────────────────────────

    public function formatEmployee(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'username' => $employee->username,
            'email' => $employee->email,
            'full_name' => $employee->fullName(),
            'role' => $employee->role->value,
            'role_label' => $employee->role->label(),
            'is_active' => $employee->is_active,
            'last_login_at' => $employee->last_login_at?->toIso8601String(),
            'created_at' => $employee->created_at->toIso8601String(),
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function resolveEmployee(string $identifier): ?Employee
    {
        // Try username first, then email.
        return $this->employeeRepository->findByUsername($identifier)
            ?? $this->employeeRepository->findByEmail($identifier);
    }
}
