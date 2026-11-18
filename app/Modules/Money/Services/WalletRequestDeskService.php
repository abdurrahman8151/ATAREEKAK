<?php

namespace App\Modules\Money\Services;

use App\Enums\WalletKind;
use App\Models\User;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Money\Contracts\CashRideFeeServiceInterface;
use App\Modules\Money\Contracts\LedgerServiceInterface;
use App\Modules\Money\Contracts\WalletRequests;
use App\Modules\Money\Contracts\WalletTransactionRecorder;
use App\Modules\Money\DTOs\WalletRequestSummary;
use App\Modules\Money\DTOs\WalletSummary;
use App\Modules\Money\Models\Wallet;
use App\Modules\Money\Models\WalletRequest;
use App\Modules\Money\Models\WalletTransaction;
use App\Support\Logging\SafeExceptionLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stage 2 C2: the admin wallet-request desk, inside the module that owns `wallet_requests` and `wallets`.
 *
 * The approval body is the controller's private methods moved VERBATIM - same lock, same movement arithmetic,
 * same transaction ids and references, same External Capital mirror, same ledger call, same audit columns - so
 * the money semantics are untouched. What changed is who owns them: Console used to run this against Money's
 * models, which is exactly the cross-module write the ownership rules forbid.
 *
 * `autoClearDebtAfterCharge()` moved too: it needs the wallet ROW (`$wallet->fresh()`) and the payer, and Console
 * can no longer hold either. It still runs after the approval transaction, in its own transaction, and a failure
 * still only logs.
 */
final class WalletRequestDeskService implements WalletRequests
{
    public function __construct(
        private readonly LedgerServiceInterface $ledgerService,
        private readonly CashRideFeeServiceInterface $cashRideFeeService,
        private readonly NotificationPort $notifications,
    ) {}

