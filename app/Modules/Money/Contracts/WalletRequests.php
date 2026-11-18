<?php

namespace App\Modules\Money\Contracts;

use App\Modules\Money\DTOs\WalletRequestSummary;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Stage 2 C2: the admin wallet-request desk, owned by MONEY.
 *
 * Two things live behind this contract, and they live here for the same reason: a wallet request is a Money row,
 * and deciding it MOVES MONEY. The approval body (wallet row lock, movement, wallet transaction, the External
 * Capital mirror, the ledger legs and the status write) used to sit in Console's controller and write Money's
 * models directly - the one shape a reader contract cannot fix, because a write is not a read. Now Console keeps
 * the HTTP envelope, the validation, the notifications and the row formatting, and asks here.
 *
 * `reviewPage()`/`statusCounts()` are the same ownership applied to reads, so the review screen stops naming the
 * model, and `summaryOrFail()` keeps "missing means 404" a Money decision.
 */
interface WalletRequests
{
    /**
     * The review list, filtered exactly as the screen filtered it, as summaries.
     *
     * @return LengthAwarePaginator<int, WalletRequestSummary>
     */
    public function reviewPage(string $status, ?string $type, int $perPage, int $page): LengthAwarePaginator;

    /** @return array{pending: int, approved: int, rejected: int, cancelled: int} */
    public function statusCounts(): array;

    /** One request, or ModelNotFoundException - the caller's 404 stays exactly as it was. */
    public function summaryOrFail(int $id): WalletRequestSummary;

    /**
     * Approve a PENDING request: the whole money movement in one transaction.
     *
     * `$adminNotes` is the reviewer's note, `$employeeId` is the real actor (the staff employee), and
     * `$adminUserId` is the shadow `users` id kept for history - the two audit columns the old controller wrote.
     *
     * Throws `ModelNotFoundException` for an unknown id and `DomainException` for a refused movement (a
     * withdrawal above the balance), which is what the controller maps to 404 and 422.
     */
    public function approve(int $requestId, ?string $adminNotes, ?int $employeeId, ?int $adminUserId): WalletRequestSummary;

    /** Reject a PENDING request: the audit columns and the status write, nothing moves. */
    public function reject(int $requestId, ?string $adminNotes, ?int $employeeId, ?int $adminUserId): WalletRequestSummary;
}
