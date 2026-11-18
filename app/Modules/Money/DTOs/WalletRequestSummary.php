<?php

namespace App\Modules\Money\DTOs;

use Carbon\Carbon;

/**
 * Stage 2 C2: what another module may know about a wallet request.
 *
 * The admin review screen renders exactly these scalars - the request's own columns plus the wallet it moves
 * money on - so the DTO publishes them and nothing else. `status`/`type` are passed through as the column held
 * them (the model casts them), and the property names keep the response's spelling (`user_notes`,
 * `admin_notes`, `processed_at`) because the caller is building a payload that already reads those keys.
 *
 * The wallet is a nested `WalletSummary`: a request whose wallet row is gone still renders (`$wallet === null`),
 * which is what the retired eager-loaded relation's null produced.
 */
final class WalletRequestSummary
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly mixed $amount,
        public readonly string $status,
        public readonly ?string $userNotes,
        public readonly ?string $adminNotes,
        public readonly ?Carbon $processedAt,
        public readonly ?Carbon $createdAt,
        public readonly ?int $userId,
        public readonly ?WalletSummary $wallet,
        /**
         * Stage 2 C2: the requester's display fields, taken from the `users` row this DTO's producer already
         * loads (the decision notification needs the principal anyway), so the admin response does not have to
         * read it a second time. NULL when the user row is gone - the same null the retired eager load gave.
         */
        public readonly ?string $requesterName = null,
        public readonly ?string $requesterEmail = null,
    ) {}

    /** The request's own words for the decision responses and notifications. */
    public function isCharge(): bool
    {
        return $this->type === 'charge';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
