<?php

namespace App\Modules\Identity\Services;

use App\DTOs\Auth\StaffAccount;
use App\Modules\Console\Contracts\EmployeeRepositoryInterface;
use App\Modules\Identity\Contracts\EmployeeAuthInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * EmployeeAuthService
 *
 * Handles authentication concerns (login / refresh / logout / me).
 * Authorization (who can do what) lives in EmployeeManagementService.
 */
final class EmployeeAuthService implements EmployeeAuthInterface
{
    /**
     * A fixed bcrypt hash compared against when the login identifier does not exist, purely
     * to spend the same ~100ms of work the real path spends, so response timing does not
     * reveal whether an account exists. It is a hash of a value nobody can supply as a
     * password (a random, never-issued secret), so this can never authenticate anyone.
     */
    private const DUMMY_HASH = '$2y$12$KdyEj/F8DFgVEmYFFnVsQemtKoKwSbpX1z/gWy061r3yCUjoemuo6';

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
        // Stage 2 C2: `employees` is Console's, so the row arrives as a `StaffCredentials` DTO (account +
        // stored hash) from its repository - this module never names the model.
        $credentials = $this->employeeRepository->credentialsFor($identifier);

        if (! $credentials) {
            // RV-29 (timing oracle): when the identifier is unknown we used to return BEFORE
            // any Hash::check, so the ~100ms bcrypt work only happened for an EXISTING
            // account. That let an attacker distinguish "no such user" from "wrong password"
            // by timing alone and enumerate valid usernames/emails, even though the HTTP
            // response is already uniform (401 / INVALID_CREDENTIALS). Burn the same work
            // here against a fixed dummy hash so both paths cost the same.
            Hash::check($password, self::DUMMY_HASH);

            Log::warning('Staff login: unknown identifier', ['identifier' => $identifier]);

            return null;
        }

        $account = $credentials->account;

        if (! Hash::check($password, $credentials->passwordHash)) {
            Log::warning('Staff login: bad password', ['employee_id' => $account->id]);

            return null;
        }

        if (! $account->isActive) {
            Log::warning('Staff login: inactive account', ['employee_id' => $account->id]);

            return null;
        }

        // The response reports the timestamp this call writes: the old code mutated the model in memory
        // and then formatted it, so `last_login_at` was the NEW value, never the stale one.
        $loggedInAt = $this->employeeRepository->markLoggedIn($account->id);

        $tokens = $this->jwtService->generateTokenPair($account->tokenSubject());

        Log::info('Staff login successful', [
            'employee_id' => $account->id,
            'role' => $account->role->value,
        ]);

        return [
            'tokens' => $tokens,
            'employee' => $this->formatEmployee($account->withLastLoginAt($loggedInAt)),
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

    public function formatEmployee(StaffAccount $account): array
    {
        return [
            'id' => $account->id,
            'username' => $account->username,
            'email' => $account->email,
            'full_name' => $account->fullName(),
            'role' => $account->role->value,
            'role_label' => $account->role->label(),
            'is_active' => $account->isActive,
            'last_login_at' => $account->lastLoginAt?->toIso8601String(),
            'created_at' => $account->createdAt->toIso8601String(),
        ];
    }
}
