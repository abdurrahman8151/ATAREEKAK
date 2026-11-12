<?php

namespace App\Modules\Identity\Services;

use App\Domain\ValueObjects\Money;
use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use App\Models\Complaint;
use App\Models\Profile;
use App\Models\User;
use App\Models\UserRating;
use App\Modules\Money\Contracts\WalletChargeHistory;
use App\Modules\Money\Contracts\WalletDirectory;
use App\Modules\Ride\Contracts\PassengerBookingHistory;

/**
 * The five panels of the admin passenger profile page - stats, monthly trips, recent trips,
 * complaints and wallet charges - and the row formatters they share.
 *
 * Split out of PassengerProfileController (behaviour unchanged) as a behaviour trait so the
 * controller keeps calling the builders and formatters exactly as it did. Bodies moved verbatim,
 * so every panel key and every fallback is untouched.
 *
 * Unlike the controller, nothing here builds an HTTP response, so the trait carries no
 * Illuminate\Http\Request.
 */
final class PassengerProfilePanelBuilder
{
    /**
     * Stage 2 C1: the wallet-charge rows are Money's, so they arrive already formatted from Money's
     * `WalletChargeHistory` port instead of being built here from `WalletTransaction` (a model this
     * module will not be allowed to name once it moves into the Money namespace).
     */
    public function __construct(
        private readonly WalletChargeHistory $chargeHistory,
        private readonly WalletDirectory $wallets,
    ) {}

    public function buildStats(User $user, $wallet = null): array
    {
        $userId = $user->id;

        // Stage 2 C2: ookings is Ride's, so both aggregates come from Ride's port.
        $history = app(PassengerBookingHistory::class);
        $totalRides = $history->completedRidesCount($userId);
        $totalSpending = $history->completedSpending($userId);

        $avgRating = UserRating::where('rated_user_id', $userId)->avg('rating') ?? 0.0;

        // Stage 2 C1: the summary is passed in by the caller so a request never pays two lookups.
        $wallet ??= $this->wallets->forUser((int) $user->id);
        $walletBalance = $wallet?->balance ?? 0.0;

        return [
            'total_rides' => $totalRides,
            // `R2 sec 117`: money figures are rounded once, in `Money`. `avg_rating` below is a RATING
            // at 1dp and is deliberately NOT money - do not "fix" it.
            'total_spending' => Money::from((float) $totalSpending)->amount(),
            'avg_rating' => round((float) $avgRating, 1),
            'wallet_balance' => Money::from((float) $walletBalance)->amount(),
        ];
    }

    /**
     * Monthly trip counts + total cost (seats × price_per_seat) for each month.
     */
    public function buildMonthlyTrips(int $userId, int $months = 6): array
    {
        // Stage 2 C2: the grouped query is Ride's; the month labels and money below are this panel's.
        $rows = app(PassengerBookingHistory::class)->monthlyTrips($userId, $months);

        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->subMonths($i)->startOfMonth();
            $key = $date->format('Y-m');
            $row = $rows[$key] ?? null;
            $result[] = [
                'month' => $date->locale('ar')->isoFormat('MMMM'),
                'month_key' => $key,
                'trips' => $row ? (int) $row['trips'] : 0,
                'total_cost' => $row ? Money::from((float) $row['total_cost'])->amount() : 0.0,
            ];
        }

