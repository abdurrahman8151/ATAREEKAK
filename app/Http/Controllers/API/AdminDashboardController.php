<?php

namespace App\Http\Controllers\API;

use App\Domain\ValueObjects\Money;
use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAuthService;
use App\Services\Admin\AdminWalletService;
use App\Services\Admin\AdminReportService;
use App\Services\Admin\AdminExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;      // ← added
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

final class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly AdminAuthService   $authService,
        private readonly AdminWalletService $walletService,
        private readonly AdminReportService $reportService,
        private readonly AdminExportService $exportService,
    ) {}

    // =========================================================================
    // AUTH — never cached (tokens / credentials must always be live)
    // =========================================================================

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required_without:username|nullable|string',
            'username' => 'required_without:email|nullable|string',
            'password' => 'required|string',
        ], [
            'email.required_without'    => 'Please provide an email or username.',
            'username.required_without' => 'Please provide an email or username.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'code'   => 'VALIDATION_FAILED',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = $request->input('username') ?? $request->input('email');
        $result     = $this->authService->authenticate($identifier, $request->password);

        if (!$result) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'INVALID_CREDENTIALS',
                'message' => 'Invalid admin credentials',
            ], 401);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Login successful',
            'admin'   => $result['admin'],
            'tokens'  => $result['tokens'],
        ]);
    }

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

        $tokens = $this->authService->refresh($request->refresh_token);

        if (!$tokens) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'REFRESH_TOKEN_INVALID',
                'message' => 'Invalid or expired refresh token',
            ], 401);
        }

        return response()->json([
            'status' => 'success',
            'tokens' => $tokens,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var \App\Models\Employee $admin */
        $admin = $request->attributes->get('staffEmployee');

        $this->authService->logout($admin->id);

        return response()->json([
            'status'  => 'success',
            'message' => 'Logged out successfully',
        ]);
    }

    // =========================================================================
    // DASHBOARD — BFF
    // =========================================================================

    /**
     * CACHED — 5 minutes.
     * The BFF payload is the most expensive call (multiple sub-queries across
     * users, rides, wallets). All 3 cluster nodes share this single Redis key,
     * so only ONE MySQL hit per 5-minute window regardless of traffic.
     */
    public function dashboard(): JsonResponse
    {
        try {
            $data = Cache::remember('admin.dashboard.data', 300, function () {
                return $this->reportService->getDashboardData();
            });

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Dashboard data failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load dashboard data',
            ], 500);
        }
    }

    /**
     * CACHED — 5 minutes.
     * Stat cards are aggregate counts. A 5-minute stale window is acceptable
     * for an admin overview panel.
     */
    public function dashboardStats(): JsonResponse
    {
        $data = Cache::remember('admin.dashboard.stats', 300, function () {
            return $this->reportService->getStats();
        });

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }

    /**
     * CACHED — 15 minutes per $months value.
     * Growth chart is historical; month-level data does not change within a session.
     * Up to 12 possible Redis keys (months 1–12).
     */
    public function dashboardGrowth(Request $request): JsonResponse
    {
        $months = (int) $request->get('months', 6);
        $months = max(1, min($months, 12));

        $data = Cache::remember("admin.dashboard.growth.{$months}", 900, function () use ($months) {
            return $this->reportService->getGrowthChart($months);
        });

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }

    /**
     * CACHED — 30 minutes.
     * City/geographic distribution shifts very slowly; a long TTL is safe.
     */
    public function dashboardCities(): JsonResponse
    {
        $data = Cache::remember('admin.dashboard.cities', 1800, function () {
            return $this->reportService->getCityDistribution();
        });

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }

    /**
     * CACHED — 1 minute per $limit value.
     * "Recent" implies freshness, so 60 s is the shortest cache window
     * worth having (still removes the per-request DB hit under load).
     */
    public function dashboardRecent(Request $request): JsonResponse
    {
        $limit = (int) $request->get('limit', 10);
        $limit = max(1, min($limit, 50));

        $data = Cache::remember("admin.dashboard.recent.{$limit}", 60, function () use ($limit) {
            return $this->reportService->getRecentActivities($limit);
        });

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }

    // =========================================================================
    // WALLET — never cached (live financial data must always be exact)
    // =========================================================================

    public function getAdminWallet(Request $request): JsonResponse
    {
        $adminConfig = $this->authService->getAdminConfigFromRequest($request);
        $wallet      = $this->walletService->getOrCreateWallet($adminConfig);

        return response()->json([
            'status' => 'success',
            'wallet' => [
                'id'            => $wallet->id,
                'wallet_number' => $wallet->wallet_number,
                'phone_number'  => $wallet->phone_number,
                'balance'       => Money::from($wallet->balance)->formatted(),
                'admin_type'    => $adminConfig['type'],
            ],
        ]);
    }

    public function getAdminWallets(): JsonResponse
    {
        return response()->json([
            'status'        => 'success',
            'admin_wallets' => $this->walletService->getAdminWallets(),
            'all_wallets'   => $this->walletService->getAllWallets(),
        ]);
    }

    public function chargeWallet(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|min:10|max:15',
            'amount'       => 'required|numeric|min:1|max:1000000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'code'   => 'VALIDATION_FAILED',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $adminConfig = $this->authService->getAdminConfigFromRequest($request);
            $amount      = Money::from((float) $request->amount);
            $result      = $this->walletService->chargeWallet(
                $request->phone_number,
                $amount,
                $adminConfig
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Wallet charged successfully',
                'wallet'  => [
                    'phone_number'     => $result['wallet']->phone_number,
                    'previous_balance' => $result['previous_balance']->formatted(),
                    'new_balance'      => $result['new_balance']->formatted(),
                ],
                'transaction_id' => $result['transaction']->transaction_id,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'WALLET_NOT_FOUND',
                'message' => 'No wallet found for this phone number',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Admin wallet charge failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to charge wallet',
            ], 500);
        }
    }

    public function showWalletTransactions(int $walletId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page'     => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $result = $this->walletService->getWalletTransactions(
                $walletId,
                (int) $request->get('per_page', 10)
            );

            return response()->json([
                'status' => 'success',
                'wallet' => $result['wallet'],
                'data'   => $result['data'],
                'meta'   => $result['meta'],
            ]);
        } catch (\Exception) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Wallet not found',
            ], 404);
        }
    }

    // =========================================================================
    // FINANCIAL REPORT  [primary only]
    // =========================================================================

    /**
     * CACHED — 5 minutes per date range.
     * generateReport() is the most expensive query in the system. A 5-minute
     * cache window is safe because financial totals don't shift meaningfully
     * within that period. Key includes the date range so different ranges each
     * get their own Redis entry.
     */
    public function showReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $startKey = $request->input('start_date', 'all');
            $endKey   = $request->input('end_date', 'all');
            $cacheKey = "admin.report.{$startKey}.{$endKey}";

            $report = Cache::remember($cacheKey, 300, function () use ($request) {
                return $this->reportService->generateReport(
                    $request->input('start_date'),
                    $request->input('end_date'),
                );
            });

            return response()->json([
                'status'      => 'success',
                'report_data' => $report,
            ]);
        } catch (\Exception $e) {
            Log::error('Report generation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to generate report',
            ], 500);
        }
    }

    // =========================================================================
    // PDF EXPORT  [primary only] — never cached (binary stream, no-store header)
    // =========================================================================

    public function exportPdf(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'sections'   => 'nullable|array',
            'sections.*' => 'in:stats,financial,growth,cities,recent',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $pdfBytes = $this->exportService->exportDashboardPdf(
                startDate: $request->input('start_date'),
                endDate:   $request->input('end_date'),
                sections:  $request->input('sections', []),
            );

            $filename = $this->exportService->buildFilename(
                $request->input('start_date'),
                $request->input('end_date'),
            );

            return response($pdfBytes, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"{$filename}\"",
                'Content-Length'      => strlen($pdfBytes),
                'Cache-Control'       => 'no-store, no-cache',
            ]);

        } catch (\Exception $e) {
            Log::error('PDF export failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to generate PDF report',
            ], 500);
        }
    }

    // =========================================================================
    // VERIFICATION  [primary only]
    // =========================================================================

    /**
     * NOT cached.
     * Admins work through this as a live action queue. Caching would show a
     * stale count in the same session immediately after approving/rejecting.
     */
    public function pendingVerifications(): JsonResponse
    {
        $pending = \App\Models\User::with(['photos', 'profile'])
            ->where('verification_status', 'pending')
            ->get()
            ->map(function ($u) {
                $docTypes = $u->photos->pluck('type')->toArray();
                $isDriver = in_array('license', $docTypes) || in_array('mechanic_card', $docTypes);

                return [
                    'user_id'      => $u->id,
                    'name'         => trim("{$u->first_name} {$u->last_name}"),
                    'email'        => $u->email,
                    'type'         => $isDriver ? 'driver' : 'passenger',
                    'documents'    => $u->photos->map(fn($p) => [
                        'type' => $p->type,
                        'url'  => asset("storage/{$p->path}"),
                    ]),
                    'submitted_at' => $u->updated_at->toIso8601String(),
                ];
            });

        return response()->json(['status' => 'success', 'data' => $pending]);
    }

    /**
     * Mutation — not cached. Busts all dashboard and driver-stat caches so the
     * next read reflects the newly verified user.
     */
    public function approveVerification(int $userId, Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'national_id' => 'required|digits:10',
        ], [
            'national_id.required' => 'The national ID number is required to approve verification.',
            'national_id.digits'   => 'The national ID number must be exactly 10 digits.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $nationalId = trim($request->input('national_id'));

        $duplicate = \App\Models\User::where('national_id', $nationalId)
            ->where('id', '!=', $userId)
            ->first();

        if ($duplicate) {
            return response()->json([
                'status'  => 'error',
                'message' => 'This national ID is already linked to another verified account. Verification blocked.',
                'data'    => [
                    'conflicting_user_id' => $duplicate->id,
                ],
            ], 422);
        }

        try {
            $user     = \App\Models\User::with(['photos'])->findOrFail($userId);
            $docTypes = $user->photos->pluck('type')->toArray();
            $isDriver = in_array('license', $docTypes) || in_array('mechanic_card', $docTypes);

            $repo         = app(\App\Interfaces\VerificationRepositoryInterface::class);
            $verifiedUser = $isDriver
                ? $repo->verifyDriver($userId)
                : $repo->verifyPassenger($userId);

            $verifiedUser->national_id = $nationalId;
            $verifiedUser->save();

            // Bust caches that reflect user/driver counts and verification stats
            $this->bustVerificationCaches();

            return response()->json([
                'status'  => 'success',
                'message' => ($isDriver ? 'Driver' : 'Passenger') . ' verification approved',
                'user'    => [
                    'id'                  => $verifiedUser->id,
                    'national_id'         => $verifiedUser->national_id,
                    'verification_status' => $verifiedUser->verification_status,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Mutation — not cached. Busts the same caches as approveVerification.
     */
    public function rejectVerification(int $userId): JsonResponse
    {
        $user = \App\Models\User::findOrFail($userId);
        $user->update([
            'verification_status'   => 'rejected',
            'is_verified_passenger' => false,
            'is_verified_driver'    => false,
        ]);

        $this->bustVerificationCaches();

        return response()->json(['status' => 'success', 'message' => 'Verification rejected']);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Clears all caches that become stale after a verification decision.
     *
     * Covers:
     *   - Main dashboard BFF and stat cards
     *   - Driver management BFF and stat cards
     *   - Verification efficiency (3 period variants)
     */
    private function bustVerificationCaches(): void
    {
        Cache::forget('admin.dashboard.data');
        Cache::forget('admin.dashboard.stats');
        Cache::forget('admin.drivers.dashboard');
        Cache::forget('admin.drivers.stats');

        foreach (['day', 'week', 'month'] as $period) {
            Cache::forget("admin.drivers.verification.efficiency.{$period}");
        }
    }
}
