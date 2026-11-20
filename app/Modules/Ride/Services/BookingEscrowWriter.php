<?php

namespace App\Modules\Ride\Services;

use App\DTOs\Money\BookingMoneySnapshot;
use App\Modules\Ride\Contracts\BookingEscrowLedger;
use App\Modules\Ride\Contracts\BookingView;
use App\Modules\Ride\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Stage 2 C2: Ride's implementation of the escrow guard, moved verbatim from Money\Services\WalletAccess.
 *
 * The SQL is unchanged on purpose - same table, same WHERE clause, same `DB::raw` arithmetic, same decimal
 * formatting (two decimals, no separator, no locale, no exponent), so the atomicity guarantee and the stored
 * values are identical to what Money wrote before. This is a change of *owner*, not of behaviour.
 */
final class BookingEscrowWriter implements BookingEscrowLedger
{
    public function conditionallyDebit(int $bookingId, float $amount): bool
    {
        $sqlAmount = $this->sqlAmount($amount);

        $affected = DB::table('bookings')
            ->where('id', $bookingId)
            ->where('escrow_held', '>=', $sqlAmount)
            ->update([
                'escrow_held' => DB::raw('escrow_held - '.$sqlAmount),
            ]);

        return $affected === 1;
    }

    public function heldFor(int $bookingId): float
    {
        return (float) (DB::table('bookings')->where('id', $bookingId)->value('escrow_held') ?? 0);
    }

    /**
     * Format an amount for safe interpolation into raw SQL.
     *
     * `escrow_held` is decimal(15,2), so anything else could round differently from the stored value or
     * arrive as `1.0E+3` - valid PHP string output, invalid arithmetic here. The assertion is belt and
     * braces: the format is fixed, and a value that somehow is not a plain decimal must fail loudly rather
     * than reach the SQL.
     */
    private function sqlAmount(float $amount): string
    {
        $formatted = number_format($amount, 2, '.', '');

        if (preg_match('/^-?\d+\.\d{2}$/', $formatted) !== 1) {
            throw new \InvalidArgumentException("Refusing to interpolate a non-decimal amount: {$formatted}");
        }

        return $formatted;
    }

    /**
     * Stage 4 A2: the owner persists the money snapshot Money computed for a charged booking.
     *
     * One UPDATE, on the model the caller already holds, exactly as the retired force-fill did.
     */
    public function recordMoneySnapshot(BookingView $booking, BookingMoneySnapshot $snapshot): void
    {
        if (! $booking instanceof Booking) {
            throw new \InvalidArgumentException(
                'recordMoneySnapshot expects a Booking; got '.get_debug_type($booking).'.'
            );
        }

        $booking->forceFill([
            'unit_price' => $snapshot->unitPrice,
            'amount_paid' => $snapshot->amountPaid,
            'payment_method' => $snapshot->paymentMethod,
            'escrow_held' => $snapshot->escrowHeld,
        ])->save();
    }
}
