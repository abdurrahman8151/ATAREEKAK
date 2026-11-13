<?php

namespace App\Modules\Console\Services;

use App\Domain\ValueObjects\Money;
use App\Models\Profile;
use App\Models\User;
use App\Modules\Identity\Contracts\PhotoRepositoryInterface;
use App\Modules\Identity\Contracts\UserRatingReader;
use App\Modules\Money\Contracts\DriverEarningsReader;
use App\Modules\Ride\Contracts\BookingRevenueAggregates;
use App\Modules\Ride\Contracts\DriverRideReads;

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
        // Stage 2 C2: photos is Identity's table; the rows arrive from its port below (one query).
        $driver = User::with(['profile'])->findOrFail($driverId);
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
        // Stage 2 C1/C2: `user_ratings` is Identity's table, read through its contract.
        return app(UserRatingReader::class)->statsFor($driverId);
    }

    private function dashboardRideStats(int $driverId): array
    {
        // Stage 2 C2: Ride's rows, so Ride's counters - the same three queries, unchanged.
        return app(DriverRideReads::class)->rideStatsForDriver($driverId);
    }

    private function dashboardRecentRides(int $driverId): array
    {
        // Stage 2 C2: Ride owns the rows AND the shape of this block, so the rows arrive as arrays.
        return app(DriverRideReads::class)->dashboardRecentRidesForDriver($driverId);
    }

    private function dashboardFavoriteDestination(int $driverId): ?array
    {
        // Stage 2 C2: the query and its two published keys are Ride's.
        return app(DriverRideReads::class)->favoriteDestinationForDriver($driverId);
    }

    private function dashboardDocuments(User $driver)
    {
        // Stage 2 C2: Identity owns the documents; the URL stays this screen's format.
        $rows = app(PhotoRepositoryInterface::class)->documentsFor([$driver->id])[$driver->id] ?? [];

        return collect($rows)->map(fn ($row) => [
            'type' => $row['type'],
            'file_url' => asset('storage/'.$row['path']),
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

        // Stage 2 C2: the projection is arithmetic over bookings x rides, so Ride computes it.
        $estimatedGross = app(BookingRevenueAggregates::class)->estimatedGrossForDriver($driverId);

        return [(float) $ledgerEarnings, (float) $estimatedGross];
    }
}
