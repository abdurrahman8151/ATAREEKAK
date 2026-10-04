<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\WalletRequest;
use App\Models\WalletTransaction;
use App\Services\NotificationService;
use App\Services\Payment\CashRideFeeService;
use App\Services\Payment\LedgerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * AdminWalletRequestController
 *
 * Admin endpoints for reviewing and acting on wallet charge/withdraw requests.
 *
 * Routes (all behind `staff:admin` / `staff:system_admin` middleware):
 *   GET   /api/admin/wallet/requests              â†’ index()
 *   POST  /api/admin/wallet/requests/{id}/approve â†’ approve()
 *   POST  /api/admin/wallet/requests/{id}/reject  â†’ reject()
 *
 * â”€â”€ Fixes applied â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 *  1. Withdrawal WalletTransaction amount stored as -$amount (outflow convention).
 *  2. reject() now eager-loads user + wallet so formatRequest() has no N+1.
 *  3. autoClearDebt wrapped in its own DB::transaction() so lockForUpdate()
 *     inside it actually holds a row lock.
 */
final class AdminWalletRequestController extends Controller
{
    public function __construct(
        private readonly CashRideFeeService $cashRideFeeService,
    ) {}

    // â”€â”€ GET /api/admin/wallet/requests â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|in:pending,approved,rejected,cancelled',
            'type' => 'sometimes|in:charge,withdraw',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $query = WalletRequest::with([
            'user:id,first_name,last_name,email',
            'wallet:id,wallet_number,phone_number,balance,cash_ride_debt',
        ])->orderByDesc('created_at');

        $status = $request->get('status', 'pending');
        $query->where('status', $status);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $paginator = $query->paginate(
            (int) $request->get('per_page', 15),
            ['*'],
            'page',
            (int) $request->get('page', 1)
        );

