<?php

namespace App\Services\Admin;

use App\Domain\ValueObjects\Money;
use App\Enums\LedgerType;
use App\Models\Booking;
use App\Models\Photo;
use App\Models\Profile;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserRating;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * AdminDriverService
 *
 * Provides all data for the Admin Driver Management dashboard.
 *
 * ── Dashboard components ────────────────────────────────────────────────────
 *   - Stats cards        (total, active, pending, suspended, avg rating)
 *   - Driver table       (filterable: All | Verified | Pending | Suspended)
 *   - Recent activity    (verification events + vehicle/profile updates)
 *   - Verification efficiency widget (day / week / month with comparison)
 *   - Admin profile photo
 *   - Driver dashboard   (rich detail page: earnings, cancel rate, docs, etc.)
 *
 * ── Definitions ────────────────────────────────────────────────────────────
 *   "Total drivers"     = verified drivers  +  users with pending/rejected
 *                         driver verification (have license/mechanic_card docs)
 *   "Active drivers"    = is_verified_driver = 1
 *   "Pending"           = verification_status = 'pending'
 *                         AND has driver documents (license or mechanic_card)
 *   "Suspended"         = 0  (functionality not implemented yet)
 *   "Avg rating"        = AVG of all ratings received by verified drivers
 *
 * ── Efficiency definition ───────────────────────────────────────────────────
 *   "Incoming"  = users whose first driver doc (license/mechanic_card photo)
 *                 was created within the chosen period
 *   "Processed" = users whose verification_status moved to 'approved' or
 *                 'rejected' within the chosen period (updated_at in range)
 *   Efficiency% = (processed / incoming) × 100
 *                 If no requests came in → 100%
 */
final class AdminDriverService
{
    // =========================================================================
    // BFF – full dashboard in one call
    // =========================================================================

    /**
     * Returns every widget the driver dashboard page needs in a single response.
     *
     * The driver table is intentionally excluded from the BFF payload because
     * it is paginated – the frontend fetches it via GET /api/admin/drivers.
     *
     * @param  int|null  $adminUserId  Used to fetch the admin's own profile photo.
     */
    public function getDashboardData(?int $adminUserId = null): array
    {
        return [
            'admin_photo' => $this->getAdminPhoto($adminUserId),
            'stats' => $this->getStats(),
            'recent_activity' => $this->getRecentActivity(10),
            'verification_efficiency' => $this->getVerificationEfficiency('week'),
        ];
    }

    // =========================================================================
    // STATS CARDS
    // =========================================================================

    /**
     * Returns the four KPI cards shown at the top of the driver dashboard.
     */
    public function getStats(): array
    {
        $totalDrivers = User::where('is_verified_driver', true)
            ->orWhere(function ($q) {
                $q->whereIn('verification_status', ['pending', 'rejected'])
                    ->whereHas('photos', fn ($p) => $p->whereIn('type', ['license', 'mechanic_card']));
            })
            ->count();

        $activeDrivers = User::where('is_verified_driver', true)->count();

        $pendingVerifications = User::where('verification_status', 'pending')
            ->whereHas('photos', fn ($p) => $p->whereIn('type', ['license', 'mechanic_card']))
            ->count();

        // RV-19 item 5: this was `$suspendedDrivers = 0; // not implemented yet`, so the dashboard
        // card reported zero suspended drivers no matter how many there were - while the driver
        // TABLE on the same screen could list them, because its `suspended` filter is
        // `where('status', 0)` and `resolveDriverStatus()` maps `status == 0` to 'suspended'.
        // The two halves of one screen contradicted each other. Counted the same way the filter
        // counts, so the card and the table can no longer disagree.
        //
        // RV-42a: agreeing with each other was not enough - BOTH halves were wrong about WHICH
        // accounts were suspended. `status = 0` is LOGGED_OUT (every self-registration starts
        // there, and `unban()` writes it back), so "suspended" counted every signed-out and every
        // unbanned driver. "Suspended" is what the user is told when login is refused
        // (`LoginController:83`, `ACCOUNT_BANNED`), which is `status = -1`. Both halves now go
        // through `User::bannedNow()`.
        $suspendedDrivers = User::bannedNow()->count();

        $avgRating = UserRating::whereHas(
            'ratedUser',
            fn ($q) => $q->where('is_verified_driver', true)
        )->avg('rating');

        return [
            'total_drivers' => $totalDrivers,
            'active_drivers' => $activeDrivers,
            'pending_verifications' => $pendingVerifications,
            'suspended_drivers' => $suspendedDrivers,
            'average_rating' => $avgRating ? round((float) $avgRating, 2) : 0.0,
        ];
    }

