<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\Profile;
use App\Models\Ride;
use App\Models\ScoreTransaction;
use App\Models\User;
use App\Models\UserRating;
use App\Models\UserScore;
use App\Models\WalletTransaction;
use App\Services\JwtService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;      // ← added
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * PassengerProfileController
 *
 * Provides the admin-facing passenger profile dashboard.
 *
 * ── Routes (all behind auth.admin middleware) ──────────────────────────────
 *
 *  BFF (single call, returns full page):
 *    GET  /api/admin/passengers/{userId}/full-profile   → fullProfile()
 *
 *  Individual (for partial refreshes):
 *    GET  /api/admin/passengers/{userId}/stats          → stats()
 *    GET  /api/admin/passengers/{userId}/monthly-trips  → monthlyTrips()
 *    GET  /api/admin/passengers/{userId}/recent-trips   → recentTrips()
 *    GET  /api/admin/passengers/{userId}/complaints     → complaints()
 *    GET  /api/admin/passengers/{userId}/wallet-charges → walletCharges()
 *
 *  Actions:
 *    POST /api/admin/passengers/{userId}/charge-wallet  → chargeWallet()
 *    POST /api/admin/passengers/{userId}/increase-score → increaseScore()
 *    POST /api/admin/passengers/{userId}/decrease-score → decreaseScore()
 *    POST /api/admin/users/{userId}/ban                 → ban()   (AdminBanController)
 *    POST /api/admin/users/{userId}/unban               → unban() (AdminBanController)
 *
 * ── Caching summary ─────────────────────────────────────────────────────────
 *
 *  CACHED    fullProfile()    admin.passenger.full-profile.{id}           5 min
 *  CACHED    stats()          admin.passenger.stats.{id}                  5 min
 *  CACHED    monthlyTrips()   admin.passenger.monthly-trips.{id}.{months} 15 min
 *  CACHED    recentTrips()    admin.passenger.recent-trips.{id}.{limit}   3 min
 *  NOT CACHED complaints()    paginated + filters + status changes from admin side
 *  NOT CACHED walletCharges() paginated financial data
 *  NOT CACHED chargeWallet()  mutation — busts full-profile + stats + dashboard
 *  NOT CACHED increase/decreaseScore() mutation — busts full-profile + stats + dashboard
 */
final class PassengerProfileController extends Controller
{
    public function __construct(
        private readonly JwtService           $jwtService,
        private readonly NotificationService  $notificationService,
    ) {}

    // =========================================================================
    // BFF — full page in one call
    // =========================================================================

