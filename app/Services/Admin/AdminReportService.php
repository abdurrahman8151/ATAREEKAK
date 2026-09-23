<?php

namespace App\Services\Admin;

use App\Domain\ValueObjects\Money;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
            $profile = \App\Models\Profile::where('user_id', $adminUserId)->first();
            if ($profile && $profile->profile_photo) {
                $photoUrl = asset('storage/' . $profile->profile_photo);
            }
        }

        return [
            'admin_photo'       => $photoUrl,
            'stats'             => $this->getStats(),
            'growth_chart'      => $this->getGrowthChart(6),
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
        $primaryWallet = Wallet::where('phone_number', config('system_admin.phone'))->first();
        $primaryBalance = $primaryWallet ? (float) $primaryWallet->balance : 0.0;

        return [
            'total_users'           => User::count(),
            'active_trips'          => Ride::where('status', 'active')->count(),
            'completed_trips'       => Ride::where('status', 'finished')->count(),
            'total_revenue'         => [
                'raw'       => $primaryBalance,
                'formatted' => Money::from($primaryBalance)->formatted(),
            ],
            'pending_complaints'    => 0,
            'verification_requests' => User::where('verification_status', 'pending')->count(),
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
            $end   = $start->copy()->endOfMonth();

            $completedTrips = Ride::where('status', 'finished')
                ->whereBetween('finished_at', [$start, $end])
                ->count();

            $newUsers = User::whereBetween('created_at', [$start, $end])->count();

            $data[] = [
                'month'           => $start->format('M'),        // 'Jan'
                'label'           => $start->format('M Y'),      // 'Jan 2025'
                'completed_trips' => $completedTrips,
                'new_users'       => $newUsers,
            ];
        }

        return [
            'period' => "last_{$months}_months",
            'data'   => $data,
        ];
    }

    // =========================================================================
    // CITY DISTRIBUTION  (horizontal progress bars)
    // =========================================================================

    /**
     * Breakdown of users (and therefore trips) by Syrian governorate.
     *
     * Uses the User.address field, which is validated against a known
     * list of Syrian cities at registration, so grouping is clean.
     *
     * Returns:
     *   [ { city: 'دمشق', city_en: 'Damascus', count: 450, percentage: 45 }, … ]
     */
    public function getCityDistribution(): array
    {
        // English display names mapped from Arabic stored values
        $nameMap = [
            'دمشق'       => 'Damascus',
            'حلب'        => 'Aleppo',
            'حمص'        => 'Homs',
            'اللاذقية'   => 'Latakia',
            'درعا'       => "Daraa",
            'حماة'       => 'Hama',
            'ريف دمشق'   => 'Rural Damascus',
            'طرطوس'      => 'Tartus',
            'السويداء'   => 'As-Suwayda',
            'القنيطرة'   => 'Quneitra',
            'ادلب'       => 'Idlib',
            'الحسكة'     => 'Al-Hasakah',
            'الرقة'      => 'Ar-Raqqah',
            'دير الزور'  => 'Deir ez-Zor',
        ];

        $rows  = User::select('address', DB::raw('COUNT(*) as count'))
            ->whereNotNull('address')
            ->groupBy('address')
            ->orderByDesc('count')
            ->limit(6)
            ->get();

        $total = $rows->sum('count') ?: 1; // avoid div-by-zero

        return $rows->map(function ($row) use ($nameMap, $total) {
            return [
                'city'       => $row->address,
                'city_en'    => $nameMap[$row->address] ?? $row->address,
                'count'      => $row->count,
                'percentage' => (int) round(($row->count / $total) * 100),
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
        $bookings = Booking::with([
            'ride:id,pickup_address,destination_address,price_per_seat,status,departure_time,driver_id',
            'ride.driver:id,first_name,last_name',
            'user:id,first_name,last_name',
        ])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $bookings->map(function (Booking $booking) {
            $ride        = $booking->ride;
            $driver      = $ride?->driver;
            $totalValue  = $booking->seats * ($ride?->price_per_seat ?? 0);
            $isCancelled = in_array($booking->status, ['cancelled', 'no_show']);

            return [
                'booking_id' => $booking->id,
                'user'       => [
                    'id'     => $booking->user?->id,
                    'name'   => trim("{$booking->user?->first_name} {$booking->user?->last_name}"),
                    'number' => 'XXX-XXX-' . substr($booking->communication_number ?? '', -4),
                ],
                'driver'     => $driver
                    ? trim("{$driver->first_name} {$driver->last_name}")
                    : '—',
                'route'      => $ride
                    ? "{$ride->pickup_address} ← {$ride->destination_address}"
                    : '—',
                'date'       => [
                    'raw'   => $booking->created_at->toIso8601String(),
                    'human' => $booking->created_at->isToday()
                        ? 'Today, ' . $booking->created_at->format('H:i')
                        : ($booking->created_at->isYesterday()
                            ? 'Yesterday, ' . $booking->created_at->format('H:i')
                            : $booking->created_at->format('d M, H:i')),
                ],
                'status'     => $booking->status,
                'value'      => $isCancelled
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
        $end   = $endDate   ? Carbon::parse($endDate)->endOfDay()     : null;

        return [
            'ride_stats'      => $this->getRideStatistics($start, $end),
            'financial_stats' => $this->getFinancialStatistics($start, $end),
            'date_range'      => [
                'start' => $start?->format('Y-m-d H:i:s'),
                'end'   => $end?->format('Y-m-d H:i:s'),
            ],
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

// AFTER (qualified with table name)
    private function getTotalRevenue(): float
    {
        return (float) Booking::where('bookings.status', 'completed')  // ← add 'bookings.'
        ->join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->selectRaw('SUM(bookings.seats * rides.price_per_seat) as total')
            ->value('total');
    }

    private function getRideStatistics(?Carbon $start, ?Carbon $end): array
    {
        $q = Ride::query();

        if ($start && $end) {
            $q->whereBetween('created_at', [$start, $end]);
        }

        return [
            'total'                  => $q->count(),
            'active'                 => (clone $q)->where('status', 'active')->count(),
            'completed'              => (clone $q)->where('status', 'finished')->count(),
            'cancelled'              => (clone $q)->where('status', 'cancelled')->count(),
            'awaiting_confirmation'  => (clone $q)->where('status', 'awaiting_confirmation')->count(),
        ];
    }

    private function getFinancialStatistics(?Carbon $start, ?Carbon $end): array
    {
        $adminConfigs  = config('admin');
        $syCashWallet  = Wallet::where('phone_number', $adminConfigs['sycash']['phone'])->first();
        $primaryWallet = Wallet::where('phone_number', $adminConfigs['system_admin']['phone'])->first();

        if (!$syCashWallet || !$primaryWallet) {
            return ['error' => 'Admin wallets not yet initialised'];
        }

        // ── SyCash: escrow received from passengers ──────────────────────────
        $escrowReceivedQ = WalletTransaction::where('wallet_id', $syCashWallet->id)
            ->where('type', 'escrow_received');          // ← was 'ride_creation_fee'

        // ── SyCash: total released on completion ─────────────────────────────
        $escrowReleasedQ = WalletTransaction::where('wallet_id', $syCashWallet->id)
            ->where('type', 'escrow_released');

        // ── SyCash: total refunds paid out ───────────────────────────────────
        $refundsQ = WalletTransaction::where('wallet_id', $syCashWallet->id)
            ->whereIn('type', [
                'driver_cancellation_refunds',
                'cancellation_processing',
                'driver_no_show_refund',
            ]);

        // ── Primary: platform fees earned (5% of completions + no-shows) ─────
        $platformFeesQ = WalletTransaction::where('wallet_id', $primaryWallet->id)
            ->where('type', 'platform_fee');             // ← was 'ride_booking_received'

        if ($start && $end) {
            $escrowReceivedQ->whereBetween('created_at', [$start, $end]);
            $escrowReleasedQ->whereBetween('created_at', [$start, $end]);
            $refundsQ->whereBetween('created_at',        [$start, $end]);
            $platformFeesQ->whereBetween('created_at',   [$start, $end]);
        }

        return [
            'sycash' => [
                'current_balance'     => Money::from($syCashWallet->balance)->formatted(),
                'total_escrow_in'     => Money::from($escrowReceivedQ->sum('amount'))->formatted(),
                'total_escrow_out'    => Money::from(abs((float) $escrowReleasedQ->sum('amount')))->formatted(),
                'total_refunds_paid'  => Money::from(abs((float) $refundsQ->sum('amount')))->formatted(),
            ],
            'primary_admin' => [
                'current_balance'     => Money::from($primaryWallet->balance)->formatted(),
                'total_platform_fees' => Money::from($platformFeesQ->sum('amount'))->formatted(),
                // Primary never disburses in the new model
            ],
            'active_rides_locked' => Money::from($this->calculateLockedFunds())->formatted(),
        ];
    }

    private function calculateLockedFunds(): float
    {
        return (float) Booking::whereHas('ride', fn($q) =>
        $q->whereIn('status', ['active', 'full', 'awaiting_confirmation'])
        )
            ->join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->where('bookings.status', 'confirmed')
            ->selectRaw('SUM(bookings.seats * rides.price_per_seat) as total')
            ->value('total');
    }
}