        return $result;
    }

    /**
     * Recent bookings with correct total cost = seats × price_per_seat.
     */
    public function buildRecentTrips(int $userId, int $limit = 10): array
    {
        // Stage 2 C2: the bookings, their ride and their driver come from Ride's port already shaped; the
        // only thing left here is `total_cost`, which is THIS panel's money format.
        return collect(app(PassengerBookingHistory::class)->recentTrips($userId, $limit))
            ->map(fn ($b) => [
                'id' => $b['id'],
                'date' => $b['date'],
                'route_from' => $b['route_from'],
                'route_to' => $b['route_to'],
                'driver' => $b['driver'],
                'seats' => $b['seats'],
                'price_per_seat' => $b['price_per_seat'],
                'total_cost' => Money::from((float) $b['price_per_seat'])
                    ->multiply((float) $b['seats'])
                    ->amount(),
                'status' => $b['status'],
                'departure_time' => $b['departure_time'],
            ])
            ->values()
            ->all();
    }

    /**
     * Complaints for this user.
     * No ride_id exposed — only complaint ID, type, and status.
     */
    public function buildComplaints(int $userId, string $status = 'all', string $type = 'all'): array
    {
        $query = Complaint::with(['assignedAgent:id,first_name,last_name'])
            ->where('user_id', $userId);

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($type !== 'all') {
            $query->where('type', $type);
        }

        return $query
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($c) => $this->formatComplaint($c))
            ->values()
            ->all();
    }

    /**
     * Wallet top-up transactions (admin_charge type only).
     *
     * Stage 0.6 item 1: `$user` is optional so the full-profile bundle - which has ALREADY loaded
     * this user with its wallet (`PassengerProfileQueryService::profileBundle()`) - can hand it over
     * instead of this method re-reading the same two rows per request. The standalone
     * `/wallet-charges` endpoint passes nothing and keeps its own lookup, so its query count and
     * output are unchanged.
     */
    public function buildWalletCharges(int $userId, ?User $user = null, $wallet = null): array
    {
        $user ??= User::find($userId);
        if (! $user) {
            return [];
        }

        $wallet ??= $this->wallets->forUser((int) $user->id);
        if (! $wallet) {
            return [];
        }

        // Rows come from Money already in the shape this panel used to build itself: id,
        // transaction_id, amount, previous_balance, new_balance, status, notes, date, created_at,
        // processed_by_id, processed_by_name, processed_by_photo.
        return $this->chargeHistory->recentForWallet($wallet->id, 20);
    }

    public function formatUser(User $user): array
    {
        $profile = $user->profile;

        return [
            'id' => $user->id,
            'full_name' => trim("{$user->first_name} {$user->last_name}"),
            'email' => $user->email,
            'phone' => $profile?->phone ?? null,
            'address' => $user->address,
            'gender' => $user->gender,
            'joined_at' => $user->created_at->toIso8601String(),
            'profile_photo' => $profile?->profile_photo
                ? asset("storage/{$profile->profile_photo}") : null,
            'verification_status' => $user->verification_status,
            'is_verified_passenger' => (bool) $user->is_verified_passenger,
            'account_status' => $user->account_status,
            'ban' => $user->status == -1 ? [
                'reason' => $user->ban_reason,
                'type' => $user->ban_type,
                'expires_at' => $user->ban_expires_at?->toIso8601String(),
                'banned_at' => $user->banned_at?->toIso8601String(),
            ] : null,
        ];
    }

    public function formatComplaint(Complaint $c): array
    {
        $typeLabels = [
            'trip_safety' => 'أمان الرحلة',
            'driver_behavior' => 'سلوك السائق',
            'passenger_behavior' => 'سلوك الراكب',
            'ride_cancellation' => 'إلغاء الرحلة',
            'financial_issue' => 'مشكلة مالية',
            'account_issue' => 'مشكلة في الحساب',
            'technical_issue' => 'عطل تقني',
            'no_show' => 'تعارض تقارير الغياب',
            'other' => 'أخرى',
        ];

        $typeValue = $c->type instanceof ComplaintType
            ? $c->type->value : (string) $c->type;

        $statusValue = $c->status instanceof ComplaintStatus
            ? $c->status->value : (string) $c->status;

        return [
            'id' => $c->id,
            'type' => $typeValue,
            'type_label' => $typeLabels[$typeValue] ?? $typeValue,
            'status' => $statusValue,
            'created_at' => $c->created_at->toDateString(),
            'assigned_to' => $c->assignedAgent
                ? trim("{$c->assignedAgent->first_name} {$c->assignedAgent->last_name}")
                : null,
        ];
    }

    public function complaintCounts(int $userId): array
    {
        $rows = Complaint::where('user_id', $userId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return [
            'all' => array_sum($rows),
            'pending' => $rows['pending'] ?? 0,
            'in_review' => $rows['in_review'] ?? 0,
            'escalated' => $rows['escalated'] ?? 0,
            'resolved' => $rows['resolved'] ?? 0,
            'closed' => $rows['closed'] ?? 0,
        ];
    }
}
