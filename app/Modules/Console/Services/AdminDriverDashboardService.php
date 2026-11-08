<?php

namespace App\Modules\Console\Services;

use App\Domain\ValueObjects\Money;
use App\Models\Booking;
use App\Models\Photo;
use App\Models\Profile;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserRating;
use App\Modules\Money\Contracts\DriverEarningsReader;

/**
 * The per-driver dashboard widget: ratings, ride counts, recent rides, favourite destination,
 * documents and money.
 *
 * Split out of AdminDriverService (behaviour unchanged). Bodies moved verbatim.
 */
final class AdminDriverDashboardService
{
    public function __construct(
        private readonly AdminDriverPresenter $presenter,
    ) {}

    /**
     * GET /api/admin/drivers/{driverId}/dashboard
     *
     * Returns the full data needed by the driver dashboard detail page:
     *   - personal info + profile photo
     *   - vehicle info + car photo
     *   - all uploaded documents
     *   - stats: total rides, earnings after commission, cancel rate
     *   - recent rides: source, destination, price_per_seat, date (no rating/comment)
     *   - favorite destination: most frequent drop-off location
     */
    public function getDriverDashboard(int $driverId): array
    {
        $driver = User::with(['profile', 'photos'])->findOrFail($driverId);
        $profile = $driver->profile;

        // ── Rating ────────────────────────────────────────────────────────────
        $ratingStats = $this->dashboardRatingStats($driverId);

        // ── Stats ─────────────────────────────────────────────────────────────
        $stats = $this->dashboardRideStats($driverId);

        // ── RV-19 item 2 (owner: ledger is authoritative) ──────────────────────
        // `ledger_earnings` reads REAL RECORDED MONEY, not a number re-derived from today's
        // columns. The old derivation (`seats * rides.price_per_seat * rate`, joined to the CURRENT
        // rides rows) is the defect this row is named for: editing a ride's price after the booking
        // silently rewrites what the driver appears to have earned, and a cancelled-then-rebooked
        // ride changes history without any money moving.
        //
        // SOURCE, and why not `ledger_entries`: the 95/5 settlement legs written by
        // `WalletTransactionService::releaseRideEarnings` carry only (wallet_id, amount, description)
        // - NO type discriminator - so a per-driver sum over `ledger_entries` would also count
        // top-ups and deposits. `ledger_entries` structurally cannot answer "earnings".
        // `wallet_transactions` CAN: it is type-discriminated and carries `user_id` = driver, so the
        // sum is exactly the credits actually paid out for rides.
        //
        // Both spellings are summed because the vocabulary has drifted (RV-39): the application
        // writes RIDE_EARNINGS ('ride_earnings'), while seeded/legacy rows use RIDE_EARNING
        // ('ride_earning'). Summing one would silently drop the other. 'completed' only - a
        // pending row is not money that moved.
        [$ledgerEarnings, $estimatedGross] = $this->dashboardMoney($driverId);

        // ── Recent rides ──────────────────────────────────────────────────────
        $recentRides = $this->dashboardRecentRides($driverId);

        // ── Favorite destination ──────────────────────────────────────────────
        $favoriteDestination = $this->dashboardFavoriteDestination($driverId);

        // ── Documents ─────────────────────────────────────────────────────────
        $documents = $this->dashboardDocuments($driver);

        return $this->presenter->formatDriverDashboard(
            $driver,
            $profile,
            $ratingStats,
            $stats,
            [$ledgerEarnings, $estimatedGross],
            $recentRides,
            $favoriteDestination,
            $documents,
        );
    }

    private function dashboardRatingStats(int $driverId)
    {
        return UserRating::where('rated_user_id', $driverId)
            ->selectRaw('COUNT(*) as total, ROUND(AVG(rating), 2) as average')
            ->first();
    }

    private function dashboardRideStats(int $driverId): array
    {
        $totalRides = Ride::where('driver_id', $driverId)->count();
        $completedRides = Ride::where('driver_id', $driverId)->where('status', 'finished')->count();
        $cancelledRides = Ride::where('driver_id', $driverId)->where('status', 'cancelled')->count();

        return [
            'total_rides' => $totalRides,
            'completed_rides' => $completedRides,
            'cancelled_rides' => $cancelledRides,
            'cancel_rate' => $totalRides > 0
                ? round(($cancelledRides / $totalRides) * 100, 1)
                : 0.0,
        ];
    }

    private function dashboardRecentRides(int $driverId)
    {
        return Ride::where('driver_id', $driverId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'status', 'pickup_address', 'destination_address', 'price_per_seat', 'departure_time']);
    }

    private function dashboardFavoriteDestination(int $driverId): ?Ride
    {
        return Ride::where('driver_id', $driverId)
            ->where('status', 'finished')
            ->select('destination_address')
            ->selectRaw('COUNT(*) as visit_count')
            ->groupBy('destination_address')
            ->orderByDesc('visit_count')
            ->first();
    }

    private function dashboardDocuments(User $driver)
    {
        return $driver->photos->map(fn ($photo) => [
            'type' => $photo->type,
            'file_url' => asset('storage/'.$photo->path),
        ]);
    }

    /**
     * The two money totals the dashboard reports, returned raw. Formatting happens at the return site, so
     * rounding is decided in one place (R2 sec 117).
     *
     * RV-19 item 2 (owner: ledger is authoritative). `ledger_earnings` is the completed ride-earning ledger
     * sum; both spellings are summed (RV-39), and a pending row is not money that moved.
     *
     * `estimated_gross` is a projection off current ride prices, not a settled amount. The driver share
     * rate is bound from config, so the platform's cut is defined in exactly one place.
     *
     * @return array{0: float, 1: float} [ledger earnings, estimated gross]
     */
    private function dashboardMoney(int $driverId): array
    {
        // Stage 2 C2: the two RV-39 spellings and the completed-only rule live with Money's table.
        $ledgerEarnings = app(DriverEarningsReader::class)->completedEarningsForDriver($driverId);

        $estimatedGross = Booking::join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->where('rides.driver_id', $driverId)
            ->where('bookings.status', 'completed')
            ->selectRaw('SUM(bookings.seats * rides.price_per_seat * ?) as total', [(float) config('fees.driver_share_rate', 0.95)])
            ->value('total') ?? 0.0;

        return [(float) $ledgerEarnings, (float) $estimatedGross];
    }
}
