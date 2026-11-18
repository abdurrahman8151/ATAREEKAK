<?php

namespace App\Modules\Console\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Money\Contracts\WalletRequests;
use App\Support\Logging\SafeExceptionLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * AdminWalletRequestController
 *
 * The review list and the approve/reject decisions for wallet charge/withdraw requests.
 *
 * ── Stage 2 C2: the WRITE moved into Money ───────────────────────────────────
 * The approval body used to live in this class: it locked the wallet row, moved the balance, wrote the wallet
 * transaction, mirrored the movement against External Capital, posted the ledger legs, marked the request
 * approved and auto-cleared cash-ride debt. Every one of those statements writes a Money table, which is the
 * one coupling a reader contract cannot fix, so the body now lives in `Money\Contracts\WalletRequests`
 * (`WalletRequestDeskService`) - VERBATIM, including the transaction ids, the references and the audit columns.
 *
 * What stayed here is what an HTTP layer owns: validation, the response envelope, the cache bust, the
 * notifications and the row formatting. Console keeps NO reference to a wallet/wallet-request model, and the
 * money movement is now a call, not a sequence the controller can get wrong.
 */
final class AdminWalletRequestController extends Controller
{
    public function __construct(
        private readonly WalletRequests $walletRequests,
        private readonly UserDirectory $users,
    ) {}

    // ── GET /api/admin/wallet/requests ──────────────────────────────────────
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

        $paginator = $this->walletRequests->reviewPage(
            status: $request->get('status', 'pending'),
            type: $request->filled('type') ? $request->input('type') : null,
            perPage: (int) $request->get('per_page', 15),
            page: (int) $request->get('page', 1),
        );

        $counts = $this->walletRequests->statusCounts();

        // Stage 2 C2: the requesters' names/emails are Identity's data - ONE batched directory read, the same
        // single query the retired `user:id,first_name,last_name,email` eager load cost.
        $requesters = $this->users->summariesFor(
            array_values(array_filter(array_map(fn ($row) => $row->userId, $paginator->getCollection()->all())))
        );

        return response()->json([
            'status' => 'success',
            'data' => $paginator->getCollection()
                ->map(fn ($r) => $this->formatRequest($r, $requesters[$r->userId] ?? null))
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

    // ── POST /api/admin/wallet/requests/{id}/approve ─────────────────────────
    public function approve(int $id, Request $request): JsonResponse
    {
        if ($invalid = $this->validateAdminNotes($request)) {
            return $invalid;
        }

        try {
            $summary = $this->walletRequests->approve(
                $id,
                $request->input('admin_notes'),
                $request->attributes->get('staffEmployee')?->id,
                $request->user()?->id,
            );

            if ($summary->userId !== null) {
                Cache::forget("wallet.requests.{$summary->userId}");
            }

            return response()->json([
                'status' => 'success',
                'message' => ucfirst($summary->type).' request approved. Wallet balance updated.',
                'data' => $this->formatRequest($summary),
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Request not found.'], 404);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Wallet request approval failed', ['id' => $id, 'error' => SafeExceptionLog::message($e)]);

            return $this->serverError();
        }
    }

    // ── POST /api/admin/wallet/requests/{id}/reject ──────────────────────────
    public function reject(int $id, Request $request): JsonResponse
    {
        if ($invalid = $this->validateAdminNotes($request)) {
            return $invalid;
        }

        try {
            $summary = $this->walletRequests->reject(
                $id,
                $request->input('admin_notes'),
                $request->attributes->get('staffEmployee')?->id,
                $request->user()?->id,
            );

            if ($summary->userId !== null) {
                Cache::forget("wallet.requests.{$summary->userId}");
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Request rejected.',
                'data' => $this->formatRequest($summary),
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Request not found.'], 404);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Wallet request rejection failed', ['id' => $id, 'error' => SafeExceptionLog::message($e)]);

            return $this->serverError();
        }
    }

    // ── Private ──────────────────────────────────────────────────────────────
    /** Validate the `admin_notes` payload shared by approve() and reject(). */
    private function validateAdminNotes(Request $request): ?JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'admin_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        return null;
    }

    /**
     * Stage 2 C2: the decision NOTIFICATIONS moved into Money with the decision itself
     * (`WalletRequestDeskService::approve()/reject()`), because they are addressed to the requesting user and
     * Console would otherwise have to load a `User` row just to hand it to the port - the import that tripped
     * `controllers_to_models` (baseline 0) the moment it was written. Money may name the kernel principal.
     */
    private function formatRequest($r, $requester = null): array
    {
        // The list supplies a batched directory summary for the page; a single decision carries the requester's
        // fields on the summary itself (Money loaded that row for the notification), so both paths agree.
        $name = $requester?->fullName() ?? $r->requesterName;
        $email = $requester?->email ?? $r->requesterEmail;

        return [
            'id' => $r->id,
            'type' => $r->type,
            'amount' => (float) $r->amount,
            'status' => $r->status,
            'user_notes' => $r->userNotes,
            'admin_notes' => $r->adminNotes,
            'processed_at' => $r->processedAt?->toIso8601String(),
            'created_at' => $r->createdAt->toIso8601String(),
            'user' => ($name !== null || $email !== null) ? [
                'id' => $r->userId,
                'name' => $name,
                'email' => $email,
            ] : null,
            'wallet' => $r->wallet ? [
                'id' => $r->wallet->id,
                'wallet_number' => $r->wallet->walletNumber,
                'phone_number' => $r->wallet->phoneNumber,
                'current_balance' => (float) $r->wallet->balance,
                'cash_ride_debt' => (float) $r->wallet->cashRideDebt,
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
