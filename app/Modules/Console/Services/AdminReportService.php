<?php

namespace App\Modules\Console\Services;

use App\Domain\ValueObjects\Money;
use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Modules\Identity\Contracts\ProfileDirectory;
use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Money\Contracts\LedgerAggregates;
use App\Modules\Money\Contracts\WalletDirectory;
use App\Modules\Ride\Contracts\BookingActivityFeed;
use App\Modules\Ride\Contracts\BookingRevenueAggregates;
use App\Modules\Ride\Contracts\RideReportCounts;
use Carbon\Carbon;

/**
 * AdminReportService
 *
 * Provides all data needed by the admin dashboard UI.
 *
 * Design pattern — BFF (Backend for Frontend):
 *   getDashboardData() is the main BFF method. It aggregates every widget
 *   the dashboard page needs into one round-trip. Individual methods
 *   (getStats, getGrowthChart, etc.) are also exposed so specific widgets
 *   can refresh independently without re-fetching the entire payload.
 *
 * SQL strategy:
 *   Most queries are single aggregates. The recent-activities query is the
 *   only join-heavy one; it is intentionally limited to the last N rows.
 */
final class AdminReportService
{
    public function __construct(
        private readonly AdminWalletService $walletService,
        private readonly ProfileDirectory $profiles,
    ) {}

    // =========================================================================
    // BFF — single payload for the dashboard page
    // =========================================================================

    /**
     * Aggregate ALL dashboard widget data in one call.
     *
     * Frontend receives one response; no waterfall of parallel requests needed.
     * Shape mirrors the UI exactly (see screenshots):
     *   stats            → top-row stat cards
     *   growth_chart     → bar chart (Completed Trips vs New Users, last 6 months)
     *   city_distribution→ horizontal progress bars
     *   recent_activities→ activity table rows
     */
    public function getDashboardData(?int $adminUserId = null): array
    {
        $photoUrl = null;
        if ($adminUserId) {
            // Stage 2 C2: `profiles` is Identity's table; ONE statement either way, and the URL format
            // stays this screen's. Third and last copy of this lookup - see `ProfileDirectory`.
            $photo = $this->profiles->photoPathForUser($adminUserId);
            if ($photo) {
                $photoUrl = asset('storage/'.$photo);
            }
        }

        return [
            'admin_photo' => $photoUrl,
            'stats' => $this->getStats(),
            'growth_chart' => $this->getGrowthChart(6),
            'city_distribution' => $this->getCityDistribution(),
            'recent_activities' => $this->getRecentActivities(10),
        ];
    }

    // =========================================================================
    // STATS  (top-row cards)
    // =========================================================================

    /**
     * Five KPI cards shown at the top of the dashboard.
     */
    public function getStats(): array
    {
        // RV-19 fake number #2 (revenue): this read config('system_admin.phone'), a key
        // backed by NO file (V8) — the lookup was always null, so the wallet query found
        // nothing and the dashboard's "total revenue" card showed 0.00 FOREVER, even with
        // money in escrow. Corrected to the canonical key every money path already uses
        // (WalletTransactionService/CashRideFeeService read admin.system_admin.phone;
        // SystemWalletSeeder seeds that phone). Value definition unchanged — still "the
        // Primary Escrow balance"; the fix only makes the intended lookup resolve. What
        // that balance MEANS is Wave-3's gated escrow redesign (§26.14) — untouched here.
        // Stage 2 C2: Money owns `wallets`; the RV-21 system-wallet boundary lives in the reader.
        $primaryWallet = app(WalletDirectory::class)->systemWalletByPhone(config('admin.system_admin.phone'));
        $primaryBalance = $primaryWallet ? (float) $primaryWallet->balance : 0.0;

        return [
            // Stage 2 C3: the four `users` aggregates moved to Identity's directory - the table's owner runs
            // the statements, the report keeps the numbers and their formatting.
            'total_users' => app(UserDirectory::class)->countAll(),
            // Stage 2 C2: the trip counters are Ride's own table, so Ride counts them.
            'active_trips' => app(RideReportCounts::class)->activeTrips(),
            'completed_trips' => app(RideReportCounts::class)->completedTrips(),
            'total_revenue' => [
                'raw' => $primaryBalance,
                'formatted' => Money::from($primaryBalance)->formatted(),
            ],
            // RV-19 fake number #1: pending_complaints was a hardcoded literal 0, so the
            // dashboard's open-workload card could never rise — support backlog invisible
            // to admins regardless of queue size. Convention mirrors the sibling KPI
            // directly below it (verification_requests counts exactly 'pending') and
            // StaffAdminController's per-status counts: "pending" = ComplaintStatus::PENDING
            // only; in_review/escalated are tracked in their own surfaces.
            'pending_complaints' => Complaint::where('status', ComplaintStatus::PENDING->value)->count(),
            'verification_requests' => app(UserDirectory::class)->countPendingVerifications(),
        ];
    }