        // 1 GROUP BY query instead of 3 separate COUNTs
        $countRows = WalletRequest::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [
            'pending' => (int) ($countRows['pending'] ?? 0),
            'approved' => (int) ($countRows['approved'] ?? 0),
            'rejected' => (int) ($countRows['rejected'] ?? 0),
            'cancelled' => (int) ($countRows['cancelled'] ?? 0),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $paginator->getCollection()
                ->map(fn ($r) => $this->formatRequest($r))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'counts' => $counts,
        ]);
    }

    // â”€â”€ POST /api/admin/wallet/requests/{id}/approve â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function approve(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $walletRequest = WalletRequest::with('wallet')->findOrFail($id);

            if (! $walletRequest->isPending()) {
                return response()->json([
                    'status' => 'error',
                    'message' => "This request has already been {$walletRequest->status}.",
                ], 422);
            }

            DB::transaction(function () use ($walletRequest, $request) {
                $wallet = Wallet::lockForUpdate()->findOrFail($walletRequest->wallet_id);
                $amount = (float) $walletRequest->amount;

                if ($walletRequest->isWithdraw()) {
                    if ($amount > (float) $wallet->balance) {
                        throw new \DomainException(
                            "Insufficient wallet balance ({$wallet->balance} SYP) to process withdrawal of {$amount} SYP."
                        );
                    }
                    $previousBalance = (float) $wallet->balance;
                    $newBalance = $previousBalance - $amount;
                    $transactionType = 'withdrawal';
                    // FIX 1: withdrawal is an outflow â€” store as negative to match
                    //         the convention used everywhere else in the codebase.
                    $transactionAmount = -$amount;
                    $description = 'Withdrawal processed by admin';
                } else {
                    $previousBalance = (float) $wallet->balance;
                    $newBalance = $previousBalance + $amount;
                    $transactionType = 'admin_charge';
                    $transactionAmount = $amount;
                    $description = 'Balance topped up by admin';
                }

                $wallet->balance = $newBalance;
                $wallet->save();

                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $walletRequest->user_id,
                    'type' => $transactionType,
                    'amount' => $transactionAmount,
                    'previous_balance' => $previousBalance,
                    'new_balance' => $newBalance,
                    'description' => $description,
                    // T3-1: was 'WR-'.$walletRequest->id.'-'.now()->timestamp.
                    // Honest note: because the request id is itself unique, this
                    // generator could not actually collide â€” the real collision
                    // was in PassengerProfileController::chargeWallet() where the
                    // id was 'ADM-'.$user->id.'-'.timestamp (same passenger, same
                    // second, UNIQUE transaction_id â†’ 500 + rollback). Normalised
                    // to UUID anyway so both money paths share one collision-free
                    // scheme; the readable prefix is kept for ops.
                    'transaction_id' => 'WR-'.$walletRequest->id.'-'.(string) Str::uuid(),
                    'status' => 'completed',
                    'reference' => 'wallet_request:'.$walletRequest->id,
                ]);

                // â”€â”€ Decision un3 (owner choice (a)): external flow â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
                // A top-up is money ARRIVING from outside; a withdrawal is money LEAVING to outside.
                // Both are recorded against the External Capital account, which is what closes the
                // ledger. `$transactionAmount`'s sign already encodes the direction, so the inbound
                // flag is derived from it rather than re-decided here.
                $external = Wallet::where('phone_number', config('admin.external.phone'))
                    ->where('kind', 'system')
                    ->whereNull('user_id')
                    ->lockForUpdate()
                    ->first();

                if ($external === null) {
                    throw new \RuntimeException(
                        'External Capital wallet not found for phone: '.config('admin.external.phone')
                        .'. Run: php artisan db:seed --class=SystemWalletSeeder'
                    );
                }

                $externalPrev = (float) $external->balance;
                // A positive wallet movement means money arrived, so it LEFT the external account.
                $externalDelta = -1 * (float) $transactionAmount;
                $external->balance += $externalDelta;
                $external->save();

                WalletTransaction::create([
                    'wallet_id' => $external->id,
                    'user_id' => null,
                    'type' => $transactionAmount > 0 ? 'external_inbound' : 'external_outbound',
                    'amount' => $externalDelta,
                    'previous_balance' => $externalPrev,
                    'new_balance' => (float) $external->balance,
                    'description' => ($transactionAmount > 0 ? 'Injected' : 'Paid out')
                        ." for wallet request #{$walletRequest->id}",
                    'transaction_id' => 'EXT-WR-'.$walletRequest->id.'-'.(string) Str::uuid(),
                    'status' => 'completed',
                    'reference' => 'wallet_request:'.$walletRequest->id,
                ]);

                app(LedgerService::class)->postExternalTransfer(
                    $external,
                    $wallet,
                    abs((float) $transactionAmount),
                    inbound: $transactionAmount > 0,
                    description: "wallet request #{$walletRequest->id}",
                );

                $walletRequest->update([
                    'status' => 'approved',
                    'admin_notes' => $request->input('admin_notes'),
                    'processed_by' => $request->user()?->id,
                    'processed_at' => now(),
                ]);

                Log::info('Wallet request approved', [
                    'request_id' => $walletRequest->id,
                    'type' => $walletRequest->type,
                    'amount' => $amount,
                    'user_id' => $walletRequest->user_id,
                    'previous_balance' => $previousBalance,
                    'new_balance' => $newBalance,
                ]);
            });

            $walletRequest->refresh()->load([
                'user:id,first_name,last_name,email',
                'wallet:id,wallet_number,phone_number,balance,cash_ride_debt',
            ]);

            Cache::forget("wallet.requests.{$walletRequest->user_id}");

            // â”€â”€ Auto-clear cash ride debt after a top-up â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            // Only for charges; withdrawals reduce the balance so debt clearing
            // would immediately fail the balance >= debt check anyway.
            // FIX 3: wrapped in its own DB::transaction() so that the
            //         lockForUpdate() inside autoClearDebt is actually effective.
            if ($walletRequest->isCharge() && $walletRequest->user && $walletRequest->wallet) {
                try {
                    DB::transaction(function () use ($walletRequest) {
                        $this->cashRideFeeService->autoClearDebt(
                            $walletRequest->wallet->fresh(),
                            $walletRequest->user
                        );
                    });
                } catch (\Throwable $e) {
                    // Debt clearing failure must never block the approval response.
                    Log::error('Auto debt clear failed after wallet charge', [
                        'wallet_request_id' => $walletRequest->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // â”€â”€ Notify user â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            try {
                $label = $walletRequest->isCharge() ? 'Wallet Charge' : 'Wallet Withdrawal';
                $msg = $walletRequest->isCharge()
                    ? "Your wallet charge request of {$walletRequest->amount} SYP has been approved."
                    : "Your withdrawal request of {$walletRequest->amount} SYP has been approved.";

                app(NotificationService::class)->createNotification(
                    $walletRequest->user,
                    'wallet_request_approved',
                    $label.' - Ù…ÙˆØ§ÙÙ‚',
                    $msg,
                    ['wallet_request_id' => $walletRequest->id],
                    'high',
                    'system'
                );
            } catch (\Throwable $e) {
                Log::warning('wallet-request decision notification failed (non-fatal): '.$e->getMessage());
            }

            return response()->json([
                'status' => 'success',
                'message' => ucfirst($walletRequest->type).' request approved. Wallet balance updated.',
                'data' => $this->formatRequest($walletRequest),
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Request not found.'], 404);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Wallet request approval failed', ['id' => $id, 'error' => $e->getMessage()]);

            return $this->serverError();
        }
    }

    // â”€â”€ POST /api/admin/wallet/requests/{id}/reject â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function reject(int $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            // FIX 2: eager-load relationships so formatRequest() and the
            //         notification call below have no lazy-load N+1 queries.
            $walletRequest = WalletRequest::with([
                'user:id,first_name,last_name,email',
                'wallet:id,wallet_number,phone_number,balance,cash_ride_debt',
            ])->findOrFail($id);

            if (! $walletRequest->isPending()) {
                return response()->json([
                    'status' => 'error',
                    'message' => "This request has already been {$walletRequest->status}.",
                ], 422);
            }

            $walletRequest->update([
                'status' => 'rejected',
                'admin_notes' => $request->input('admin_notes'),
                'processed_by' => $request->user()?->id,
                'processed_at' => now(),
            ]);

            Cache::forget("wallet.requests.{$walletRequest->user_id}");

            Log::info('Wallet request rejected', [
                'request_id' => $walletRequest->id,
                'type' => $walletRequest->type,
                'amount' => $walletRequest->amount,
                'user_id' => $walletRequest->user_id,
            ]);

            try {
                $label = $walletRequest->isCharge() ? 'Wallet Charge' : 'Wallet Withdrawal';
                $reason = $request->input('admin_notes') ? ' Reason: '.$request->input('admin_notes') : '';

                app(NotificationService::class)->createNotification(
                    $walletRequest->user,
                    'wallet_request_rejected',
                    $label.' - Rejected',
                    "Your request for {$walletRequest->amount} SYP has been rejected.{$reason}",
                    ['wallet_request_id' => $walletRequest->id],
                    'normal',
                    'system'
                );
            } catch (\Throwable $e) {
                Log::warning('wallet-request decision notification failed (non-fatal): '.$e->getMessage());
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Request rejected.',
                'data' => $this->formatRequest($walletRequest),
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Request not found.'], 404);
        } catch (\Exception $e) {
            Log::error('Wallet request rejection failed', ['id' => $id, 'error' => $e->getMessage()]);

            return $this->serverError();
        }
    }

    // â”€â”€ Private â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    private function formatRequest(WalletRequest $r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'amount' => (float) $r->amount,
            'status' => $r->status,
            'user_notes' => $r->user_notes,
            'admin_notes' => $r->admin_notes,
            'processed_at' => $r->processed_at?->toIso8601String(),
            'created_at' => $r->created_at->toIso8601String(),
            'user' => $r->user ? [
                'id' => $r->user->id,
                'name' => trim("{$r->user->first_name} {$r->user->last_name}"),
                'email' => $r->user->email,
            ] : null,
            'wallet' => $r->wallet ? [
                'id' => $r->wallet->id,
                'wallet_number' => $r->wallet->wallet_number,
                'phone_number' => $r->wallet->phone_number,
                'current_balance' => (float) $r->wallet->balance,
                'cash_ride_debt' => (float) $r->wallet->cash_ride_debt,
            ] : null,
        ];
    }

    private function serverError(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }
}