    // =========================================================================
    // DRIVER TABLE  (paginated + filtered + searchable)
    // =========================================================================

    /**
     * Returns a paginated, filterable list of drivers for the admin table.
     *
     * @param  string  $filter  all | verified | pending | suspended
     * @param  int  $perPage  1–50
     * @param  string|null  $search  Matches first name, last name, or email
     */
    public function getDrivers(
        string $filter = 'all',
        int $perPage = 10,
        int $page = 1,
        ?string $search = null
    ): LengthAwarePaginator {
        $query = User::with([
            'profile:user_id,profile_photo,type_of_car,color_of_car',
            'photos',
        ])
            ->where(function ($q) {
                $q->where('is_verified_driver', true)
                    ->orWhere(function ($q2) {
                        $q2->whereIn('verification_status', ['pending', 'rejected'])
                            ->whereHas('photos', fn ($p) => $p->whereIn('type', ['license', 'mechanic_card']));
                    });
            });

        match ($filter) {
            'verified' => $query->where('is_verified_driver', true),
            'pending' => $query->where('verification_status', 'pending')
                ->whereHas('photos', fn ($p) => $p->whereIn('type', ['license', 'mechanic_card'])),
            'suspended' => $query->bannedNow(),
            default => null,
        };

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('created_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $paginator->getCollection()->transform(function (User $driver) {
            $driver->avg_rating = UserRating::where('rated_user_id', $driver->id)->avg('rating');

            return $driver;
        });