    // =========================================================================
    // GROWTH CHART  (bar chart — Completed Trips vs New Users)
    // =========================================================================

    /**
     * Monthly breakdown for the last $months calendar months.
     *
     * Returns:
     *   period: 'last_6_months'
     *   data:   [{ month: 'Jan', label: 'Jan 2025', completed_trips: 120, new_users: 89 }, ...]
     */
    public function getGrowthChart(int $months = 6): array
    {
        $data = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = Carbon::now()->startOfMonth()->subMonths($i);
            $end = $start->copy()->endOfMonth();

            $completedTrips = app(RideReportCounts::class)->completedTripsBetween($start, $end);

            $newUsers = app(UserDirectory::class)->countCreatedBetween($start, $end);

            $data[] = [
                'month' => $start->format('M'),        // 'Jan'
                'label' => $start->format('M Y'),      // 'Jan 2025'
                'completed_trips' => $completedTrips,
                'new_users' => $newUsers,
            ];
        }

        return [
            'period' => "last_{$months}_months",
            'data' => $data,
        ];
    }

    // =========================================================================
    // CITY DISTRIBUTION  (horizontal progress bars)
    // =========================================================================

    /**
     * Breakdown of users (and therefore trips) by Syrian governorate.
     *
     * Uses the `users.address` field, which is validated against a known
     * list of Syrian cities at registration, so grouping is clean. The grouping itself is Identity's
     * `UserDirectory::addressDistribution()` now; the display names and percentages below are this report's.
     *
     * Returns:
     *   [ { city: 'دمشق', city_en: 'Damascus', count: 450, percentage: 45 }, … ]
     */
    public function getCityDistribution(): array
    {
        // English display names mapped from Arabic stored values
        $nameMap = [
            'دمشق' => 'Damascus',
            'حلب' => 'Aleppo',
            'حمص' => 'Homs',
            'اللاذقية' => 'Latakia',
            'درعا' => 'Daraa',
            'حماة' => 'Hama',
            'ريف دمشق' => 'Rural Damascus',
            'طرطوس' => 'Tartus',
            'السويداء' => 'As-Suwayda',
            'القنيطرة' => 'Quneitra',
            'إدلب' => 'Idlib',
            'الحسكة' => 'Al-Hasakah',
            'الرقة' => 'Ar-Raqqah',
            'دير الزور' => 'Deir ez-Zor',
        ];

        // Stage 2 C3: the grouped `users` query is Identity's; the raw rows come back in the same order and
        // the same shape, and the display names and percentages below stay this report's.
        $rows = collect(app(UserDirectory::class)->addressDistribution(6));

        $total = $rows->sum('count') ?: 1; // avoid div-by-zero

        return $rows->map(function (array $row) use ($nameMap, $total) {
            return [
                'city' => $row['address'],
                'city_en' => $nameMap[$row['address']] ?? $row['address'],
                'count' => $row['count'],
                'percentage' => (int) round(($row['count'] / $total) * 100),
            ];
        })->values()->toArray();
    }

    // =========================================================================
    // RECENT ACTIVITIES  (table rows)
    // =========================================================================

    /**
     * Latest $limit bookings with their ride, driver, and passenger info.
     *
     * Shape per row:
     *   user    → { name, number }
     *   driver  → string (full name)
     *   route   → 'Pickup ← Destination'   (RTL-friendly arrow)
     *   date    → human-readable
     *   status  → 'active' | 'completed' | 'cancelled' | 'pending'
     *   value   → formatted SYP or '---' for cancelled
     */
    public function getRecentActivities(int $limit = 10): array
    {
        $bookings = collect(app(BookingActivityFeed::class)->recentActivities($limit));

        return $bookings->map(function ($booking) {
            $totalValue = $booking['seats'] * ($booking['price_per_seat'] ?? 0);
            $isCancelled = in_array($booking['status'], ['cancelled', 'no_show']);

            return [
                'booking_id' => $booking['booking_id'],
                'user' => [
                    'name' => trim("{$booking['user_first_name']} {$booking['user_last_name']}"),
                    'number' => 'XXX-XXX-'.substr($booking['communication_number'] ?? '', -4),
                ],
                'driver' => $booking['driver_first_name'] ? trim("{$booking['driver_first_name']} {$booking['driver_last_name']}")
                    : '—',
                'route' => $booking['pickup_address']
                    ? "{$booking['pickup_address']} ← {$booking['destination_address']}"
                    : '—',
                'date' => [
                    'raw' => $booking['created_at']->toIso8601String(),
                    'human' => $booking['created_at']->isToday()
                        ? 'Today, '.$booking['created_at']->format('H:i')
                        : ($booking['created_at']->isYesterday()
                            ? 'Yesterday, '.$booking['created_at']->format('H:i')
                            : $booking['created_at']->format('d M, H:i')),
                ],
                'status' => $booking['status'],
                'value' => $isCancelled
                    ? '---'
                    : Money::from($totalValue)->formatted(),
            ];
        })->toArray();
    }

    // =========================================================================
    // FINANCIAL REPORT  (date-filtered, primary admin only)
    // =========================================================================

    public function generateReport(?string $startDate, ?string $endDate): array
    {
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : null;
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : null;

        return [
            'ride_stats' => $this->getRideStatistics($start, $end),
            'financial_stats' => $this->getFinancialStatistics($start, $end),
            'date_range' => [
                'start' => $start?->format('Y-m-d H:i:s'),
                'end' => $end?->format('Y-m-d H:i:s'),
            ],
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    // Stage 2 C2: the SUM over bookings x rides lives with the tables it reads.
    private function getTotalRevenue(): float
    {
        return app(BookingRevenueAggregates::class)->completedBookingsRevenue();
    }

    private function getRideStatistics(?Carbon $start, ?Carbon $end): array
    {
        // Stage 2 C2: five counts over Ride's table, run by Ride; the keys are unchanged.
        return app(RideReportCounts::class)->statistics($start, $end);
    }

    private function getFinancialStatistics(?Carbon $start, ?Carbon $end): array
    {
        $adminConfigs = config('admin');
        $wallets = app(WalletDirectory::class);
        $syCashWallet = $wallets->byPhone($adminConfigs['sycash']['phone']);
        $primaryWallet = $wallets->byPhone($adminConfigs['system_admin']['phone']);

        if (! $syCashWallet || ! $primaryWallet) {
            return ['error' => 'Admin wallets not yet initialised'];
        }

        // Stage 2 C2: the four ledger totals are Money's queries - the type spellings (two escrow-released
        // variants, three refund types, escrow_received, platform_fee) are ledger semantics, not report
        // formatting, so they moved behind Money\Contracts\LedgerAggregates.
        $ledger = app(LedgerAggregates::class);
        $escrowReceived = $ledger->escrowReceived($syCashWallet->id, $start, $end);
        $escrowReleased = $ledger->escrowReleased($syCashWallet->id, $start, $end);
        $refundsPaid = $ledger->refundsPaid($syCashWallet->id, $start, $end);
        $platformFees = $ledger->platformFees($primaryWallet->id, $start, $end);

        return [
            'sycash' => [
                'current_balance' => Money::from($syCashWallet->balance)->formatted(),
                'total_escrow_in' => Money::from($escrowReceived)->formatted(),
                'total_escrow_out' => Money::from(abs($escrowReleased))->formatted(),
                'total_refunds_paid' => Money::from(abs($refundsPaid))->formatted(),
            ],
            'primary_admin' => [
                'current_balance' => Money::from($primaryWallet->balance)->formatted(),
                'total_platform_fees' => Money::from($platformFees)->formatted(),
                // Primary never disburses in the new model
            ],
            'active_rides_locked' => Money::from($this->calculateLockedFunds())->formatted(),
        ];
    }

    private function calculateLockedFunds(): float
    {
        // Stage 2 C2: locked funds are Ride's arithmetic, whereHas included.
        return app(BookingRevenueAggregates::class)->lockedFundsInActiveRides();
    }
}
