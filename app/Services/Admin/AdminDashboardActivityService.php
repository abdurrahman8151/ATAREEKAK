<?php

namespace App\Services\Admin;

use App\Models\Photo;
use App\Models\Profile;
use App\Models\User;
use App\Models\UserRating;
use App\Services\Admin\Concerns\AdminDriverPresenting;
use Carbon\Carbon;

/**
 * The admin dashboard BFF payload, the driver stat cards, the recent-activity feed and the
 * verification-efficiency widget.
 *
 * Split out of AdminDriverService (behaviour unchanged). Bodies moved verbatim.
 */
final class AdminDashboardActivityService
{
    use AdminDriverPresenting;

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
}
