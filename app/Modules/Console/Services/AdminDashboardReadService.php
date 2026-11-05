<?php

namespace App\Modules\Console\Services;

use App\Support\Logging\SafeExceptionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The cached admin dashboard reads: the BFF payload, the stat cards and the city breakdown.
 *
 * Split out of AdminDashboardController (behaviour unchanged) so the controller keeps the HTTP
 * job. The handlers that read a request (growth, recent activity) deliberately stay at the HTTP
 * boundary, which is why this class carries no Illuminate\Http\Request. Bodies moved verbatim, so
 * the cache keys and the cache TTLs are exactly as they were.
 */
final class AdminDashboardReadService
{
    public function __construct(
        private readonly AdminReportService $reportService,
    ) {}

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
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Dashboard data failed', ['error' => SafeExceptionLog::message($e)]);

            return response()->json([
                'status' => 'error',
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
            'data' => $data,
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
            'data' => $data,
        ]);
    }
}
