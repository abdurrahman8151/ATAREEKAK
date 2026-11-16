<?php

namespace App\Modules\Console\Services;

use App\Modules\Identity\Contracts\ProfileDirectory;
use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Identity\Contracts\UserRatingReader;
use Carbon\Carbon;

/**
 * The admin dashboard BFF payload, the driver stat cards, the recent-activity feed and the
 * verification-efficiency widget.
 *
 * Split out of AdminDriverService (behaviour unchanged). Bodies moved verbatim.
 */
final class AdminDashboardActivityService
{
    public function __construct(
        private readonly AdminDriverPresenter $presenter,
    ) {}

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
        // Stage 2 C3: every card is a COUNT over `users` (plus Identity's own `photos` for the "submitted
        // documents" predicate), so Identity runs the statements and this screen keeps the labels and the
        // rounding. RV-19 item 5 / RV-42a live in the owner now: `suspended` goes through the same
        // `bannedNow` rule the driver TABLE's filter uses, so the card and the table cannot disagree.
        $totalDrivers = app(UserDirectory::class)->countDrivers();
        $activeDrivers = app(UserDirectory::class)->countVerifiedDrivers();
        $pendingVerifications = app(UserDirectory::class)->countPendingDrivers();
        $suspendedDrivers = app(UserDirectory::class)->countSuspended();

        // Stage 2 C1/C2: `user_ratings` is Identity's table; the verified-driver filter walks two of its
        // tables, so the module implements it and this card only rounds what it returns.
        $avgRating = app(UserRatingReader::class)->averageAcrossVerifiedDrivers();

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
        // Stage 2 C3: the rows come from Identity as summaries; the event words stay here.
        $recentVerifications = app(UserDirectory::class)->recentVerificationUsers($limit);

        $events = [];

        foreach ($recentVerifications as $user) {
            $name = $user->fullName();
            $status = $user->verificationStatus;

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
                'occurred_at' => $user->updatedAt->toIso8601String(),
                'human_time' => $this->presenter->humanTime($user->updatedAt),
            ];
        }

        return $events;
    }

    /** ── 2. Vehicle / profile updates by drivers ─────────────────────────── */
    private function profileUpdateActivityEvents(int $limit): array
    {
        // Stage 2 C2: `profiles` is Identity's table, so the page query lives behind its port and
        // arrives as scalars. Two statements in, two out - the eager load the caller used to run is
        // kept inside the port, so the frozen admin dashboard query budget does not move.
        $recentProfileUpdates = app(ProfileDirectory::class)->recentVehicleUpdates($limit);

        $events = [];

        foreach ($recentProfileUpdates as $profile) {
            // A profile whose user row is gone is skipped exactly as `if (! $profile->user)` did.
            if ($profile['user_id'] === null) {
                continue;
            }
            $name = trim("{$profile['first_name']} {$profile['last_name']}");

            $events[] = [
                'type' => 'vehicle_update',
                'icon' => 'edit',
                'color' => 'purple',
                'message' => "Vehicle details updated for \"{$name}\"",
                'actor' => $name,
                'user_id' => $profile['user_id'],
                'occurred_at' => $profile['updated_at']->toIso8601String(),
                'human_time' => $this->presenter->humanTime($profile['updated_at']),
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

        // Stage 2 C2: `profiles` is Identity's table. One statement either way; the URL format stays
        // here. This was the second copy of the same lookup - see `ProfileDirectory`.
        $photo = app(ProfileDirectory::class)->photoPathForUser($adminUserId);

        return $photo ? asset('storage/'.$photo) : null;
    }

    private function countIncomingVerifications(Carbon $start, Carbon $end): int
    {
        // Stage 2 C3: the window belongs to this widget; the statement (and "which documents count") belongs
        // to Identity, which owns both `users` and `photos`.
        return app(UserDirectory::class)->countUsersWithDriverDocumentsCreatedBetween($start, $end);
    }

    private function countProcessedVerifications(Carbon $start, Carbon $end): int
    {
        return app(UserDirectory::class)->countProcessedVerifications($start, $end);
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
