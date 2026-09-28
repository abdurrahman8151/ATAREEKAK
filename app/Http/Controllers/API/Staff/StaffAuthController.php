<?php

namespace App\Http\Controllers\API\Staff;

use App\Http\Controllers\Controller;
use App\Services\Staff\EmployeeAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * StaffAuthController
 *
 * Thin controller — all business logic lives in EmployeeAuthService.
 * Public: login, refresh.
 * Protected (staff middleware): logout, me.
 */
final class StaffAuthController extends Controller
{
    public function __construct(
        private readonly EmployeeAuthService $authService,
    ) {}

    // ── POST /api/staff/login ─────────────────────────────────────────────────

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identifier' => 'required|string|max:255',  // username or email
            'password' => 'required|string',
        ], [
            'identifier.required' => 'Please provide your username or email.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->authService->authenticate(
            $request->input('identifier'),
            $request->input('password')
        );

        if (! $result) {
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Invalid credentials or account is inactive.',
            ], 401);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful.',
            'employee' => $result['employee'],
            'tokens' => $result['tokens'],
        ]);
    }

    // ── POST /api/staff/refresh ───────────────────────────────────────────────

    public function refresh(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokens = $this->authService->refresh($request->input('refresh_token'));

        if (! $tokens) {
            return response()->json([
                'status' => 'error',
                'code' => 'REFRESH_TOKEN_INVALID',
                'message' => 'Invalid or expired refresh token.',
            ], 401);
        }

        return response()->json([
            'status' => 'success',
            'tokens' => $tokens,
        ]);
    }

    // ── POST /api/staff/logout  [staff] ───────────────────────────────────────

    public function logout(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('staffEmployee');

        $this->authService->logout($employee->id);

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully.',
        ]);
    }

    // ── GET /api/staff/me  [staff] ────────────────────────────────────────────

    public function me(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('staffEmployee');

        return response()->json([
            'status' => 'success',
            'employee' => $this->authService->formatEmployee($employee),
        ]);
    }
}
