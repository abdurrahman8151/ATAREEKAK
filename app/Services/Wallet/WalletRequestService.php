<?php

namespace App\Services\Wallet;

use App\Exceptions\Domain\BusinessRuleViolation;
use App\Exceptions\Domain\ConflictViolation;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRequest;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletRequestService
{
    // RV-13 (R2 sec 68): the seven `\DomainException` throws below carried the HTTP status in
    // `$code`, and `WalletRequestController` read it back with `$e->getCode() ?: 422`. An exception
    // code is NOT an HTTP status: it is an arbitrary int that PHP defaults to 0, so a throw that
    // forgot the code silently became 422, and a throw that used a non-HTTP code would hand that
    // number straight to `response()->json($payload, $status)`. The status belongs to the exception
    // TYPE, which is what `App\Exceptions\Domain\*` exists for.
    //
    // EVERY STATUS HERE IS UNCHANGED (422 / 409), because `WalletRequestControllerTest` pins them and
    // changing an endpoint's answer is not this task's business - only the CHANNEL is fixed. The
    // one 409 stays a conflict because "you already have a pending charge request" really is a
    // state conflict, and a client can act on that by waiting.

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function requestCharge(User $user, float $amount, ?string $notes = null): WalletRequest
    {
        $user->loadMissing('wallet');

        if (! $user->wallet) {
            throw new BusinessRuleViolation(
                'You do not have a wallet yet. Please create one first.',
                'WALLET_NOT_FOUND'
            );
        }

        $exists = WalletRequest::where('user_id', $user->id)
            ->where('type', 'charge')
            ->where('status', 'pending')
            ->exists();

        if ($exists) {
            throw new ConflictViolation(
                'You already have a pending charge request. Please wait for it to be reviewed.',
                'PENDING_CHARGE_EXISTS'
            );
        }

        return DB::transaction(function () use ($user, $amount, $notes) {
            $walletRequest = WalletRequest::create([
                'user_id' => $user->id,
                'wallet_id' => $user->wallet->id,
                'type' => 'charge',
                'amount' => $amount,
                'status' => 'pending',
                'user_notes' => $notes,
            ]);

            $this->notify($user, $walletRequest, 'charge_request_received', 'تم استلام طلب الشحن', 'سيتم مراجعة طلب شحن المحفظة من قِبل الإدارة قريباً.');
            Cache::forget($this->cacheKey($user->id));

            return $walletRequest;
        });
    }

    public function requestWithdraw(User $user, float $amount, ?string $notes = null): WalletRequest
    {
        $user->loadMissing('wallet');

        if (! $user->wallet) {
            throw new BusinessRuleViolation('You do not have a wallet yet.', 'WALLET_NOT_FOUND');
        }

        // T3-15: this used to read the balance and the pending total OUTSIDE any
        // transaction and with no lock, then insert. Two concurrent
        // POST /api/wallet/request-withdraw calls could both evaluate
        // `pendingTotal + amount <= balance` as true before either inserted, and
        // together over-commit the wallet. The check now happens inside the
        // transaction after locking the wallet row FOR UPDATE — the pattern
        // PassengerProfileController::chargeWallet() (:331) and
        // WalletTransactionService already use — so the second request blocks
        // until the first commits and then sees its pending amount. Idempotency
        // comes from locking, not from hoping nobody double-clicks.
        return DB::transaction(function () use ($user, $amount, $notes) {
            /** @var Wallet|null $wallet */
            $wallet = $user->wallet()->lockForUpdate()->first();

            if (! $wallet) {
                throw new BusinessRuleViolation('You do not have a wallet yet.', 'WALLET_NOT_FOUND');
            }

            $balance = (float) $wallet->balance;

            if ($amount > $balance) {
                throw new BusinessRuleViolation(
                    "Insufficient balance. Your current balance is {$wallet->balance} SYP.",
                    'INSUFFICIENT_BALANCE'
                );
            }

            $pendingTotal = (float) WalletRequest::where('user_id', $user->id)
                ->where('type', 'withdraw')
                ->where('status', 'pending')
                ->sum('amount');

            if (($pendingTotal + $amount) > $balance) {
                throw new BusinessRuleViolation(
                    "You already have pending withdraw requests totalling {$pendingTotal} SYP. This request would exceed your balance.",
                    'PENDING_WITHDRAWALS_EXCEED_BALANCE'
                );
            }

            $walletRequest = WalletRequest::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'type' => 'withdraw',
                'amount' => $amount,
                'status' => 'pending',
                'user_notes' => $notes,
            ]);

            $this->notify($user, $walletRequest, 'withdraw_request_received', 'تم استلام طلب السحب', 'سيتم مراجعة طلب سحب المحفظة من قِبل الإدارة قريباً.');
            Cache::forget($this->cacheKey($user->id));

            return $walletRequest;
        });
    }

    public function create(User $user, string $type, float $amount, ?string $notes = null): WalletRequest
    {
        return $type === 'withdraw'
            ? $this->requestWithdraw($user, $amount, $notes)
            : $this->requestCharge($user, $amount, $notes);
    }

    /** @return array<int, array> */
    public function listForUser(int $userId): array
    {
        return Cache::remember($this->cacheKey($userId), 120, function () use ($userId) {
            return WalletRequest::where('user_id', $userId)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($r) => $this->format($r))
                ->values()
                ->all();
        });
    }

    public function getForUser(int $userId, int $id): WalletRequest
    {
        $request = WalletRequest::where('user_id', $userId)->find($id);

        if (! $request) {
            throw new ModelNotFoundException('Request not found.');
        }

        return $request;
    }

    public function cancelForUser(int $userId, int $id): WalletRequest
    {
        $request = $this->getForUser($userId, $id);

        if (! $request->isPending()) {
            throw new BusinessRuleViolation('Only pending requests can be cancelled.', 'REQUEST_NOT_PENDING');
        }

        $request->update(['status' => 'cancelled']);
        Cache::forget($this->cacheKey($userId));

        return $request->fresh();
    }

    public function format(WalletRequest $r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'amount' => (float) $r->amount,
            'status' => $r->status,
            'user_notes' => $r->user_notes,
            'admin_notes' => $r->admin_notes,
            'processed_at' => $r->processed_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private function cacheKey(int $userId): string
    {
        return "wallet.requests.{$userId}";
    }

    private function notify(User $user, WalletRequest $walletRequest, string $type, string $title, string $body): void
    {
        try {
            $this->notifications->createNotification(
                $user,
                $type,
                $title,
                $body,
                ['wallet_request_id' => $walletRequest->id],
                'normal',
                'system'
            );
        } catch (\Throwable $e) {
            // T3-13: was silently swallowed. Non-fatal by intent (a failed
            // acknowledgement must not undo the persisted request), but the
            // failure must be visible to operations.
            Log::warning('wallet-request acknowledgement notification failed (non-fatal): '.$e->getMessage());
        }
    }
}