    public function reviewPage(string $status, ?string $type, int $perPage, int $page): LengthAwarePaginator
    {
        $query = WalletRequest::with(['wallet'])->orderByDesc('created_at');

        $query->where('status', $status);

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (WalletRequest $request) => $this->toSummary($request));
    }

    public function statusCounts(): array
    {
        $countRows = WalletRequest::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($countRows['pending'] ?? 0),
            'approved' => (int) ($countRows['approved'] ?? 0),
            'rejected' => (int) ($countRows['rejected'] ?? 0),
            'cancelled' => (int) ($countRows['cancelled'] ?? 0),
        ];
    }

    public function summaryOrFail(int $id): WalletRequestSummary
    {
        return $this->toSummary(WalletRequest::with(['wallet'])->findOrFail($id));
    }

    public function approve(int $requestId, ?string $adminNotes, ?int $employeeId, ?int $adminUserId): WalletRequestSummary
    {
        DB::transaction(function () use ($requestId, $adminNotes, $employeeId, $adminUserId) {
            // No `with(['wallet'])` here: the transaction body uses the ROW-LOCKED wallet, and the summary
            // re-reads the row below with everything the response needs. The eager load was a query nobody read.
            $request = WalletRequest::findOrFail($requestId);

            if (! $request->isPending()) {
                // The controller's "already decided" 422, message and status code unchanged.
                throw new \DomainException("This request has already been {$request->status}.");
            }

            $wallet = Wallet::lockForUpdate()->findOrFail($request->wallet_id);
            $movement = $this->resolveApprovedMovement($request, $wallet);

            $wallet->balance = $movement['new_balance'];
            $wallet->save();

            $targetTransaction = $this->recordWalletMovement($request, $wallet, $movement);
            $this->settleExternalCapital($request, $wallet, $movement['transaction_amount'], $targetTransaction);
            $this->markRequestApproved($request, $adminNotes, $employeeId, $adminUserId);

            Log::info('Wallet request approved', [
                'request_id' => $request->id,
                'type' => $request->type,
                'amount' => $movement['amount'],
                'user_id' => $request->user_id,
                'previous_balance' => $movement['previous_balance'],
                'new_balance' => $movement['new_balance'],
            ]);
        });

        // ONE user read for the summary's requester fields, the debt clear AND the notification - the same
        // single eager load the old `refresh()->load(['user', ...])` paid.
        $fresh = WalletRequest::with(['user', 'wallet'])->findOrFail($requestId);
        $summary = $this->toSummary($fresh);
        $requester = $fresh->user;

        // Post-approval side effect: only for a charge, own transaction, never fatal (verbatim semantics).
        $this->autoClearDebtAfterCharge($summary, $requester);

        // Stage 2 C2: the decision notification moved here WITH the decision - it is addressed to the
        // requesting user, and Console would otherwise load a `User` row only to hand it to the port
        // (`controllers_to_models` is a 0 baseline). Texts, channel and priority are unchanged.
        $label = $summary->isCharge() ? 'Wallet Charge' : 'Wallet Withdrawal';
        $this->notify(
            $summary,
            'wallet_request_approved',
            $label.' - موافق',
            $summary->isCharge()
                ? "Your wallet charge request of {$summary->amount} SYP has been approved."
                : "Your withdrawal request of {$summary->amount} SYP has been approved.",
            'high',
            $requester,
        );

        return $summary;
    }

    public function reject(int $requestId, ?string $adminNotes, ?int $employeeId, ?int $adminUserId): WalletRequestSummary
    {
        $request = WalletRequest::with(['user', 'wallet'])->findOrFail($requestId);

        if (! $request->isPending()) {
            throw new \DomainException("This request has already been {$request->status}.");
        }

        $request->update([
            'status' => 'rejected',
            'admin_notes' => $adminNotes,
            // T3-4: both columns are written on purpose - `processed_by` is the shadow `users` id kept for
            // history, `processed_by_employee_id` is the real actor.
            'processed_by_employee_id' => $employeeId,
            'processed_by' => $adminUserId,
            'processed_at' => now(),
        ]);

        Log::info('Wallet request rejected', [
            'request_id' => $request->id,
            'type' => $request->type,
            'amount' => $request->amount,
            'user_id' => $request->user_id,
        ]);

        // The instance already carries the update AND the eager-loaded user/wallet, so no refresh is needed -
        // the extra round trip would be a query nobody reads.
        $summary = $this->toSummary($request);

        $label = $summary->isCharge() ? 'Wallet Charge' : 'Wallet Withdrawal';
        $reason = $adminNotes ? ' Reason: '.$adminNotes : '';
        $this->notify(
            $summary,
            'wallet_request_rejected',
            $label.' - Rejected',
            "Your request for {$summary->amount} SYP has been rejected.{$reason}",
            'normal',
            $request->user,
        );

        return $summary;
    }

    /**
     * Stage 2 C2: the decision notification, moved from Console with the decision. Non-fatal by intent - a
     * failed notification must never undo a decision that is already persisted, so it only logs.
     */
    private function notify(
        WalletRequestSummary $request,
        string $type,
        string $title,
        string $body,
        string $priority,
        ?User $user = null,
    ): void {
        try {
            if ($request->userId === null) {
                return;
            }

            $user ??= User::find($request->userId);
            if (! $user) {
                return;
            }

            $this->notifications->createNotification(
                $user,
                $type,
                $title,
                $body,
                ['wallet_request_id' => $request->id],
                $priority,
                'system'
            );
        } catch (\Throwable $e) {
            Log::warning('wallet-request decision notification failed (non-fatal): '.SafeExceptionLog::message($e));
        }
    }

    // =========================================================================
    // APPROVAL BODY (moved verbatim from Console's controller)
    // =========================================================================

    /**
     * The balance movement for one approved request: the new balance plus the ledger-leg values
     * the wallet transaction row is written with. A withdrawal above the balance throws.
     *
     * @return array{amount: float, previous_balance: float, new_balance: float, type: string, transaction_amount: float, description: string}
     */
    private function resolveApprovedMovement(WalletRequest $request, Wallet $wallet): array
    {
        $amount = (float) $request->amount;

        if ($request->isWithdraw()) {
            if ($amount > (float) $wallet->balance) {
                throw new \DomainException(
                    "Insufficient wallet balance ({$wallet->balance} SYP) to process withdrawal of {$amount} SYP."
                );
            }
            $previousBalance = (float) $wallet->balance;
            $newBalance = $previousBalance - $amount;
            $transactionType = 'withdrawal';
            // FIX 1: withdrawal is an outflow - stored negative to match the codebase convention.
            $transactionAmount = -$amount;
            $description = 'Withdrawal processed by admin';
        } else {
            $previousBalance = (float) $wallet->balance;
            $newBalance = $previousBalance + $amount;
            $transactionType = 'admin_charge';
            $transactionAmount = $amount;
            $description = 'Balance topped up by admin';
        }

        return [
            'amount' => $amount,
            'previous_balance' => $previousBalance,
            'new_balance' => $newBalance,
            'type' => $transactionType,
            'transaction_amount' => $transactionAmount,
            'description' => $description,
        ];
    }

    /**
     * Stage 2 B2: returns the row it wrote, so the ledger legs can name it.
     *
     * @param  array{previous_balance: float, new_balance: float, type: string, transaction_amount: float, description: string}  $movement
     */
    private function recordWalletMovement(WalletRequest $request, Wallet $wallet, array $movement): WalletTransaction
    {
        return app(WalletTransactionRecorder::class)->record([
            'wallet_id' => $wallet->id,
            'user_id' => $request->user_id,
            'type' => $movement['type'],
            'amount' => $movement['transaction_amount'],
            'previous_balance' => $movement['previous_balance'],
            'new_balance' => $movement['new_balance'],
            'description' => $movement['description'],
            // T3-1: UUID so both money paths share one collision-free scheme; the prefix is kept for ops.
            'transaction_id' => 'WR-'.$request->id.'-'.(string) Str::uuid(),
            'status' => 'completed',
            'reference' => 'wallet_request:'.$request->id,
        ]);
    }

    /**
     * Mirror the movement against the External Capital account and close it on the ledger.
     */
    private function settleExternalCapital(
        WalletRequest $request,
        Wallet $wallet,
        float $transactionAmount,
        WalletTransaction $targetTransaction,
    ): void {
        // Decision un3 (owner choice (a)): external flow. A top-up is money ARRIVING from outside; a
        // withdrawal is money LEAVING. `$transactionAmount`'s sign already encodes the direction, so the
        // inbound flag is derived from it rather than re-decided here.
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
        $externalDelta = -1 * $transactionAmount;
        $external->balance += $externalDelta;
        $external->save();

        $externalTransaction = app(WalletTransactionRecorder::class)->record([
            'wallet_id' => $external->id,
            'user_id' => null,
            'type' => $transactionAmount > 0 ? 'external_inbound' : 'external_outbound',
            'amount' => $externalDelta,
            'previous_balance' => $externalPrev,
            'new_balance' => (float) $external->balance,
            'description' => ($transactionAmount > 0 ? 'Injected' : 'Paid out')
                ." for wallet request #{$request->id}",
            'transaction_id' => 'EXT-WR-'.$request->id.'-'.(string) Str::uuid(),
            'status' => 'completed',
            'reference' => 'wallet_request:'.$request->id,
        ]);

        $this->ledgerService->postExternalTransfer(
            $external,
            $wallet,
            abs($transactionAmount),
            inbound: $transactionAmount > 0,
            externalTransaction: $externalTransaction,
            targetTransaction: $targetTransaction,
            description: "wallet request #{$request->id}",
        );
    }

    /** Persist the approval decision and its audit columns on the request row. */
    private function markRequestApproved(
        WalletRequest $request,
        ?string $adminNotes,
        ?int $employeeId,
        ?int $adminUserId,
    ): void {
        $request->update([
            'status' => 'approved',
            'admin_notes' => $adminNotes,
            'processed_by_employee_id' => $employeeId,
            'processed_by' => $adminUserId,
            'processed_at' => now(),
        ]);
    }

    /**
     * Auto-clear cash ride debt after a top-up. Only for charges; withdrawals reduce the balance, so debt
     * clearing would fail the balance >= debt check anyway. Wrapped in its own transaction so the
     * lockForUpdate() inside autoClearDebt is effective. A failure here never blocks the approval.
     */
    private function autoClearDebtAfterCharge(WalletRequestSummary $request, ?User $user): void
    {
        if (! ($request->isCharge() && $user !== null && $request->wallet !== null)) {
            return;
        }

        try {
            DB::transaction(function () use ($request, $user) {
                $this->cashRideFeeService->autoClearDebt(
                    Wallet::findOrFail($request->wallet->id),
                    $user,
                );
            });
        } catch (\Throwable $e) {
            Log::error('Auto debt clear failed after wallet charge', [
                'wallet_request_id' => $request->id,
                'error' => SafeExceptionLog::message($e),
            ]);
        }
    }

    private function toSummary(WalletRequest $request): WalletRequestSummary
    {
        $wallet = $request->relationLoaded('wallet') ? $request->wallet : null;
        $requester = $request->relationLoaded('user') ? $request->user : null;

        return new WalletRequestSummary(
            id: (int) $request->id,
            type: (string) $request->type,
            amount: $request->amount,
            status: (string) $request->status,
            userNotes: $request->user_notes,
            adminNotes: $request->admin_notes,
            processedAt: $request->processed_at,
            createdAt: $request->created_at,
            userId: $request->user_id === null ? null : (int) $request->user_id,
            wallet: $wallet === null ? null : new WalletSummary(
                id: (int) $wallet->id,
                userId: $wallet->user_id === null ? null : (int) $wallet->user_id,
                walletNumber: (string) $wallet->wallet_number,
                balance: $wallet->balance,
                cashRideDebt: $wallet->cash_ride_debt,
                name: $wallet->name,
                phoneNumber: $wallet->phone_number,
                kind: $wallet->kind instanceof WalletKind ? $wallet->kind->value : ($wallet->kind === null ? null : (string) $wallet->kind),
                createdAt: $wallet->created_at,
                updatedAt: $wallet->updated_at,
            ),
            requesterName: $requester === null
                ? null
                : trim(($requester->first_name ?? '').' '.($requester->last_name ?? '')),
            requesterEmail: $requester?->email,
        );
    }
}
