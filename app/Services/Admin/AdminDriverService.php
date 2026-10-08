<?php

namespace App\Services\Admin;

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
        $suspendedDrivers = User::where('status', 0)->count();

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
            'suspended' => $query->where('status', 0),
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
        $driver = User::with([
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

        $ratingStats = UserRating::where('rated_user_id', $driverId)
            ->selectRaw('COUNT(*) as total, AVG(rating) as average')
            ->first();

        $completedRides = Ride::where('driver_id', $driverId)
            ->where('status', 'finished')
            ->count();

        $documents = $driver->photos->mapWithKeys(
            fn ($p) => [$p->type => asset('storage/'.$p->path)]
        );

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
            'vehicle' => [
                'type' => $profile?->type_of_car,
                'color' => $profile?->color_of_car,
                'seats' => $profile?->number_of_seats,
                'photo' => $profile?->car_pic
                    ? asset('storage/'.$profile->car_pic)
                    : null,
            ],
            'documents' => $documents,
            'rating' => [
                'average' => $ratingStats->average
                    ? round((float) $ratingStats->average, 2)
                    : null,
                'total_ratings' => (int) $ratingStats->total,
            ],
            'stats' => [
                'completed_rides' => $completedRides,
                // RV-19 item 5: the uncapped count, not the 5-row recent collection.
                'total_rides' => (int) $driver->rides_count,
            ],
            'recent_rides' => $driver->rides->map(fn ($r) => [
                'id' => $r->id,
                'pickup_address' => $r->pickup_address,
                'destination_address' => $r->destination_address,
                'departure_time' => $r->departure_time->toIso8601String(),
                'status' => $r->status,
                'bookings_count' => $r->bookings_count,
            ])->values()->all(),
        ];
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
        $ratingStats = UserRating::where('rated_user_id', $driverId)
            ->selectRaw('COUNT(*) as total, ROUND(AVG(rating), 2) as average')
            ->first();

        // ── Stats ─────────────────────────────────────────────────────────────
        $totalRides = Ride::where('driver_id', $driverId)->count();
        $completedRides = Ride::where('driver_id', $driverId)->where('status', 'finished')->count();
        $cancelledRides = Ride::where('driver_id', $driverId)->where('status', 'cancelled')->count();

        $cancelRate = $totalRides > 0
            ? round(($cancelledRides / $totalRides) * 100, 1)
            : 0.0;

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
        $ledgerEarnings = WalletTransaction::where('user_id', $driverId)
            ->whereIn('type', [LedgerType::RIDE_EARNINGS->value, LedgerType::RIDE_EARNING->value])
            ->where('status', 'completed')
            ->sum('amount');

        // `estimated_gross` is the SAME arithmetic as before, kept and RELABELLED honestly: it is a
        // projection off current ride prices, not a settled amount. Nothing is lost by renaming it,
        // and everything is gained: a reader can no longer mistake a projection for a payout.
        // The rate is bound from config instead of written into the SQL, so the platform's cut is
        // defined in exactly one place.
        $estimatedGross = Booking::join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->where('rides.driver_id', $driverId)
            ->where('bookings.status', 'completed')
            ->selectRaw('SUM(bookings.seats * rides.price_per_seat * ?) as total', [(float) config('fees.driver_share_rate', 0.95)])
            ->value('total') ?? 0.0;

        // ── Recent rides ──────────────────────────────────────────────────────
        $recentRides = Ride::where('driver_id', $driverId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'status', 'pickup_address', 'destination_address', 'price_per_seat', 'departure_time']);

        // ── Favorite destination ──────────────────────────────────────────────
        $favoriteDestination = Ride::where('driver_id', $driverId)
            ->where('status', 'finished')
            ->select('destination_address')
            ->selectRaw('COUNT(*) as visit_count')
            ->groupBy('destination_address')
            ->orderByDesc('visit_count')
            ->first();

        // ── Documents ─────────────────────────────────────────────────────────
        $documents = $driver->photos->map(fn ($photo) => [
            'type' => $photo->type,
            'file_url' => asset('storage/'.$photo->path),
        ]);

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

            'stats' => [
                'total_rides' => $totalRides,
                'completed_rides' => $completedRides,
                'cancelled_rides' => $cancelledRides,
                'cancel_rate' => $cancelRate,                        // e.g. 2.4 (%)
                // AUTHORITATIVE: real settled money paid to this driver for rides.
                'ledger_earnings' => round((float) $ledgerEarnings, 2),
                // A projection off CURRENT ride prices, not a payout.
                'estimated_gross' => round((float) $estimatedGross, 2),
                // ALIAS of `ledger_earnings`, kept so the admin front-end does not break (D5 = C).
                'total_earnings' => round((float) $ledgerEarnings, 2),
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
        $events = [];

        // ── 1. Verification status changes ────────────────────────────────────
        $recentVerifications = User::with('profile:user_id,profile_photo')
            ->whereHas('photos', fn ($p) => $p->whereIn('type', ['license', 'mechanic_card']))
            ->whereIn('verification_status', ['pending', 'approved', 'rejected'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

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

        // ── 2. Vehicle / profile updates by drivers ───────────────────────────
        $recentProfileUpdates = Profile::with('user:id,first_name,last_name')
            ->whereHas('user', fn ($q) => $q->where('is_verified_driver', true)
                ->orWhereHas('photos', fn ($p) => $p->whereIn('type', ['license', 'mechanic_card']))
            )
            ->whereNotNull('type_of_car')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

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

        usort($events, fn ($a, $b) => strcmp($b['occurred_at'], $a['occurred_at']));

        return array_slice(array_values($events), 0, $limit);
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

        $totalIncoming = $this->countIncomingVerifications($currentStart, $currentEnd);
        $processed = $this->countProcessedVerifications($currentStart, $currentEnd);

        $efficiencyPct = $totalIncoming > 0
            ? round(($processed / $totalIncoming) * 100)
            : 100;

        $prevTotalIncoming = $this->countIncomingVerifications($previousStart, $previousEnd);
        $prevProcessed = $this->countProcessedVerifications($previousStart, $previousEnd);

        $prevEfficiencyPct = $prevTotalIncoming > 0
            ? round(($prevProcessed / $prevTotalIncoming) * 100)
            : 100;

        $delta = $efficiencyPct - $prevEfficiencyPct;
        $deltaDisplay = ($delta >= 0 ? '+' : '').$delta.'%';
        $trend = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');

        $comparisonText = match (true) {
            $delta > 0 => abs($delta).'% higher than last '.$label,
            $delta < 0 => abs($delta).'% lower than last '.$label,
            default => 'Same as last '.$label,
        };

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
                'total_incoming' => $totalIncoming,
                'processed' => $processed,
                'pending' => max(0, $totalIncoming - $processed),
                'efficiency_pct' => $efficiencyPct,
            ],
            'previous' => [
                'label' => 'Last '.$previousLabel,
                'start' => $previousStart->toDateTimeString(),
                'end' => $previousEnd->toDateTimeString(),
                'total_incoming' => $prevTotalIncoming,
                'processed' => $prevProcessed,
                'efficiency_pct' => $prevEfficiencyPct,
            ],
            'comparison' => [
                'delta' => $delta,
                'delta_display' => $deltaDisplay,
                'trend' => $trend,
                'text' => $comparisonText,
            ],
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
        if ($driver->status == 0) {
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
