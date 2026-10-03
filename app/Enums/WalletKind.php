<?php

namespace App\Enums;

/**
 * Decision un3 (owner, 2026-10-02), foundation slice: what a wallet IS.
 *
 * Before this, a wallet's role was inferred from `user_id IS NULL`. That made "is this the platform
 * escrow sink?" a question about data rather than about type, and it forced
 * `WalletTransactionService::lockWalletByPhone` into a defensive filter + re-check (the RV-21 hijack
 * defence): a user who registered on the platform's configured phone could otherwise have owned the
 * wallet that receives every escrow, refund and platform fee.
 *
 * `kind` states the role. The values are intentionally FEW: the platform has two system wallets with
 * different jobs (SyCash holds escrow in flight, Primary Admin holds earnings), and adding more
 * roles here is a product decision, not a refactoring detail. What the type buys today is that the
 * escrow lookup can be ASSERTED rather than inferred.
 *
 * Kept in sync with `wallets.kind` (VARCHAR(16)) by migration 2026_10_03_234000.
 */
enum WalletKind: string
{
    /** Belongs to a person (or, defensively, any row that is not one of the two platform wallets). */
    case USER = 'user';

    /**
     * Platform-owned: the SyCash escrow wallet or the Primary Admin earnings wallet.
     *
     * NOT the same as "system account" in the staff sense - this is about money, not about who may
     * log into the staff panel.
     */
    case SYSTEM = 'system';

    /** Only platform wallets may receive escrow, refunds and platform fees. */
    public function isSystem(): bool
    {
        return $this === self::SYSTEM;
    }
}
