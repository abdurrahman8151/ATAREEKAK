<?php

namespace App\Http\Controllers\API;

use App\Domain\ValueObjects\Money;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\Admin\AdminAuthService;
use App\Services\Admin\AdminDashboardReadService;
use App\Services\Admin\AdminExportService;
use App\Services\Admin\AdminReportService;
use App\Services\Admin\AdminVerificationQueueService;
use App\Services\Admin\AdminWalletService;      // ← added
use App\Services\Verification\DuplicateNationalIdException;
use App\Services\Verification\VerificationApprovalService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class AdminDashboardController extends Controller
{
    private readonly AdminDashboardReadService $reads;

    private readonly AdminVerificationQueueService $verificationQueue;

    public function __construct(
        private readonly AdminAuthService $authService,
        private readonly AdminWalletService $walletService,
        private readonly AdminReportService $reportService,
        private readonly AdminExportService $exportService,
    ) {
        $this->reads = new AdminDashboardReadService($reportService);
        $this->verificationQueue = new AdminVerificationQueueService;
    }

    // =========================================================================
    // DELEGATED READS - the implementations live in the two collaborators
    // =========================================================================

    /** Delegates to AdminDashboardReadService. */
    public function dashboard(): JsonResponse
    {
        return $this->reads->dashboard();
    }

    /** Delegates to AdminDashboardReadService. */
    public function dashboardStats(): JsonResponse
    {
        return $this->reads->dashboardStats();
    }

    /** Delegates to AdminDashboardReadService. */
    public function dashboardCities(): JsonResponse
    {
        return $this->reads->dashboardCities();
    }

    /** Delegates to AdminVerificationQueueService. */
    public function pendingVerifications(): JsonResponse
    {
        return $this->verificationQueue->pendingVerifications();
    }

    /** Delegates to AdminVerificationQueueService. */
    public function rejectVerification(int $userId): JsonResponse
    {
        return $this->verificationQueue->rejectVerification($userId);
    }

    // =========================================================================
    // AUTH — never cached (tokens / credentials must always be live)
    // =========================================================================

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required_without:username|nullable|string',
            'username' => 'required_without:email|nullable|string',
            'password' => 'required|string',
        ], [
            'email.required_without' => 'Please provide an email or username.',
            'username.required_without' => 'Please provide an email or username.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = $request->input('username') ?? $request->input('email');
        $result = $this->authService->authenticate($identifier, $request->password);

        if (! $result) {
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Invalid admin credentials',
            ], 401);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'admin' => $result['admin'],
            'tokens' => $result['tokens'],
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

        if (! $tokens) {
            return response()->json([
                'status' => 'error',
                'code' => 'REFRESH_TOKEN_INVALID',
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
        /** @var Employee $admin */
        $admin = $request->attributes->get('staffEmployee');

        $this->authService->logout($admin->id);

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully',
        ]);
    }

    // =========================================================================
    // DASHBOARD — BFF
    // =========================================================================

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
            'data' => $data,
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
            'data' => $data,
        ]);
    }

    // =========================================================================
    // WALLET — never cached (live financial data must always be exact)
    // =========================================================================

    public function getAdminWallet(Request $request): JsonResponse
    {
        $adminConfig = $this->authService->getAdminConfigFromRequest($request);
        $wallet = $this->walletService->getOrCreateWallet($adminConfig);

        return response()->json([
            'status' => 'success',
            'wallet' => [
                'id' => $wallet->id,
                'wallet_number' => $wallet->wallet_number,
                'phone_number' => $wallet->phone_number,
                'balance' => Money::from($wallet->balance)->formatted(),
                'admin_type' => $adminConfig['type'],
            ],
        ]);
    }

    public function getAdminWallets(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'admin_wallets' => $this->walletService->getAdminWallets(),
            'all_wallets' => $this->walletService->getAllWallets(),
        ]);
    }

    public function chargeWallet(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|min:10|max:15',
            'amount' => 'required|numeric|min:1|max:1000000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $adminConfig = $this->authService->getAdminConfigFromRequest($request);
            $amount = Money::from((float) $request->amount);
            $result = $this->walletService->chargeWallet(
                $request->phone_number,
                $amount,
                $adminConfig
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Wallet charged successfully',
                'wallet' => [
                    'phone_number' => $result['wallet']->phone_number,
                    'previous_balance' => $result['previous_balance']->formatted(),
                    'new_balance' => $result['new_balance']->formatted(),
                ],
                'transaction_id' => $result['transaction']->transaction_id,
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => 'error',
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'No wallet found for this phone number',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Admin wallet charge failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to charge wallet',
            ], 500);
        }
    }

    public function showWalletTransactions(int $walletId): JsonResponse
    {
        try {
            $result = $this->walletService->getWalletTransactions($walletId);

            return response()->json([
                'status' => 'success',
                'wallet' => $result['wallet'],
                'transactions' => $result['transactions'],
            ]);
        } catch (\Exception) {
            return response()->json([
                'status' => 'error',
                'message' => 'Wallet not found',
            ], 404);
        }
    }

    // T4-7: uploadAdminPhoto() was removed. It returned
    // {'status':'success','message':'Photo uploaded'} while uploading nothing
    // — the employees table has no photo column, so there was nothing to write
    // to. Reporting success for an action that never happened is worse than a
    // missing endpoint; its route was removed alongside it.
    // A real admin-photo upload needs a schema column + storage policy and
    // should be designed, not re-added as a stub.

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
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $startKey = $request->input('start_date', 'all');
            $endKey = $request->input('end_date', 'all');
            $cacheKey = "admin.report.{$startKey}.{$endKey}";

            $report = Cache::remember($cacheKey, 300, function () use ($request) {
                return $this->reportService->generateReport(
                    $request->input('start_date'),
                    $request->input('end_date'),
                );
            });

            return response()->json([
                'status' => 'success',
                'report_data' => $report,
            ]);
        } catch (\Exception $e) {
            Log::error('Report generation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate report',
            ], 500);
        }
    }

    // =========================================================================
    // PDF EXPORT  [primary only] — never cached (binary stream, no-store header)
    // =========================================================================

    public function exportPdf(Request $request): Response
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'sections' => 'nullable|array',
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
                endDate: $request->input('end_date'),
                sections: $request->input('sections', []),
            );

            $filename = $this->exportService->buildFilename(
                $request->input('start_date'),
                $request->input('end_date'),
            );

            return response($pdfBytes, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"{$filename}\"",
                'Content-Length' => strlen($pdfBytes),
                'Cache-Control' => 'no-store, no-cache',
            ]);

        } catch (\Exception $e) {
            Log::error('PDF export failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate PDF report',
            ], 500);
        }
    }

    // =========================================================================
    // VERIFICATION  [primary only]
    // =========================================================================

    /**
     * Mutation — not cached. Busts all dashboard and driver-stat caches so the
     * next read reflects the newly verified user.
     */
    public function approveVerification(int $userId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'national_id' => 'required|string|max:50',
        ], [
            'national_id.required' => 'The national ID number is required to approve verification.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $nationalId = trim($request->input('national_id'));

        try {
            $approved = app(VerificationApprovalService::class)->approve($userId, $nationalId);
            $verifiedUser = $approved->user;

            // Bust caches that reflect user/driver counts and verification stats
            $this->verificationQueue->bustVerificationCaches();

            return response()->json([
                'status' => 'success',
                'message' => ucfirst($approved->type).' verification approved',
                'user' => [
                    'id' => $verifiedUser->id,
                    'national_id' => $verifiedUser->national_id,
                    'verification_status' => $verifiedUser->verification_status,
                ],
            ]);

        } catch (DuplicateNationalIdException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'This national ID is already linked to another verified account. Verification blocked.',
                'data' => [
                    'conflicting_user_id' => $e->conflictingUserId,
                ],
            ], 422);
        } catch (\Exception $e) {
            // RV-13: message to the log, not the client.
            Log::error('AdminDashboard: verification decision failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to record the verification decision. Please try again.',
            ], 422);
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

}
