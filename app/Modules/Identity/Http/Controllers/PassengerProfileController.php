<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Domain\ValueObjects\Money;
use App\Http\Controllers\Controller;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Identity\Services\JwtService;
use App\Modules\Identity\Services\PassengerProfilePanelBuilder;
use App\Modules\Identity\Services\PassengerProfileQueryService;
use App\Modules\Money\Contracts\PassengerWalletChargerInterface;
use App\Support\Logging\SafeExceptionLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * PassengerProfileController
 *
 * Provides the admin-facing passenger profile dashboard.
 *
 * ── Routes (all behind staff:* middleware) ──────────────────────────────────
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
 */
final class PassengerProfileController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly NotificationPort $notificationService,
        private readonly PassengerProfileQueryService $profileQueries,
        private readonly PassengerWalletChargerInterface $walletCharger,
        private readonly PassengerProfilePanelBuilder $panels,
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
                $user = $this->profileQueries->profileBundle($userId);

                return [
                    'user' => $this->panels->formatUser($user),
                    'stats' => $this->panels->buildStats($user),
                    'monthly_trips' => $this->panels->buildMonthlyTrips($userId),
                    'recent_trips' => $this->panels->buildRecentTrips($userId),
                    'complaints' => $this->panels->buildComplaints($userId),
                    'wallet_charges' => $this->panels->buildWalletCharges($userId, $user),
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('PassengerProfile: fullProfile failed', ['user_id' => $userId, 'error' => SafeExceptionLog::message($e)]);

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
                $user = $this->profileQueries->withWalletOrFail($userId);

                return $this->panels->buildStats($user);
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
            return $this->panels->buildMonthlyTrips($userId, $months);
        });

        return response()->json([
            'status' => 'success',
            'data' => $data,
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
            return $this->panels->buildRecentTrips($userId, $limit);
        });

        return response()->json([
            'status' => 'success',
            'data' => $data,
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
            'status' => 'sometimes|in:all,pending,in_review,resolved,closed,escalated',
            'type' => 'sometimes|in:all,trip_safety,driver_behavior,passenger_behavior,ride_cancellation,financial_issue,account_issue,technical_issue,no_show,other',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $paginator = $this->profileQueries->complaintsFor(
            userId: $userId,
            status: $request->get('status', 'all'),
            type: $request->get('type', 'all'),
            perPage: (int) $request->get('per_page', 15),
            page: (int) $request->get('page', 1),
        );

        return response()->json([
            'status' => 'success',
            'data' => $paginator->getCollection()
                ->map(fn ($c) => $this->panels->formatComplaint($c))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            // Tab badge counts for this user only
            'counts' => $this->panels->complaintCounts($userId),
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
        $user = $this->profileQueries->withWallet($userId);
        if (! $user || ! $user->wallet) {
            return response()->json(['status' => 'success', 'data' => [], 'meta' => []]);
        }

        $paginator = $this->profileQueries->adminChargesFor(
            $user->wallet->id,
            (int) $request->get('per_page', 15),
            (int) $request->get('page', 1),
        );

        return response()->json([
            'status' => 'success',
            // Stage 2 C1: the collection already holds Money's formatted rows (WalletChargeHistory),
            // so the map through formatWalletCharge() is gone - same keys, same order, same JSON.
            'data' => $paginator->getCollection()->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
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
            'amount' => 'required|numeric|min:1|max:10000000',
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $user = $this->profileQueries->withWalletOrFail($userId);

            if (! $user->wallet) {
                return $this->walletCharger->noWalletResponse();
            }

            $amount = (float) $request->input('amount');

            DB::transaction(function () use ($user, $amount, $request) {
                $credit = $this->walletCharger->creditWalletBalance($user, $amount);
                $this->recordAdminChargeTransaction($credit, $user, $amount, $request);

                // ── Decision un3 (owner choice (a)): external flow ──────────────────────
                // An admin charge is money arriving from OUTSIDE the platform. Recorded against the
                // External Capital account so the ledger closes - without it this was a balance
                // change no ledger entry could explain.
                $this->walletCharger->injectExternalCapital($credit['wallet'], $user, $amount);

                Log::info('Admin charged passenger wallet', [
                    'passenger_id' => $user->id,
                    'admin_id' => $request->user()?->id,
                    'amount' => $amount,
                    'previous_balance' => $credit['previous'],
                    'new_balance' => $credit['current'],
                ]);
            });

            // Bust caches — wallet balance and transaction list are now stale
            $this->walletCharger->bustWalletChargeCaches($userId);

            // Notify the user
            $this->notifyWalletCharged($user, $amount, $request);

            $user->wallet->refresh();

            return $this->walletCharger->chargeSuccessResponse($user->wallet);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('PassengerProfile: chargeWallet failed', [
                'user_id' => $userId,
                'error' => SafeExceptionLog::message($e),
            ]);

            return $this->serverError();
        }
    }

    // =========================================================================
    /**
     * The admin_charge wallet row, then the matching inbound leg from External Capital.
     *
     * @param  array{wallet: Wallet, previous: float, current: float}  $credit
     */
    private function recordAdminChargeTransaction(array $credit, $user, float $amount, Request $request): void
    {
        $adminChargeTransaction = $this->profileQueries->createTransaction([
            'wallet_id' => $credit['wallet']->id,
            'user_id' => $request->user()?->id,  // the admin
            'type' => 'admin_charge',
            'amount' => $amount,
            'previous_balance' => $credit['previous'],
            'new_balance' => $credit['current'],
            'description' => $request->input('admin_notes') ?? 'Admin wallet charge',
            // T3-1: was 'ADM-'.$user->id.'-'.now()->timestamp, i.e.
            // unique only per-second per-user. wallet_transactions
            // .transaction_id is UNIQUE, so two charges for the same
            // passenger inside one second threw and rolled back a
            // legitimate money movement. UUID makes it collision-free;
            // the readable prefix is kept for ops.
            'transaction_id' => 'ADM-'.$user->id.'-'.(string) Str::uuid(),
            'status' => 'completed',
            'reference' => 'admin_charge:'.$user->id,
        ]);

        // Stage 2 B2: hand this row to the injection so the inbound leg can name it. NOTE: `chargeWallet()`
        // calls `injectExternalCapital()` directly as well, so one admin charge currently injects twice;
        // linking only this call makes any duplicate transfer visible as an unlinked twin (parked, sec 264).
        $this->walletCharger->injectExternalCapital($credit['wallet'], $user, $amount, $adminChargeTransaction);
    }

    /** Tell the passenger the administration topped up their wallet (non-fatal on failure). */
    private function notifyWalletCharged($user, float $amount, Request $request): void
    {
        try {
            $this->notificationService->createNotification(
                $user,
                'wallet_charged',
                'تم شحن محفظتك',
                "تم إضافة {$amount} ر.س إلى محفظتك بواسطة الإدارة.".
                ($request->input('admin_notes') ? ' ملاحظة: '.$request->input('admin_notes') : ''),
                ['amount' => $amount],
                'normal',
                'system'
            );
        } catch (\Throwable $e) {
            // T3-13: non-fatal by intent, but it must be visible.
            Log::warning('wallet charge notification failed (non-fatal): '.SafeExceptionLog::message($e));
        }
    }

    // PRIVATE BUILDERS
    // =========================================================================

    // =========================================================================
    // FORMATTERS
    // =========================================================================

    // =========================================================================
    // SHARED
    // =========================================================================

    private function serverError(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }
}