        return $paginator;
    }

    /**
     * Shape a single User (driver) model into the table-row array
     * that the controller returns to the frontend.
     */
    public function formatDriver(User $driver): array
    {
        // RV-37 / un9: this is called once per driver in list/format paths; the profile is read
        // unconditionally below, so load it once explicitly instead of per driver (N+1).
        $driver->loadMissing('profile');
        $profile = $driver->profile;

        $vehicleLabel = null;
        if ($profile?->type_of_car) {
            $vehicleLabel = $profile->type_of_car;
            if ($profile->color_of_car) {
                $vehicleLabel .= ' | '.$profile->color_of_car;
            }
        }

        return [
            'id' => $driver->id,
            'driver_ref' => '#DR-'.$driver->id,
            'full_name' => trim("{$driver->first_name} {$driver->last_name}"),
            'profile_photo' => $profile?->profile_photo
                ? asset('storage/'.$profile->profile_photo)
                : null,
            'phone' => $this->resolveDriverPhone($driver->id),
            'vehicle' => $vehicleLabel,
            'status' => $this->resolveDriverStatus($driver),
            'avg_rating' => isset($driver->avg_rating) && $driver->avg_rating !== null
                ? round((float) $driver->avg_rating, 1)
                : null,
            'is_verified_driver' => (bool) $driver->is_verified_driver,
            'verification_status' => $driver->verification_status,
            'joined_at' => $driver->created_at->toIso8601String(),
        ];
    }

    // =========================================================================
    // DRIVER PROFILE  (existing – detail / modal view)
    // =========================================================================

    /**
     * GET /api/admin/drivers/{driverId}/profile
     *
     * Returns the full profile of a single driver including:
     *   - personal info, vehicle details, documents, rating, recent rides
     */
    public function getDriverProfile(int $driverId): array
    {
        $driver = $this->findDriverWithProfileData($driverId);

        $ratingStats = UserRating::where('rated_user_id', $driverId)
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->first();

        $completedRides = Ride::where('driver_id', $driverId)
            ->where('status', 'finished')
            ->count();

        return [
            ...$this->driverProfileIdentity($driver),
            'vehicle' => $this->driverProfileVehicle($driver->profile),
            'documents' => $driver->photos->mapWithKeys(
                fn ($p) => [$p->type => asset('storage/'.$p->path)]
            ),
            'rating' => $this->driverProfileRating($ratingStats),
            'stats' => [
                'completed_rides' => $completedRides,
                // RV-19 item 5: the uncapped count, not the 5-row recent collection.
                'total_rides' => (int) $driver->rides_count,
            ],
            'recent_rides' => $this->driverProfileRecentRides($driver),
        ];
    }

    /**
     * The driver row the profile view is built from: profile, photos, the 5 most recent rides
     * with their booking counts, and the uncapped ride count.
     */
    private function findDriverWithProfileData(int $driverId): User
    {
        return User::with([
            'profile',
            'photos',
            // Only the RECENT rides are limited, for the `recent_rides` list below. `total_rides`
            // used to be `$driver->rides->count()` off THIS relation, so it counted the capped
            // collection: any driver with more than 5 rides reported exactly 5, and one with fewer
            // reported a number that looked like a total but was a page size.
            'rides' => fn ($q) => $q
                ->withCount('bookings')
                ->orderByDesc('created_at')
                ->limit(5),
            // RV-19 item 5: an uncapped scalar count, so the total is a total. Kept separate from
            // the limited relation on purpose - sharing one relation cannot serve both a page of
            // recent rows and a lifetime count.
        ])
            ->withCount('rides')
            ->findOrFail($driverId);
    }

    /** Identity, contact and verification keys of the driver profile payload, in their order. */
    private function driverProfileIdentity(User $driver): array
    {
        $profile = $driver->profile;

        return [
            'id' => $driver->id,
            'driver_ref' => '#DR-'.$driver->id,
            'full_name' => trim("{$driver->first_name} {$driver->last_name}"),
            'email' => $driver->email,
            'profile_photo' => $profile?->profile_photo
                ? asset('storage/'.$profile->profile_photo)
                : null,
            'phone' => $this->resolveDriverPhone($driver->id),
            'address' => $driver->address,
            'gender' => $driver->gender,
            'verification_status' => $driver->verification_status,
            'is_verified_driver' => (bool) $driver->is_verified_driver,
            'status' => $this->resolveDriverStatus($driver),
            'joined_at' => $driver->created_at->toIso8601String(),
        ];
    }

    private function driverProfileVehicle(?Profile $profile): array
    {
        return [
            'type' => $profile?->type_of_car,
            'color' => $profile?->color_of_car,
            'seats' => $profile?->number_of_seats,
            'photo' => $profile?->car_pic
                ? asset('storage/'.$profile->car_pic)
                : null,
        ];
    }

    private function driverProfileRating($ratingStats): array
    {
        return [
            'average' => $ratingStats->average
                ? round((float) $ratingStats->average, 2)
                : null,
            'total_ratings' => (int) $ratingStats->total,
        ];
    }

    private function driverProfileRecentRides(User $driver): array
    {
        return $driver->rides->map(fn ($r) => [
            'id' => $r->id,
            'pickup_address' => $r->pickup_address,
            'destination_address' => $r->destination_address,
            'departure_time' => $r->departure_time->toIso8601String(),
            'status' => $r->status,
            'bookings_count' => $r->bookings_count,
        ])->values()->all();
    }

    // =========================================================================
    // DRIVER DASHBOARD  (new – rich detail page for dashboard page-3)
    // =========================================================================

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

        return $this->formatDriverDashboard(
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

    /**
     * Assemble the driver dashboard payload. Money is rounded once, here (R2 sec 117).
     */
    private function formatDriverDashboard(
        User $driver,
        ?Profile $profile,
        $ratingStats,
        array $stats,
        array $money,
        $recentRides,
        $favoriteDestination,
        $documents,
    ): array {
        [$ledgerEarnings, $estimatedGross] = $money;

        return [
            ...$this->dashboardIdentityBlock($driver, $profile, $ratingStats),

            'stats' => [
                'total_rides' => $stats['total_rides'],
                'completed_rides' => $stats['completed_rides'],
                'cancelled_rides' => $stats['cancelled_rides'],
                'cancel_rate' => $stats['cancel_rate'],                        // e.g. 2.4 (%)
                // AUTHORITATIVE: real settled money paid to this driver for rides.
                // `R2 sec 117`: money figures are rounded once, in `Money`.
                'ledger_earnings' => Money::from((float) $ledgerEarnings)->amount(),
                // A projection off CURRENT ride prices, not a payout.
                'estimated_gross' => Money::from((float) $estimatedGross)->amount(),
                // ALIAS of `ledger_earnings`, kept so the admin front-end does not break (D5 = C).
                'total_earnings' => Money::from((float) $ledgerEarnings)->amount(),
            ],

            'vehicle' => [
                'type' => $profile?->type_of_car,
                'color' => $profile?->color_of_car,
                'seats' => $profile?->number_of_seats,
                'photo_url' => $profile?->car_pic
                    ? asset('storage/'.$profile->car_pic)
                    : null,
            ],

            'documents' => $documents,

            'recent_rides' => $recentRides->map(fn ($ride) => [
                'id' => $ride->id,
                'status' => $ride->status,
                'source' => $ride->pickup_address,
                'destination' => $ride->destination_address,
                'price_per_seat' => (float) $ride->price_per_seat,
                'date' => $ride->departure_time->toIso8601String(),
            ])->values(),

            'favorite_destination' => $favoriteDestination ? [
                'name' => $favoriteDestination->destination_address,
                'visit_count' => $favoriteDestination->visit_count,
            ] : null,
        ];
    }

    /**
     * Identity and rating keys of the dashboard payload, in their original order.
     */
    private function dashboardIdentityBlock(User $driver, ?Profile $profile, $ratingStats): array
    {
        return [
            'id' => $driver->id,
            'driver_ref' => '#DR-'.$driver->id,
            'full_name' => trim("{$driver->first_name} {$driver->last_name}"),
            'email' => $driver->email,
            'phone' => $this->resolveDriverPhone($driver->id),
            'gender' => $driver->gender,
            'address' => $driver->address,
            'joined_at' => $driver->created_at->toIso8601String(),
            'status' => $this->resolveDriverStatus($driver),
            'is_verified' => (bool) $driver->is_verified_driver,
            'verification_status' => $driver->verification_status,

            'profile_photo' => $profile?->profile_photo
                ? asset('storage/'.$profile->profile_photo)
                : null,

            'rating' => [
                'average' => $ratingStats->average ?? 0,
                'total_ratings' => (int) ($ratingStats->total ?? 0),
            ],
        ];
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
        $ledgerEarnings = WalletTransaction::where('user_id', $driverId)
            ->whereIn('type', [LedgerType::RIDE_EARNINGS->value, LedgerType::RIDE_EARNING->value])
            ->where('status', 'completed')
            ->sum('amount');

        $estimatedGross = Booking::join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->where('rides.driver_id', $driverId)
            ->where('bookings.status', 'completed')
            ->selectRaw('SUM(bookings.seats * rides.price_per_seat * ?) as total', [(float) config('fees.driver_share_rate', 0.95)])
            ->value('total') ?? 0.0;

        return [(float) $ledgerEarnings, (float) $estimatedGross];
    }

    // =========================================================================
    // RECENT ACTIVITY FEED
    // =========================================================================

    /**
     * Returns the latest driver-related activity events merged and sorted newest-first.
     *
     * Event sources:
     *   1. Verification status changes  (pending / approved / rejected)
     *   2. Vehicle / profile updates made by verified or pending drivers
     */
    public function getRecentActivity(int $limit = 10): array
    {
        $events = [
            ...$this->verificationActivityEvents($limit),
            ...$this->profileUpdateActivityEvents($limit),
        ];

        usort($events, fn ($a, $b) => strcmp($b['occurred_at'], $a['occurred_at']));

        return array_slice(array_values($events), 0, $limit);
    }

    // ── 1. Verification status changes ────────────────────────────────────
    private function verificationActivityEvents(int $limit): array
    {
        $recentVerifications = User::with('profile:user_id,profile_photo')
            ->whereHas('photos', fn ($p) => $p->whereIn('type', ['license', 'mechanic_card']))
            ->whereIn('verification_status', ['pending', 'approved', 'rejected'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $events = [];

        foreach ($recentVerifications as $user) {
            $name = trim("{$user->first_name} {$user->last_name}");
            $status = $user->verification_status;

            $events[] = [
                'type' => 'verification_'.$status,
                'icon' => match ($status) {
                    'approved' => 'check',
                    'rejected' => 'x',
                    default => 'clock',
                },
                'color' => match ($status) {
                    'approved' => 'green',
                    'rejected' => 'red',
                    default => 'blue',
                },
                'message' => match ($status) {
                    'pending' => "Verification request submitted by \"{$name}\"",
                    'approved' => "Verification request for \"{$name}\" accepted",
                    'rejected' => "Verification request for \"{$name}\" rejected",
                    default => "Verification update for \"{$name}\"",
                },
                'actor' => $status === 'pending' ? $name : 'Admin',
                'user_id' => $user->id,
                'occurred_at' => $user->updated_at->toIso8601String(),
                'human_time' => $this->humanTime($user->updated_at),
            ];
        }

        return $events;
    }

    /** ── 2. Vehicle / profile updates by drivers ─────────────────────────── */
    private function profileUpdateActivityEvents(int $limit): array
    {
        $recentProfileUpdates = Profile::with('user:id,first_name,last_name')
            ->whereHas('user', fn ($q) => $q->where('is_verified_driver', true)
                ->orWhereHas('photos', fn ($p) => $p->whereIn('type', ['license', 'mechanic_card']))
            )
            ->whereNotNull('type_of_car')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $events = [];

        foreach ($recentProfileUpdates as $profile) {
            if (! $profile->user) {
                continue;
            }
            $name = trim("{$profile->user->first_name} {$profile->user->last_name}");

            $events[] = [
                'type' => 'vehicle_update',
                'icon' => 'edit',
                'color' => 'purple',
                'message' => "Vehicle details updated for \"{$name}\"",
                'actor' => $name,
                'user_id' => $profile->user_id,
                'occurred_at' => $profile->updated_at->toIso8601String(),
                'human_time' => $this->humanTime($profile->updated_at),
            ];
        }

        return $events;
    }

    // =========================================================================
    // VERIFICATION EFFICIENCY WIDGET
    // =========================================================================

    /**
     * Calculates the verification processing efficiency for the chosen period
     * and compares it against the previous equivalent period.
     *
     * @param  string  $period  'day' | 'week' | 'month'
     */
    public function getVerificationEfficiency(string $period = 'week'): array
    {
        [
            $currentStart,
            $currentEnd,
            $previousStart,
            $previousEnd,
            $label,
            $previousLabel,
        ] = $this->resolvePeriodBounds($period);

        $current = $this->efficiencyWindow($currentStart, $currentEnd);
        $previous = $this->efficiencyWindow($previousStart, $previousEnd);

        return [
            // RV-19 item 5: `resolvePeriodBounds()` has a `default =>` arm, so an unknown
            // `?period=` fell through to WEEK. The response then echoed the REQUESTED value in
            // `period` while `period_label` carried the resolved one - so `?period=quarter`
            // answered `period: "quarter", period_label: "Week"` and the caller had no way to tell
            // the numbers were for a different window than the one it asked for. The resolved
            // label is now echoed, so the response is self-consistent. Same keys, same shape - only
            // the value stops lying. Rejecting the unknown value outright instead would be an API
            // change, so it is left to the caller's validation layer.
            'period' => $label,
            'period_label' => ucfirst($label),
            'current' => [
                'start' => $currentStart->toDateTimeString(),
                'end' => $currentEnd->toDateTimeString(),
                'total_incoming' => $current['total_incoming'],
                'processed' => $current['processed'],
                'pending' => max(0, $current['total_incoming'] - $current['processed']),
                'efficiency_pct' => $current['efficiency_pct'],
            ],
            'previous' => [
                'label' => 'Last '.$previousLabel,
                'start' => $previousStart->toDateTimeString(),
                'end' => $previousEnd->toDateTimeString(),
                'total_incoming' => $previous['total_incoming'],
                'processed' => $previous['processed'],
                'efficiency_pct' => $previous['efficiency_pct'],
            ],
            'comparison' => $this->efficiencyComparison($current['efficiency_pct'], $previous['efficiency_pct'], $label),
        ];
    }

    // =========================================================================
    // ADMIN PHOTO
    // =========================================================================

    public function getAdminPhoto(?int $adminUserId): ?string
    {
        if (! $adminUserId) {
            return null;
        }

        $profile = Profile::where('user_id', $adminUserId)->first();

        return ($profile && $profile->profile_photo)
            ? asset('storage/'.$profile->profile_photo)
            : null;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function countIncomingVerifications(Carbon $start, Carbon $end): int
    {
        return User::whereHas('photos', function ($q) use ($start, $end) {
            $q->whereIn('type', ['license', 'mechanic_card'])
                ->whereBetween('created_at', [$start, $end]);
        })->count();
    }

    private function countProcessedVerifications(Carbon $start, Carbon $end): int
    {
        return User::whereIn('verification_status', ['approved', 'rejected'])
            ->whereHas('photos', fn ($p) => $p->whereIn('type', ['license', 'mechanic_card']))
            ->whereBetween('updated_at', [$start, $end])
            ->count();
    }

    /**
     * Incoming / processed counts and the efficiency percentage for one window.
     */
    private function efficiencyWindow(Carbon $start, Carbon $end): array
    {
        $totalIncoming = $this->countIncomingVerifications($start, $end);
        $processed = $this->countProcessedVerifications($start, $end);

        return [
            'total_incoming' => $totalIncoming,
            'processed' => $processed,
            'efficiency_pct' => $totalIncoming > 0
                ? round(($processed / $totalIncoming) * 100)
                : 100,
        ];
    }

    /**
     * Delta / trend block comparing the current window against the previous one.
     *
     * The two percentages are deliberately untyped: they are `round()` (float) when requests came
     * in and the integer `100` when none did, and `$delta` kept that int/float shape in the
     * response, so the arithmetic here must not force either type.
     */
    private function efficiencyComparison($efficiencyPct, $prevEfficiencyPct, string $label): array
    {
        $delta = $efficiencyPct - $prevEfficiencyPct;

        return [
            'delta' => $delta,
            'delta_display' => ($delta >= 0 ? '+' : '').$delta.'%',
            'trend' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
            'text' => match (true) {
                $delta > 0 => abs($delta).'% higher than last '.$label,
                $delta < 0 => abs($delta).'% lower than last '.$label,
                default => 'Same as last '.$label,
            },
        ];
    }

    private function resolvePeriodBounds(string $period): array
    {
        $now = Carbon::now();

        return match ($period) {
            'day' => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
                'day', 'day',
            ],
            'month' => [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth(),
                $now->copy()->subMonth()->startOfMonth(),
                $now->copy()->subMonth()->endOfMonth(),
                'month', 'month',
            ],
            default => [
                $now->copy()->startOfWeek(),
                $now->copy()->endOfWeek(),
                $now->copy()->subWeek()->startOfWeek(),
                $now->copy()->subWeek()->endOfWeek(),
                'week', 'week',
            ],
        };
    }

    private function resolveDriverStatus(User $driver): string
    {
        // RV-42a: "suspended" means BANNED (status -1) - see `User::scopeBannedNow()`.
        // It was `status == 0`, which is LOGGED_OUT, so every driver who was simply
        // signed out, or who had been unbanned, read as suspended.
        if ($driver->isBannedNow()) {
            return 'suspended';
        }
        if ($driver->is_verified_driver) {
            return 'verified';
        }
        if ($driver->verification_status === 'pending') {
            return 'pending';
        }
        if ($driver->verification_status === 'rejected') {
            return 'rejected';
        }

        return 'unverified';
    }

    private function resolveDriverPhone(int $driverId): ?string
    {
        return Ride::where('driver_id', $driverId)
            ->whereNotNull('communication_number')
            ->orderByDesc('created_at')
            ->value('communication_number');
    }

    private function humanTime(Carbon $time): string
    {
        $diffMins = $time->diffInMinutes(now());

        if ($diffMins < 60) {
            return $diffMins.' mins ago';
        }
        if ($diffMins < 1440) {
            return $time->diffInHours(now()).' hours ago';
        }

        return $time->format('d M Y');
    }
}
