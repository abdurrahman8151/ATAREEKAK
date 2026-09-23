<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WalletRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use App\Services\NotificationService;
/**
 * WalletRequestController
 *
 * User-facing endpoints for submitting and viewing wallet requests.
 *
 * Routes (all behind `jwt` middleware):
 *   POST   /api/wallet/requests          → store()
 *   POST   /api/wallet/request-charge    → requestCharge()
 *   POST   /api/wallet/request-withdraw  → requestWithdraw()
 *   GET    /api/wallet/requests          → myRequests()
 *   GET    /api/wallet/requests/{id}     → show()
 *   DELETE /api/wallet/requests/{id}     → destroy()
 */
class WalletRequestController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    // ── POST /api/wallet/requests ────────────────────────────────────────────

    /**
     * Unified entry point: dispatches to requestCharge() or requestWithdraw()
     * based on `type`. request-charge / request-withdraw remain available as
     * direct routes for callers that already target them.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:charge,withdraw',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        return $request->input('type') === 'charge'
            ? $this->requestCharge($request)
            : $this->requestWithdraw($request);
    }

    // ── POST /api/wallet/request-charge ──────────────────────────────────────

    /**
     * User asks admin to top up their wallet.
     *
     * The user specifies the amount they want added; the admin reviews
     * and, when approved, manually adds the balance (offline transfer).
     */
    public function requestCharge(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->wallet) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have a wallet yet. Please create one first.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1|max:10000000',
            'notes'  => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $alreadyPending = WalletRequest::where('user_id', $user->id)
            ->where('type', 'charge')
            ->where('status', 'pending')
            ->exists();

        if ($alreadyPending) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending charge request. Please wait for it to be reviewed.',
            ], 409);
        }

        $walletRequest = WalletRequest::create([
            'user_id'    => $user->id,
            'wallet_id'  => $user->wallet->id,
            'type'       => 'charge',
            'amount'     => $request->input('amount'),
            'status'     => 'pending',
            'user_notes' => $request->input('notes'),
        ]);

        try {
            $this->notificationService->createNotification(
                $user,
                'charge_request_received',
                'تم استلام طلب الشحن',
                'سيتم مراجعة طلب شحن المحفظة من قِبل الإدارة قريباً.',
                ['wallet_request_id' => $walletRequest->id],
                'normal',
                'system'
            );
        } catch (\Throwable) {}

        Cache::forget("wallet.requests.{$user->id}");

        return response()->json([
            'success' => true,
            'message' => 'Charge request submitted. The admin will review it shortly.',
            'data'    => $this->formatRequest($walletRequest),
        ], 201);
    }

    // ── POST /api/wallet/request-withdraw ────────────────────────────────────

    /**
     * User asks admin to withdraw funds from their wallet.
     *
     * Validated immediately against current balance so the user
     * gets instant feedback; balance is only deducted on approval.
     */
    public function requestWithdraw(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->wallet) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have a wallet yet.',
            ], 422);
        }

        $wallet = $user->wallet;

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'notes'  => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $amount = (float) $request->input('amount');

        if ($amount > (float) $wallet->balance) {
            return response()->json([
                'success' => false,
                'message' => "Insufficient balance. Your current balance is {$wallet->balance} SYP.",
            ], 422);
        }

        $pendingTotal = WalletRequest::where('user_id', $user->id)
            ->where('type', 'withdraw')
            ->where('status', 'pending')
            ->sum('amount');

        if (($pendingTotal + $amount) > (float) $wallet->balance) {
            return response()->json([
                'success' => false,
                'message' => "You already have pending withdraw requests totalling {$pendingTotal} SYP. This request would exceed your balance.",
            ], 422);
        }

        $walletRequest = WalletRequest::create([
            'user_id'    => $user->id,
            'wallet_id'  => $wallet->id,
            'type'       => 'withdraw',
            'amount'     => $amount,
            'status'     => 'pending',
            'user_notes' => $request->input('notes'),
        ]);

        try {
            $this->notificationService->createNotification(
                $user,
                'withdraw_request_received',
                'تم استلام طلب السحب',
                'سيتم مراجعة طلب سحب المحفظة من قِبل الإدارة قريباً.',
                ['wallet_request_id' => $walletRequest->id],
                'normal',
                'system'
            );
        } catch (\Throwable) {}

        Cache::forget("wallet.requests.{$user->id}");

        return response()->json([
            'success' => true,
            'message' => 'Withdraw request submitted. The admin will process it shortly.',
            'data'    => $this->formatRequest($walletRequest),
        ], 201);
    }

    // ── GET /api/wallet/requests ──────────────────────────────────────────────

    /**
     * Returns the authenticated user's own wallet requests (newest first).
     */
    public function myRequests(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = Cache::remember("wallet.requests.{$userId}", 120, function () use ($userId) {
            return WalletRequest::where('user_id', $userId)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn($r) => $this->formatRequest($r))
                ->values()
                ->all();
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    // ── GET /api/wallet/requests/{id} ────────────────────────────────────────

    /**
     * A single wallet request belonging to the authenticated user.
     */
    public function show(int $id, Request $request): JsonResponse
    {
        $walletRequest = WalletRequest::where('user_id', $request->user()->id)->find($id);

        if (!$walletRequest) {
            return response()->json(['success' => false, 'message' => 'Wallet request not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->formatRequest($walletRequest),
        ]);
    }

    // ── DELETE /api/wallet/requests/{id} ─────────────────────────────────────

    /**
     * Cancels the authenticated user's own request — only while it is still
     * pending; once an admin has approved/rejected it, it is immutable.
     */
    public function destroy(int $id, Request $request): JsonResponse
    {
        $user          = $request->user();
        $walletRequest = WalletRequest::where('user_id', $user->id)->find($id);

        if (!$walletRequest) {
            return response()->json(['success' => false, 'message' => 'Wallet request not found.'], 404);
        }

        if ($walletRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => "This request has already been {$walletRequest->status} and can no longer be cancelled.",
            ], 422);
        }

        $walletRequest->delete();

        Cache::forget("wallet.requests.{$user->id}");

        return response()->json([
            'success' => true,
            'message' => 'Wallet request cancelled.',
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function formatRequest(WalletRequest $r): array
    {
        return [
            'id'           => $r->id,
            'type'         => $r->type,
            'amount'       => (float) $r->amount,
            'status'       => $r->status,
            'user_notes'   => $r->user_notes,
            'admin_notes'  => $r->admin_notes,
            'processed_at' => $r->processed_at?->toIso8601String(),
            'created_at'   => $r->created_at->toIso8601String(),
        ];
    }
}
