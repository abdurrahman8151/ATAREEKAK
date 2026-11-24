<?php

namespace App\Modules\Money\Services;

use App\Contracts\BookingView;
use App\Domain\ValueObjects\Money;
use App\Enums\WalletKind;
use App\Models\User;
use App\Modules\Money\Models\Wallet;
use App\Modules\Ride\Contracts\BookingEscrowLedger;
use Illuminate\Database\Eloquent\Collection;

/** Wallet lookup, row locking and the balance guard shared by the money paths. */
class WalletAccess
{
    /**
     * Stage 2 C2: Ride owns ookings, so the guarded escrow decrement lives there.
     *
     * Optional so the existing
ew WalletAccess call sites (tests that build the money paths by hand)
     * keep working; production resolves it from the container like every other port.
     */
    public function __construct(private ?BookingEscrowLedger $escrowLedger = null) {}

    private function escrowLedger(): BookingEscrowLedger
    {
        return $this->escrowLedger ??= app(BookingEscrowLedger::class);
    }

    /**
     * Format an amount for safe interpolation into raw SQL.
     *
     * Two decimals, no thousands separator, no locale, no exponent: `escrow_held` is
     * decimal(15,2), so anything else could round differently from the stored value or arrive as
     * `1.0E+3` — which is valid PHP string output and invalid arithmetic here.
     */
    public function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Build the deterministic posting key for one movement.
     *
     * A thin delegate to {@see PostingKey} rather than a second implementation: a guard that
     * exists twice is a guard that drifts, and this one is the difference between paying once and
     * paying twice.
     */
    public function postingKey(string ...$segments): string
    {
        return PostingKey::build(...$segments);
    }

    /**
     * Guard 1 — refuse a movement whose posting key is already recorded.
     *
     * Runs before any balance is written, so a replay aborts having moved nothing.
     */
    public function assertPostingKeyUnused(string $postingKey): void
    {
        PostingKey::assertUnused($postingKey);
    }

    /**
     * Guard 2 — take `amount` out of a booking's escrow, conditionally.
     *
     * The guard is in the WHERE clause, not a read-then-write: `escrow_held >= :amount` is
     * evaluated by the database as part of the UPDATE, so two concurrent settlements cannot both
     * see the pre-decrement value and both proceed. Exactly one row changed, or the caller aborts.
     *
     * @throws \RuntimeException when the booking does not hold that much (already settled, or
     *                           never charged at all — both are reasons not to pay).
     */
    public function debitEscrow(BookingView $booking, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        // Stage 2 C2: the conditional UPDATE and its refusal read both live behind Ride's port now.
        // Same statement, same guard, same two queries - the table's owner runs its own SQL.
        if (! $this->escrowLedger()->conditionallyDebit((int) $booking->id, $amount)) {
            $held = $this->escrowLedger()->heldFor((int) $booking->id);

            throw new \RuntimeException(
                "RV-02 L2: booking #{$booking->id} does not hold {$this->money($amount)} in escrow "
                ."(holds {$this->money($held)}). Refusing to settle: this booking's escrow has "
                .'already been spent, or it was never charged.'
            );
        }
    }

    /**
     * Guard 2 over a set of bookings, for a ride-level settlement.
     *
     * Each booking is decremented on its OWN row: one ride-level wallet movement can still cover
     * several bookings, and the escrow is per booking. Any booking short of its share aborts the
     * whole operation — a partial settlement is exactly the half-applied state this task removes.
     */
    public function debitEscrowForSet(Collection $bookings, callable $amountFor): void
    {
        foreach ($bookings as $booking) {
            $this->debitEscrow($booking, (float) $amountFor($booking));
        }
    }

    public function lockWalletByUserId(int $userId): Wallet
    {
        $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();

        if (! $wallet) {
            throw new \RuntimeException("Wallet not found for user ID: {$userId}");
        }

        return $wallet;
    }

    public function lockWalletByPhone(string $phone): Wallet
    {
        // RV-21: resolve the SYSTEM wallet by phone, and refuse any non-system row.
        // This lookup takes the "escrow sink" role in every money path, so it MUST
        // only ever return a platform-owned wallet (user_id IS NULL). It previously
        // matched on phone alone, so a user who registered the SyCash/Primary phone
        // (config defaults 0987654321 / 0912345678 are in the repo) BEFORE the
        // seeder ran would own the wallet that receives all escrow, refunds and
        // platform fees — i.e. money routed to a hijacker. Filtering to
        // user_id NULL and failing closed (rather than silently using a user-owned
        // row) makes that impossible, and cannot affect a correctly-seeded system
        // wallet. The seeder's own firstOrCreate(['phone_number'=>…]) is the second
        // half of this (it should not adopt a claimed phone); that belongs with the
        // wallets.kind refactor in §26.4, not this boundary fix.
        //
        // Decision un3: `kind = system` is now the PRIMARY condition and `user_id IS NULL` is kept
        // as a second, independent one. Keeping both means this defence no longer rests on a single
        // column: a `kind = system` row that somehow acquired a user_id would still be refused here
        // rather than becoming the escrow sink.
        $wallet = Wallet::where('phone_number', $phone)
            ->where('kind', WalletKind::SYSTEM->value)
            ->whereNull('user_id')
            ->lockForUpdate()
            ->first();

        if (! $wallet) {
            throw new \RuntimeException(
                "System wallet not found for phone: {$phone}. Either it is not seeded ".
                '(run: php artisan db:seed --class=SystemWalletSeeder) or a user-owned '.
                'wallet has claimed that phone number, which is rejected here so escrow '.
                'is never routed to a normal account.'
            );
        }

        return $wallet;
    }

    public function assertSufficientBalance(Wallet $wallet, float $required, string $message): void
    {
        if ($wallet->balance < $required) {
            throw new \RuntimeException($message);
        }
    }
}