    /**
     * GET /api/admin/passengers/{userId}/full-profile
     *
     * CACHED — 5 minutes per user.
     * The BFF fires many sub-queries (stats, monthly trips, recent trips,
     * complaints summary, wallet charges). Caching is high-value since an
     * admin may refresh the modal several times without anything changing.
     * Cache is busted immediately by chargeWallet() which changes wallet data.
     */
    public function fullProfile(int $userId): JsonResponse
    {
        try {
            $data = Cache::remember("admin.passenger.full-profile.{$userId}", 300, function () use ($userId) {
                $user = User::with(['profile', 'wallet', 'photos', 'userScore'])->findOrFail($userId);

                return [
                    'user'           => $this->formatUser($user),
                    'stats'          => $this->buildStats($user),
                    'monthly_trips'  => $this->buildMonthlyTrips($userId),
                    'recent_trips'   => $this->buildRecentTrips($userId),
                    'complaints'     => $this->buildComplaints($userId),
                    'wallet_charges' => $this->buildWalletCharges($userId),
                ];
            });

            return response()->json([
                'status' => 'success',
                'data'   => $data,
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('PassengerProfile: fullProfile failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return $this->serverError();
        }
    }

    // =========================================================================
    // INDIVIDUAL REFRESH ENDPOINTS
    // =========================================================================

    /**
     * GET /api/admin/passengers/{userId}/stats
     *
     * CACHED — 5 minutes per user.
     * Stats include wallet_balance — this is an admin read, not a payment
     * endpoint, so 5-minute staleness is acceptable. Cache is busted
     * immediately by chargeWallet() since the balance changes.
     */
    public function stats(int $userId): JsonResponse
    {
        try {
            $data = Cache::remember("admin.passenger.stats.{$userId}", 300, function () use ($userId) {
                $user = User::with(['wallet', 'userScore'])->findOrFail($userId);
                return $this->buildStats($user);
            });

            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        }
    }

    /**
     * GET /api/admin/passengers/{userId}/monthly-trips
     *
     * CACHED — 15 minutes per user + months value.
     * Pure historical aggregate (completed bookings grouped by month). A new
     * booking completing mid-session won't shift the chart meaningfully within
     * 15 minutes.
     *
     * Query params:
     *   months = 1-12 (default 6)
     */
    public function monthlyTrips(int $userId, Request $request): JsonResponse
    {
        $months = min(12, max(1, (int) $request->get('months', 6)));

        $data = Cache::remember("admin.passenger.monthly-trips.{$userId}.{$months}", 900, function () use ($userId, $months) {
            return $this->buildMonthlyTrips($userId, $months);
        });

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }

    /**
     * GET /api/admin/passengers/{userId}/recent-trips
     *
     * CACHED — 3 minutes per user + limit value.
     * "Recent" implies freshness, so a shorter TTL than the other endpoints.
     * Three minutes is the minimum worth having — still removes per-request
     * DB pressure when an admin paginates or re-opens the modal quickly.
     *
     * Query params:
     *   limit = 1-50 (default 10)
     */
    public function recentTrips(int $userId, Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->get('limit', 10)));

        $data = Cache::remember("admin.passenger.recent-trips.{$userId}.{$limit}", 180, function () use ($userId, $limit) {
            return $this->buildRecentTrips($userId, $limit);
        });

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }

    /**
     * GET /api/admin/passengers/{userId}/complaints
     *
     * NOT cached.
     * Two reasons:
     *   1. Status changes come from the admin side (not this controller), so
     *      invalidation would require cross-controller coordination.
     *   2. Paginated with status + type + per_page + page filters — unbounded
     *      key combinations.
     *
     * Query params:
     *   status = all | pending | in_review | resolved | closed | escalated
     *   type   = all | trip_safety | driver_behavior | ...
     *   per_page = 1-50 (default 15)
     *   page     = int
     */
    public function complaints(int $userId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status'   => 'sometimes|in:all,pending,in_review,resolved,closed,escalated',
            'type'     => 'sometimes|in:all,trip_safety,driver_behavior,passenger_behavior,ride_cancellation,financial_issue,account_issue,technical_issue,no_show,other',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page'     => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $query = Complaint::with(['assignedAgent:id,first_name,last_name', 'attachments'])
            ->where('user_id', $userId);

        $status = $request->get('status', 'all');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $type = $request->get('type', 'all');
        if ($type !== 'all') {
            $query->where('type', $type);
        }

        $paginator = $query
            ->orderByDesc('created_at')
            ->paginate(
                (int) $request->get('per_page', 15),
                ['*'],
                'page',
                (int) $request->get('page', 1)
            );

        return response()->json([
            'status' => 'success',
            'data'   => $paginator->getCollection()
                ->map(fn($c) => $this->formatComplaint($c))
                ->values(),
            'meta'   => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            // Tab badge counts for this user only
            'counts' => $this->complaintCounts($userId),
        ]);
    }

    /**
     * GET /api/admin/passengers/{userId}/wallet-charges
     *
     * NOT cached — financial data, paginated, and mutated by chargeWallet().
     *
     * Query params:
     *   per_page = 1-50 (default 15)
     *   page     = int
     */
    public function walletCharges(int $userId, Request $request): JsonResponse
    {
        $user = User::with('wallet')->find($userId);
        if (!$user || !$user->wallet) {
            return response()->json(['status' => 'success', 'data' => [], 'meta' => []]);
        }

        $paginator = \App\Models\WalletTransaction::with([
            'user:id,first_name,last_name',
            'user.profile:user_id,profile_photo',
        ])
            ->where('wallet_id', $user->wallet->id)
            ->where('type', 'admin_charge')
            ->orderByDesc('created_at')
            ->paginate(
                (int) $request->get('per_page', 15),
                ['*'],
                'page',
                (int) $request->get('page', 1)
            );

        return response()->json([
            'status' => 'success',
            'data'   => $paginator->getCollection()
                ->map(fn($tx) => $this->formatWalletCharge($tx))
                ->values(),
            'meta'   => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // =========================================================================
    // ACTIONS
    // =========================================================================

    /**
     * POST /api/admin/passengers/{userId}/charge-wallet
     *
     * Mutation — not cached. Busts the full-profile BFF and the stats
     * endpoint because both embed wallet_balance / wallet_charges data
     * that is now stale. Also busts the main admin dashboard since it may
     * aggregate financial totals.
     *
     * Body:
     *   amount      numeric  required, min 1
     *   admin_notes string   optional
     */
    public function chargeWallet(int $userId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount'      => 'required|numeric|min:1|max:10000000',
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $user = User::with('wallet')->findOrFail($userId);

            if (!$user->wallet) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This user does not have a wallet yet.',
                ], 422);
            }

            $amount = (float) $request->input('amount');

            DB::transaction(function () use ($user, $amount, $request) {
                $wallet = \App\Models\Wallet::lockForUpdate()->findOrFail($user->wallet->id);

                $previousBalance = (float) $wallet->balance;
                $newBalance      = $previousBalance + $amount;
                $wallet->balance = $newBalance;
                $wallet->save();

                \App\Models\WalletTransaction::create([
                    'wallet_id'        => $wallet->id,
                    'user_id'          => $request->user()?->id,  // the admin
                    'type'             => 'admin_charge',
                    'amount'           => $amount,
                    'previous_balance' => $previousBalance,
                    'new_balance'      => $newBalance,
                    'description'      => $request->input('admin_notes') ?? 'Admin wallet charge',
                    'transaction_id'   => 'ADM-' . $user->id . '-' . now()->timestamp,
                    'status'           => 'completed',
                    'reference'        => 'admin_charge:' . $user->id,
                ]);

                Log::info('Admin charged passenger wallet', [
                    'passenger_id'     => $user->id,
                    'admin_id'         => $request->user()?->id,
                    'amount'           => $amount,
                    'previous_balance' => $previousBalance,
                    'new_balance'      => $newBalance,
                ]);
            });

            // Bust caches — wallet balance and transaction list are now stale
            Cache::forget("admin.passenger.full-profile.{$userId}");
            Cache::forget("admin.passenger.stats.{$userId}");
            Cache::forget('admin.dashboard.data');
            Cache::forget('admin.dashboard.stats');

            // Notify the user
            try {
                $this->notificationService->createNotification(
                    $user,
                    'wallet_charged',
                    'تم شحن محفظتك',
                    "تم إضافة {$amount} ر.س إلى محفظتك بواسطة الإدارة." .
                    ($request->input('admin_notes') ? ' ملاحظة: ' . $request->input('admin_notes') : ''),
                    ['amount' => $amount],
                    'normal',
                    'system'
                );
            } catch (\Throwable) {
                // Never let notification failure block the charge
            }

            $user->wallet->refresh();

            return response()->json([
                'status'      => 'success',
                'message'     => "Wallet charged successfully. New balance: {$user->wallet->balance} SYP.",
                'new_balance' => (float) $user->wallet->balance,
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('PassengerProfile: chargeWallet failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return $this->serverError();
        }
    }

    /**
     * POST /api/admin/passengers/{userId}/increase-score
     *
     * Mutation — not cached. Busts the full-profile BFF and the stats
     * endpoint because both embed the score / tier.
     *
     * Body:
     *   points integer required, min 1, max 100
     *   reason string  optional, max 500
     */
    public function increaseScore(int $userId, Request $request): JsonResponse
    {
        return $this->adjustScore($userId, $request, 1);
    }

    /**
     * POST /api/admin/passengers/{userId}/decrease-score
     *
     * Mutation — not cached. Busts the full-profile BFF and the stats
     * endpoint because both embed the score / tier.
     *
     * Body:
     *   points integer required, min 1, max 100
     *   reason string  optional, max 500
     */
    public function decreaseScore(int $userId, Request $request): JsonResponse
    {
        return $this->adjustScore($userId, $request, -1);
    }

    /**
     * Shared implementation for increaseScore() / decreaseScore().
     * $sign is +1 or -1, applied to the validated `points` amount.
     */
    private function adjustScore(int $userId, Request $request, int $sign): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'points' => 'required|integer|min:1|max:100',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $user   = User::findOrFail($userId);
            $delta  = $sign * (int) $request->input('points');
            $reason = $request->input('reason');

            $previousScore = null;
            $newScore      = null;

            DB::transaction(function () use ($user, $delta, $reason, $request, &$previousScore, &$newScore) {
                $userScore = UserScore::lockForUpdate()->firstOrCreate(['user_id' => $user->id]);

                $previousScore = $userScore->score;
                $userScore->applyDelta($delta);
                $newScore = $userScore->score;

                ScoreTransaction::create([
                    'user_id'        => $user->id,
                    'action'         => $delta >= 0 ? 'admin_increase' : 'admin_decrease',
                    'points'         => $delta,
                    'previous_score' => $previousScore,
                    'new_score'      => $newScore,
                    'reason'         => $reason,
                    'metadata'       => ['admin_id' => $request->user()?->id],
                ]);

                Log::info('Admin adjusted user score', [
                    'user_id'        => $user->id,
                    'admin_id'       => $request->user()?->id,
                    'delta'          => $delta,
                    'previous_score' => $previousScore,
                    'new_score'      => $newScore,
                    'reason'         => $reason,
                ]);
            });

            // Bust caches — score / tier are embedded in both
            Cache::forget("admin.passenger.full-profile.{$userId}");
            Cache::forget("admin.passenger.stats.{$userId}");
            Cache::forget('admin.dashboard.data');
            Cache::forget('admin.dashboard.stats');

            try {
                $this->notificationService->createNotification(
                    $user,
                    'score_adjusted',
                    $delta >= 0 ? 'تم رفع نقاطك' : 'تم خفض نقاطك',
                    ($delta >= 0
                        ? "تم إضافة {$delta} نقطة إلى رصيدك بواسطة الإدارة."
                        : 'تم خصم ' . abs($delta) . ' نقطة من رصيدك بواسطة الإدارة.')
                    . ($reason ? ' السبب: ' . $reason : ''),
                    ['delta' => $delta, 'new_score' => $newScore],
                    'normal',
                    'system'
                );
            } catch (\Throwable) {
                // Never let notification failure block the adjustment
            }

            return response()->json([
                'status'         => 'success',
                'message'        => "Score updated successfully. New score: {$newScore}.",
                'previous_score' => $previousScore,
                'new_score'      => $newScore,
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('PassengerProfile: adjustScore failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return $this->serverError();
        }
    }

    // =========================================================================
    // PRIVATE BUILDERS
    // =========================================================================

    private function buildStats(User $user): array
    {
        $userId = $user->id;

        $totalRides = Booking::where('user_id', $userId)
            ->whereIn('status', ['completed'])
            ->count();

        $totalSpending = Booking::join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->where('bookings.user_id', $userId)
            ->where('bookings.status', 'completed')
            ->selectRaw('SUM(bookings.seats * rides.price_per_seat) as total')
            ->value('total') ?? 0.0;

        $avgRating = UserRating::where('rated_user_id', $userId)->avg('rating') ?? 0.0;

        $walletBalance = $user->wallet?->balance ?? 0.0;

        return [
            'total_rides'    => $totalRides,
            'total_spending' => round((float) $totalSpending, 2),
            'avg_rating'     => round((float) $avgRating, 1),
            'wallet_balance' => round((float) $walletBalance, 2),
            'score'          => $user->userScore?->score ?? 70,
            'score_tier'     => $user->userScore?->tier ?? 'Silver',
        ];
    }

    /**
     * Monthly trip counts + total cost (seats × price_per_seat) for each month.
     */
    private function buildMonthlyTrips(int $userId, int $months = 6): array
    {
        $rows = Booking::join('rides', 'bookings.ride_id', '=', 'rides.id')
            ->where('bookings.user_id', $userId)
            ->where('bookings.status', 'completed')
            ->where('rides.departure_time', '>=', now()->subMonths($months)->startOfMonth())
            ->selectRaw(
                "DATE_FORMAT(rides.departure_time, '%Y-%m') as month_key,
                 COUNT(*) as trips,
                 SUM(bookings.seats * rides.price_per_seat) as total_cost"
            )
            ->groupBy('month_key')
            ->orderBy('month_key')
            ->get()
            ->keyBy('month_key');

        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $date     = now()->subMonths($i)->startOfMonth();
            $key      = $date->format('Y-m');
            $row      = $rows->get($key);
            $result[] = [
                'month'      => $date->locale('ar')->isoFormat('MMMM'),
                'month_key'  => $key,
                'trips'      => $row ? (int) $row->trips : 0,
                'total_cost' => $row ? round((float) $row->total_cost, 2) : 0.0,
            ];
        }
        return $result;
    }

    /**
     * Recent bookings with correct total cost = seats × price_per_seat.
     */
    private function buildRecentTrips(int $userId, int $limit = 10): array
    {
        return Booking::with([
            'ride:id,pickup_address,destination_address,departure_time,price_per_seat,driver_id',
            'ride.driver:id,first_name,last_name',
        ])
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn($b) => [
                'id'             => $b->id,
                'date'           => $b->created_at->toDateString(),
                'route_from'     => $b->ride?->pickup_address,
                'route_to'       => $b->ride?->destination_address,
                'driver'         => $b->ride?->driver
                    ? trim("{$b->ride->driver->first_name} {$b->ride->driver->last_name}")
                    : null,
                'seats'          => $b->seats,
                'price_per_seat' => (float) ($b->ride?->price_per_seat ?? 0),
                'total_cost'     => round($b->seats * (float) ($b->ride?->price_per_seat ?? 0), 2),
                'status'         => $b->status,
                'departure_time' => $b->ride?->departure_time?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Complaints for this user.
     * No ride_id exposed — only complaint ID, type, and status.
     */
    private function buildComplaints(int $userId, string $status = 'all', string $type = 'all'): array
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
            ->map(fn($c) => $this->formatComplaint($c))
            ->values()
            ->all();
    }

    /**
     * Wallet top-up transactions (admin_charge type only).
     */
    private function buildWalletCharges(int $userId): array
    {
        $user = User::with('wallet')->find($userId);
        if (!$user?->wallet) return [];

        return \App\Models\WalletTransaction::with([
            'user:id,first_name,last_name',
            'user.profile:user_id,profile_photo',
        ])
            ->where('wallet_id', $user->wallet->id)
            ->where('type', 'admin_charge')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($tx) => $this->formatWalletCharge($tx))
            ->values()
            ->all();
    }

    // =========================================================================
    // FORMATTERS
    // =========================================================================

    private function formatUser(User $user): array
    {
        $profile = $user->profile;
        return [
            'id'                    => $user->id,
            'full_name'             => trim("{$user->first_name} {$user->last_name}"),
            'email'                 => $user->email,
            'phone'                 => $profile?->phone ?? null,
            'address'               => $user->address,
            'gender'                => $user->gender,
            'joined_at'             => $user->created_at->toIso8601String(),
            'profile_photo'         => $profile?->profile_photo
                ? asset("storage/{$profile->profile_photo}") : null,
            'verification_status'   => $user->verification_status,
            'is_verified_passenger' => (bool) $user->is_verified_passenger,
            'account_status'        => $user->account_status,
            'ban' => $user->status == -1 ? [
                'reason'     => $user->ban_reason,
                'type'       => $user->ban_type,
                'expires_at' => $user->ban_expires_at?->toIso8601String(),
                'banned_at'  => $user->banned_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function formatComplaint(\App\Models\Complaint $c): array
    {
        $typeLabels = [
            'trip_safety'        => 'أمان الرحلة',
            'driver_behavior'    => 'سلوك السائق',
            'passenger_behavior' => 'سلوك الراكب',
            'ride_cancellation'  => 'إلغاء الرحلة',
            'financial_issue'    => 'مشكلة مالية',
            'account_issue'      => 'مشكلة في الحساب',
            'technical_issue'    => 'عطل تقني',
            'no_show'            => 'تعارض تقارير الغياب',
            'other'              => 'أخرى',
        ];

        $typeValue = $c->type instanceof \App\Enums\ComplaintType
            ? $c->type->value : (string) $c->type;

        $statusValue = $c->status instanceof \App\Enums\ComplaintStatus
            ? $c->status->value : (string) $c->status;

        return [
            'id'          => $c->id,
            'type'        => $typeValue,
            'type_label'  => $typeLabels[$typeValue] ?? $typeValue,
            'status'      => $statusValue,
            'created_at'  => $c->created_at->toDateString(),
            'assigned_to' => $c->assignedAgent
                ? trim("{$c->assignedAgent->first_name} {$c->assignedAgent->last_name}")
                : null,
        ];
    }

    private function formatWalletCharge(\App\Models\WalletTransaction $tx): array
    {
        // The actor is a staff Employee, not a users-table row — resolve via
        // processed_by_employee_id, not the wallet-owner `user_id` column.
        $admin = $tx->processedByEmployee;

        return [
            'id'                   => $tx->id,
            'transaction_id'       => $tx->transaction_id,
            'amount'               => (float) $tx->amount,
            'previous_balance'     => (float) $tx->previous_balance,
            'new_balance'          => (float) $tx->new_balance,
            'status'               => $tx->status,
            'notes'                => $tx->description,
            'date'                 => $tx->created_at->toDateString(),
            'created_at'           => $tx->created_at->toIso8601String(),
            'processed_by_id'      => $admin?->id,
            'processed_by_name'    => $admin?->fullName(),
            // Employees have no photo column (see BUG-12) — always null.
            'processed_by_photo'   => null,
        ];
    }

    private function complaintCounts(int $userId): array
    {
        $rows = Complaint::where('user_id', $userId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        return [
            'all'       => array_sum($rows),
            'pending'   => $rows['pending']   ?? 0,
            'in_review' => $rows['in_review'] ?? 0,
            'escalated' => $rows['escalated'] ?? 0,
            'resolved'  => $rows['resolved']  ?? 0,
            'closed'    => $rows['closed']    ?? 0,
        ];
    }

    // =========================================================================
    // SHARED
    // =========================================================================

    private function serverError(): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }
}
