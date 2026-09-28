<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Services\Staff\StaffJwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AdminJwtMiddleware
 *
 * Guards routes that require system_admin or sycash access.
 *
 * What changed vs. the old version:
 *   OLD → decoded a User JWT, then matched the user's email against
 *         hardcoded values in config/admin.php to decide "is this an admin?".
 *   NEW → decodes an Employee JWT (via StaffJwtService), then checks that
 *         the Employee's role is an admin role (system_admin or sycash).
 *         The database IS the source of truth — no config lookup needed.
 *
 * Usage:
 *   middleware('auth.admin')               — any admin role
 *   middleware('auth.admin:system_admin')  — system_admin only
 *   middleware('auth.admin:sycash')        — sycash only
 */
final class AdminJwtMiddleware
{
    public function __construct(
        private readonly StaffJwtService $staffJwtService,
    ) {}

    public function handle(Request $request, Closure $next, ?string $requiredRole = null): Response
    {
        // ── 1. Extract Bearer token ───────────────────────────────────────────
        $token = $this->extractToken($request);
        if (! $token) {
            return $this->unauthorized('Admin access token missing.');
        }

        // ── 2. Decode & verify signature + expiry ─────────────────────────────
        $payload = $this->staffJwtService->decodeToken($token);
        if (! $payload) {
            return $this->unauthorized('Invalid or expired admin token.');
        }

        // ── 3. Must be an access token ────────────────────────────────────────
        if (($payload['type'] ?? null) !== 'access') {
            return $this->unauthorized('Provide the access token, not the refresh token.');
        }

        // ── 4. Load the Employee record ───────────────────────────────────────
        $employee = Employee::find($payload['sub']);
        if (! $employee) {
            return $this->unauthorized('Account not found.');
        }

        if (! $employee->is_active) {
            return $this->unauthorized('This account has been deactivated.');
        }

        // ── 5. Verify this is an admin-level role ─────────────────────────────
        //      system_admin and sycash are the only two admin roles.
        //      Regular admins and support agents use StaffJwtMiddleware instead.
        if (! $employee->role->isAdminRole()) {
            return $this->unauthorized(
                'Access denied. This endpoint requires an admin account.'
            );
        }

        // ── 6. Token version check (invalidates tokens after password rotation)─
        if (! $this->staffJwtService->validateTokenVersion($payload, $employee)) {
            return $this->unauthorized(
                'Your session has been invalidated. Please log in again.'
            );
        }

        // ── 7. Optional per-route role gate ───────────────────────────────────
        //      middleware('auth.admin:sycash') restricts to financial admin only.
        if ($requiredRole && $employee->role->value !== $requiredRole) {
            return response()->json([
                'status' => 'error',
                'code' => 'FORBIDDEN',
                'message' => "This action requires '{$requiredRole}' access.",
            ], 403);
        }

        // ── 8. Inject into request ────────────────────────────────────────────
        // Set user resolver so $request->user()->id works in all admin controllers.
        $request->setUserResolver(fn () => $employee);
        $request->attributes->set('adminEmployee', $employee);
        $request->attributes->set('adminType', $employee->role->value);

        return $next($request);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'status' => 'error',
            'code' => 'UNAUTHORIZED',
            'message' => $message,
        ], 401);
    }
}
