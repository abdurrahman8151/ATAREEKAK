<?php

namespace App\Services\Payment;

use App\Interfaces\PolicyRepositoryInterface;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WalletTransactionService
 *
 * Single source of truth for ALL money movements.
 *
 * ═══════════════════════════════════════════════════════
 *  MONEY FLOW
 * ═══════════════════════════════════════════════════════
 *
 *  BOOKING (e-pay):
 *    Passenger ──(100% of seats × price)──► SyCash (escrow)
 *
 *  RIDE COMPLETION:
 *    SyCash ──(driver %)──► Driver
 *    SyCash ──(platform %)──► Primary Admin
 *    (split is configurable — see PolicySetting::platform_profit_percentage)
 *
 *  DRIVER CANCELS RIDE:
 *    SyCash ──(100% per booking)──► each Passenger (full refund)
 *
 *  PASSENGER CANCELS (time-based, e-pay confirmed booking):
 *    SyCash ──(refund %)──► Passenger
 *    SyCash ──(rest   %)──► Driver
 *    Tiers (elapsed %):
 *      0–30%   → 100% passenger / 0%  driver
 *      30–50%  →  70% passenger / 30% driver
 *      50–70%  →  50% passenger / 50% driver
 *      70–100% →   0% passenger / 100% driver
 *
 *  PASSENGER NO-SHOW (e-pay):
 *    SyCash ──(driver %)──► Driver
 *    SyCash ──(platform %)──► Primary Admin
 *
 *  DRIVER NO-SHOW (e-pay):
 *    SyCash ──(100%)──► Passenger
 *
 *  CASH rides: no wallet movements at all (payment offline).
 *  PRIMARY ADMIN: only ever receives money at completion or no-show.
 *  NO creation fee is charged to the driver.
 * ═══════════════════════════════════════════════════════
 */
class WalletTransactionService
{
    public function __construct(
        private readonly PolicyRepositoryInterface $policySettings,
    ) {}

    // =========================================================================
    // BOOKING PAYMENT  (Passenger → SyCash)
    // =========================================================================

    /**
     * Charge passenger for an e-pay booking.
     * Full amount goes to SyCash (escrow) — driver receives nothing yet.
     *
     * Called when:
     *   - DIRECT booking confirmed
     *   - REQUEST booking accepted by driver
     */
    public function chargePassengerForBooking(Booking $booking, Ride $ride, User $passenger): void
    {
        $amount = $booking->seats * $ride->price_per_seat;

        $passengerWallet = $this->lockWalletByUserId($passenger->id);
        $syCashWallet    = $this->lockWalletByPhone(config('admin.sycash.phone'));

        $this->assertSufficientBalance(
            $passengerWallet,
            $amount,
            "Insufficient balance. Required: " . number_format($amount, 0) . " SYP. " .
            "Current: " . number_format($passengerWallet->balance, 0) . " SYP."
        );

        $passengerPrev = $passengerWallet->balance;
        $syCashPrev    = $syCashWallet->balance;

        $passengerWallet->balance -= $amount;
        $syCashWallet->balance    += $amount;

        $passengerWallet->save();
        $syCashWallet->save();

        $txId = 'RB_' . time() . '_' . Str::random(8);

        WalletTransaction::create([
            'wallet_id'        => $passengerWallet->id,
            'user_id'          => $passenger->id,
            'type'             => 'ride_booking_payment',
            'amount'           => -$amount,
            'previous_balance' => $passengerPrev,
            'new_balance'      => $passengerWallet->balance,
            'description'      => "Payment for {$booking->seats} seat(s): {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => $txId,
            'status'           => 'completed',
            'reference'        => "booking:{$booking->id}",
        ]);

        WalletTransaction::create([
            'wallet_id'        => $syCashWallet->id,
            'user_id'          => null,
            'type'             => 'escrow_received',
            'amount'           => $amount,
            'previous_balance' => $syCashPrev,
            'new_balance'      => $syCashWallet->balance,
            'description'      => "Escrow received — {$passenger->first_name} {$passenger->last_name}, {$booking->seats} seat(s)",
            'transaction_id'   => 'SYCASH_' . $txId,
            'status'           => 'completed',
            'reference'        => "booking:{$booking->id}",
        ]);

        Log::info('Passenger charged — escrow held in SyCash', [
            'booking_id'   => $booking->id,
            'passenger_id' => $passenger->id,
            'amount'       => $amount,
        ]);
    }

    // =========================================================================
    // RIDE COMPLETION  (SyCash → Driver 95% + Primary 5%)
    // =========================================================================

    /**
     * Release escrow after all parties confirm ride completion.
     *
     * SyCash → Driver  (driver %)
     * SyCash → Primary (platform %)
     */
    public function releaseEarningsToDriver(Ride $ride, Collection $confirmedBookings): void
    {
        $total = $confirmedBookings->sum(fn($b) => $b->seats * $ride->price_per_seat);

        if ($total <= 0) {
            Log::info('No e-pay bookings to release', ['ride_id' => $ride->id]);
            return;
        }

        $platformPercentage = $this->policySettings->getPlatformProfitPercentage();
        $driverPercentage   = 100 - $platformPercentage;
        $primaryShare       = round($total * $platformPercentage / 100, 2);
        $driverShare        = round($total - $primaryShare, 2);

        $syCashWallet  = $this->lockWalletByPhone(config('admin.sycash.phone'));
        $primaryWallet = $this->lockWalletByPhone(config('admin.system_admin.phone'));
        $driverWallet  = $this->lockWalletByUserId($ride->driver_id);

        $this->assertSufficientBalance(
            $syCashWallet,
            $total,
            "Insufficient SyCash balance for payout. Required: {$total}"
        );

        $syCashPrev  = $syCashWallet->balance;
        $driverPrev  = $driverWallet->balance;
        $primaryPrev = $primaryWallet->balance;

        $syCashWallet->balance  -= $total;
        $driverWallet->balance  += $driverShare;
        $primaryWallet->balance += $primaryShare;

        $syCashWallet->save();
        $driverWallet->save();
        $primaryWallet->save();

        $txId = 'COMPLETE_' . time() . '_' . Str::random(6);

        // SyCash debit
        WalletTransaction::create([
            'wallet_id'        => $syCashWallet->id,
            'user_id'          => null,
            'type'             => 'escrow_released',
            'amount'           => -$total,
            'previous_balance' => $syCashPrev,
            'new_balance'      => $syCashWallet->balance,
            'description'      => "Escrow released for completed ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => 'SYCASH_' . $txId,
            'status'           => 'completed',
            'reference'        => "ride:{$ride->id}",
        ]);

        // Driver receives the driver percentage
        WalletTransaction::create([
            'wallet_id'        => $driverWallet->id,
            'user_id'          => $ride->driver_id,
            'type'             => 'ride_earnings',
            'amount'           => $driverShare,
            'previous_balance' => $driverPrev,
            'new_balance'      => $driverWallet->balance,
            'description'      => "Earnings ({$driverPercentage}%) — completed ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => 'DRIVER_' . $txId,
            'status'           => 'completed',
            'reference'        => "ride:{$ride->id}",
        ]);

        // Primary receives the platform percentage
        WalletTransaction::create([
            'wallet_id'        => $primaryWallet->id,
            'user_id'          => null,
            'type'             => 'platform_fee',
            'amount'           => $primaryShare,
            'previous_balance' => $primaryPrev,
            'new_balance'      => $primaryWallet->balance,
            'description'      => "Platform fee ({$platformPercentage}%) — completed ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => 'PRIMARY_' . $txId,
            'status'           => 'completed',
            'reference'        => "ride:{$ride->id}",
        ]);

        Log::info('Ride earnings released', [
            'ride_id'       => $ride->id,
            'driver_share'  => $driverShare,
            'primary_share' => $primaryShare,
        ]);
    }

    // =========================================================================
    // DRIVER CANCELS RIDE  (SyCash → each Passenger, 100%)
    // =========================================================================

    /**
     * Full refund to all confirmed passengers when driver cancels.
     * SyCash → each Passenger (100%).
     * No creation fee was charged, so nothing to refund to driver.
     */
    public function refundPassengersForDriverCancellation(Ride $ride, Collection $bookings): void
    {
        if ($bookings->isEmpty()) {
            return;
        }

        $totalRefund  = $bookings->sum(fn($b) => $b->seats * $ride->price_per_seat);
        $syCashWallet = $this->lockWalletByPhone(config('admin.sycash.phone'));

        $this->assertSufficientBalance(
            $syCashWallet,
            $totalRefund,
            "Insufficient SyCash balance for passenger refunds. Required: {$totalRefund}"
        );

        $txId = 'DRIVER_CANCEL_' . time() . '_' . Str::random(6);

        $syCashPrev            = $syCashWallet->balance;
        $syCashWallet->balance -= $totalRefund;
        $syCashWallet->save();

        WalletTransaction::create([
            'wallet_id'        => $syCashWallet->id,
            'user_id'          => null,
            'type'             => 'driver_cancellation_refunds',
            'amount'           => -$totalRefund,
            'previous_balance' => $syCashPrev,
            'new_balance'      => $syCashWallet->balance,
            'description'      => "Refunds for driver-cancelled ride: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => 'SYCASH_' . $txId,
            'status'           => 'completed',
            'reference'        => "ride:{$ride->id}",
        ]);

        foreach ($bookings as $booking) {
            $refundAmount    = $booking->seats * $ride->price_per_seat;
            $passengerWallet = $this->lockWalletByUserId($booking->user_id);
            $passengerPrev   = $passengerWallet->balance;

            $passengerWallet->balance += $refundAmount;
            $passengerWallet->save();

            WalletTransaction::create([
                'wallet_id'        => $passengerWallet->id,
                'user_id'          => $booking->user_id,
                'type'             => 'driver_cancellation_refund',
                'amount'           => $refundAmount,
                'previous_balance' => $passengerPrev,
                'new_balance'      => $passengerWallet->balance,
                'description'      => "Full refund — driver cancelled: {$ride->pickup_address} → {$ride->destination_address}",
                'transaction_id'   => 'PASS_' . $txId . '_' . $booking->id,
                'status'           => 'completed',
                'reference'        => "booking:{$booking->id}",
            ]);

            Log::info('Passenger refunded for driver cancellation', [
                'booking_id'   => $booking->id,
                'passenger_id' => $booking->user_id,
                'amount'       => $refundAmount,
            ]);
        }
    }

    // =========================================================================
    // TIME-BASED PASSENGER CANCELLATION  (SyCash → Passenger + Driver)
    // =========================================================================

    /**
     * Calculate refund policy based on time elapsed.
     *
     * Returns refund_percentage for passenger (rest goes to driver).
     */
    public function calculateRefundPolicy(\Carbon\Carbon $departureTime, \Carbon\Carbon $bookingCreatedAt): array
    {
        $now = now();

        if ($now->greaterThanOrEqualTo($departureTime)) {
            return [
                'refund_percentage'       => 0,
                'time_elapsed_percentage' => 100,
                'policy_tier'             => 'No refund — departure time passed',
            ];
        }

        $totalMinutes   = $bookingCreatedAt->diffInMinutes($departureTime);
        $elapsedMinutes = $bookingCreatedAt->diffInMinutes($now);
        $elapsedPct     = $totalMinutes > 0
            ? min(100, ($elapsedMinutes / $totalMinutes) * 100)
            : 100;

        if ($elapsedPct <= 30) {
            $tier = ['refund_percentage' => 100, 'policy_tier' => 'Full refund (0–30% elapsed)'];
        } elseif ($elapsedPct <= 50) {
            $tier = ['refund_percentage' => 70,  'policy_tier' => 'Partial refund (30–50% elapsed)'];
        } elseif ($elapsedPct <= 70) {
            $tier = ['refund_percentage' => 50,  'policy_tier' => 'Partial refund (50–70% elapsed)'];
        } else {
            $tier = ['refund_percentage' => 0,   'policy_tier' => 'No refund (70–100% elapsed)'];
        }

        return array_merge($tier, [
            'time_elapsed_percentage'    => $elapsedPct,
            'total_minutes_from_booking' => $totalMinutes,
            'minutes_elapsed'            => $elapsedMinutes,
        ]);
    }

    /**
     * Process time-based cancellation.
     * SyCash → Passenger (refund%) + SyCash → Driver (non-refundable%).
     */
    public function processTimeBasedCancellation(
        Booking $booking,
        Ride    $ride,
        int     $seatsCancelled,
        array   $refundPolicy
    ): void {
        $totalPaid    = $seatsCancelled * $ride->price_per_seat;
        $refundAmount = ($totalPaid * $refundPolicy['refund_percentage']) / 100;
        $driverAmount = $totalPaid - $refundAmount;

        $syCashWallet    = $this->lockWalletByPhone(config('admin.sycash.phone'));
        $passengerWallet = $this->lockWalletByUserId($booking->user_id);
        $driverWallet    = $this->lockWalletByUserId($ride->driver_id);

        $this->assertSufficientBalance(
            $syCashWallet,
            $totalPaid,
            "Insufficient SyCash balance for cancellation refund. Required: {$totalPaid}"
        );

        $syCashPrev    = $syCashWallet->balance;
        $passengerPrev = $passengerWallet->balance;
        $driverPrev    = $driverWallet->balance;

        $syCashWallet->balance -= $totalPaid;
        if ($refundAmount > 0) {
            $passengerWallet->balance += $refundAmount;
        }
        if ($driverAmount > 0) {
            $driverWallet->balance += $driverAmount;
        }

        $syCashWallet->save();
        $passengerWallet->save();
        $driverWallet->save();

        $txId = 'TIME_CANCEL_' . time() . '_' . Str::random(6);

        // SyCash debit
        WalletTransaction::create([
            'wallet_id'        => $syCashWallet->id,
            'user_id'          => null,
            'type'             => 'cancellation_processing',
            'amount'           => -$totalPaid,
            'previous_balance' => $syCashPrev,
            'new_balance'      => $syCashWallet->balance,
            'description'      => "Cancellation: refund " . number_format($refundAmount, 0) . " SYP to passenger, " . number_format($driverAmount, 0) . " SYP to driver",
            'transaction_id'   => 'SYCASH_' . $txId,
            'status'           => 'completed',
            'reference'        => "booking:{$booking->id}",
        ]);

        // Passenger refund
        if ($refundAmount > 0) {
            WalletTransaction::create([
                'wallet_id'        => $passengerWallet->id,
                'user_id'          => $booking->user_id,
                'type'             => 'time_based_refund',
                'amount'           => $refundAmount,
                'previous_balance' => $passengerPrev,
                'new_balance'      => $passengerWallet->balance,
                'description'      => "Refund ({$refundPolicy['refund_percentage']}%) — {$seatsCancelled} seat(s) cancelled ({$refundPolicy['policy_tier']})",
                'transaction_id'   => 'REFUND_' . $txId,
                'status'           => 'completed',
                'reference'        => "booking:{$booking->id}",
            ]);
        } else {
            // Audit trail for zero-refund so passenger sees it in history
            WalletTransaction::create([
                'wallet_id'        => $passengerWallet->id,
                'user_id'          => $booking->user_id,
                'type'             => 'cancellation_no_refund',
                'amount'           => 0,
                'previous_balance' => $passengerPrev,
                'new_balance'      => $passengerWallet->balance,
                'description'      => "No refund — late cancellation ({$refundPolicy['policy_tier']})",
                'transaction_id'   => 'NO_REFUND_' . $txId,
                'status'           => 'completed',
                'reference'        => "booking:{$booking->id}",
            ]);
        }

        // Driver compensation
        if ($driverAmount > 0) {
            WalletTransaction::create([
                'wallet_id'        => $driverWallet->id,
                'user_id'          => $ride->driver_id,
                'type'             => 'cancellation_fee_earnings',
                'amount'           => $driverAmount,
                'previous_balance' => $driverPrev,
                'new_balance'      => $driverWallet->balance,
                'description'      => "Cancellation compensation — {$seatsCancelled} seat(s) ({$refundPolicy['policy_tier']})",
                'transaction_id'   => 'DRIVER_' . $txId,
                'status'           => 'completed',
                'reference'        => "booking:{$booking->id}",
            ]);
        }

        Log::info('Time-based cancellation processed', [
            'booking_id'      => $booking->id,
            'seats_cancelled' => $seatsCancelled,
            'total_paid'      => $totalPaid,
            'refund_amount'   => $refundAmount,
            'driver_amount'   => $driverAmount,
            'policy_tier'     => $refundPolicy['policy_tier'],
        ]);
    }

    // =========================================================================
    // PASSENGER NO-SHOW  (SyCash → Driver 95% + Primary 5%)
    // =========================================================================

    /**
     * Passenger didn't show — E-PAY ride.
     * SyCash → Driver (driver %) + SyCash → Primary (platform %).
     */
    public function processPassengerNoShow(Booking $booking, Ride $ride, User $passenger): void
    {
        $total              = $booking->seats * $ride->price_per_seat;
        $platformPercentage = $this->policySettings->getPlatformProfitPercentage();
        $driverPercentage   = 100 - $platformPercentage;
        $primaryShare       = round($total * $platformPercentage / 100, 2);
        $driverShare        = round($total - $primaryShare, 2);

        $syCashWallet  = $this->lockWalletByPhone(config('admin.sycash.phone'));
        $driverWallet  = $this->lockWalletByUserId($ride->driver_id);
        $primaryWallet = $this->lockWalletByPhone(config('admin.system_admin.phone'));

        $this->assertSufficientBalance(
            $syCashWallet,
            $total,
            "Insufficient SyCash balance for no-show settlement. Required: {$total}"
        );

        $syCashPrev  = $syCashWallet->balance;
        $driverPrev  = $driverWallet->balance;
        $primaryPrev = $primaryWallet->balance;

        $syCashWallet->balance  -= $total;
        $driverWallet->balance  += $driverShare;
        $primaryWallet->balance += $primaryShare;

        $syCashWallet->save();
        $driverWallet->save();
        $primaryWallet->save();

        $txId = 'PASS_NOSHOW_' . time() . '_' . Str::random(6);

        WalletTransaction::create([
            'wallet_id'        => $syCashWallet->id,
            'user_id'          => null,
            'type'             => 'passenger_no_show_settlement',
            'amount'           => -$total,
            'previous_balance' => $syCashPrev,
            'new_balance'      => $syCashWallet->balance,
            'description'      => "No-show settlement — booking #{$booking->id}",
            'transaction_id'   => 'SYCASH_' . $txId,
            'status'           => 'completed',
            'reference'        => "booking:{$booking->id}",
        ]);

        WalletTransaction::create([
            'wallet_id'        => $driverWallet->id,
            'user_id'          => $ride->driver_id,
            'type'             => 'passenger_no_show_earning',
            'amount'           => $driverShare,
            'previous_balance' => $driverPrev,
            'new_balance'      => $driverWallet->balance,
            'description'      => "No-show compensation ({$driverPercentage}%) — passenger absent, {$booking->seats} seat(s)",
            'transaction_id'   => 'DRIVER_' . $txId,
            'status'           => 'completed',
            'reference'        => "booking:{$booking->id}",
        ]);

        WalletTransaction::create([
            'wallet_id'        => $primaryWallet->id,
            'user_id'          => null,
            'type'             => 'platform_fee',
            'amount'           => $primaryShare,
            'previous_balance' => $primaryPrev,
            'new_balance'      => $primaryWallet->balance,
            'description'      => "Platform fee ({$platformPercentage}%) — no-show booking #{$booking->id}",
            'transaction_id'   => 'PRIMARY_' . $txId,
            'status'           => 'completed',
            'reference'        => "booking:{$booking->id}",
        ]);

        Log::info('Passenger no-show settled', [
            'booking_id'   => $booking->id,
            'driver_share' => $driverShare,
            'primary_share'=> $primaryShare,
        ]);
    }

    // =========================================================================
    // DRIVER NO-SHOW  (SyCash → Passenger 100%)
    // =========================================================================

    /**
     * Driver didn't show — E-PAY ride.
     * SyCash → Passenger (100% refund).
     */
    public function processDriverNoShowRefund(Ride $ride, Booking $booking, User $passenger): void
    {
        $refundAmount    = $booking->seats * $ride->price_per_seat;
        $syCashWallet    = $this->lockWalletByPhone(config('admin.sycash.phone'));
        $passengerWallet = $this->lockWalletByUserId($passenger->id);

        $this->assertSufficientBalance(
            $syCashWallet,
            $refundAmount,
            "Insufficient SyCash balance for driver no-show refund. Required: {$refundAmount}"
        );

        $syCashPrev    = $syCashWallet->balance;
        $passengerPrev = $passengerWallet->balance;

        $syCashWallet->balance    -= $refundAmount;
        $passengerWallet->balance += $refundAmount;

        $syCashWallet->save();
        $passengerWallet->save();

        $txId = 'DRIVER_NOSHOW_' . time() . '_' . Str::random(6);

        WalletTransaction::create([
            'wallet_id'        => $syCashWallet->id,
            'user_id'          => null,
            'type'             => 'driver_no_show_refund',
            'amount'           => -$refundAmount,
            'previous_balance' => $syCashPrev,
            'new_balance'      => $syCashWallet->balance,
            'description'      => "Driver no-show — full refund to passenger, booking #{$booking->id}",
            'transaction_id'   => 'SYCASH_' . $txId,
            'status'           => 'completed',
            'reference'        => "booking:{$booking->id}",
        ]);

        WalletTransaction::create([
            'wallet_id'        => $passengerWallet->id,
            'user_id'          => $passenger->id,
            'type'             => 'driver_no_show_refund',
            'amount'           => $refundAmount,
            'previous_balance' => $passengerPrev,
            'new_balance'      => $passengerWallet->balance,
            'description'      => "Full refund — driver no-show: {$ride->pickup_address} → {$ride->destination_address}",
            'transaction_id'   => 'PASS_' . $txId,
            'status'           => 'completed',
            'reference'        => "booking:{$booking->id}",
        ]);

        Log::info('Driver no-show refund processed', [
            'booking_id'    => $booking->id,
            'passenger_id'  => $passenger->id,
            'refund_amount' => $refundAmount,
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function lockWalletByUserId(int $userId): Wallet
    {
        $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();

        if (!$wallet) {
            throw new \RuntimeException("Wallet not found for user ID: {$userId}");
        }

        return $wallet;
    }

    private function lockWalletByPhone(string $phone): Wallet
    {
        $wallet = Wallet::where('phone_number', $phone)->lockForUpdate()->first();

        if (!$wallet) {
            throw new \RuntimeException(
                "Wallet not found for phone: {$phone}. " .
                "Run: php artisan db:seed --class=SystemWalletSeeder"
            );
        }

        return $wallet;
    }

    private function assertSufficientBalance(Wallet $wallet, float $required, string $message): void
    {
        if ($wallet->balance < $required) {
            throw new \RuntimeException($message);
        }
    }


    /**
     * ══════════════════════════════════════════════════════════════════════════
     * PASTE THIS METHOD INTO:
     *   app/Services/Payment/WalletTransactionService.php
     *
     * PLACE IT after chargePassengerForBooking() — they are paired operations.
     *
     * IMPORTS TO ADD at the top of WalletTransactionService if not already there:
     *   use App\Models\Booking;
     *   use App\Models\Ride;
     *   use App\Models\User;
     *   use App\Models\Wallet;
     *   use App\Models\WalletTransaction;
     *   use App\Models\Employee;
     *   use Illuminate\Support\Facades\DB;
     *   use Illuminate\Support\Facades\Log;
     * ══════════════════════════════════════════════════════════════════════════
     */

    /**
     * Release a single passenger's escrowed funds to the driver.
     *
     * Called once per passenger when they confirm trip completion.
     * Money flow:
     *   admin escrow wallet  →  driver wallet (95%)
     *                        →  SyCash wallet (5%)
     *
     * Must be called inside a DB::transaction() — the caller (BookingService)
     * wraps the whole confirmation in one.
     *
     * @throws \RuntimeException if the escrow or driver wallet cannot be found,
     *                           or if the escrow has insufficient balance.
     */


    /**
     * ══════════════════════════════════════════════════════════════════════════════
     * FIND and REPLACE the entire releaseEscrowToDriver() method in:
     *   app/Services/Payment/WalletTransactionService.php
     *
     * The old version deducted from system_admin's wallet, which is WRONG.
     * The escrow is held in the SyCash wallet (chargePassengerForBooking puts
     * money there), so the release must come FROM SyCash too.
     *
     * This version mirrors releaseEarningsToDriver() but for ONE booking,
     * not the whole ride — so it can be called per-passenger.
     * ══════════════════════════════════════════════════════════════════════════════
     *
     * MONEY FLOW (matches chargePassengerForBooking exactly):
     *   SyCash (escrow)  →  Driver wallet   (95%)
     *   SyCash (escrow)  →  Primary Admin   (5%)
     */

    /**
     * Release ONE passenger's escrowed share to the driver.
     *
     * Called by BookingService::passengerConfirmCompletion() once per booking
     * when that specific passenger confirms the ride.
     *
     * Must be called inside a DB::transaction() — BookingService already does this.
     *
     * @throws \RuntimeException  if SyCash has insufficient balance,
     *                            or if any required wallet is missing.
     */
    public function releaseEscrowToDriver(Booking $booking, Ride $ride, User $driver): void
    {
        $total        = round((float) ($booking->seats * $ride->price_per_seat), 2);
        $driverShare  = round($total * 0.95, 2);
        $primaryShare = round($total - $driverShare, 2); // subtract to avoid float drift

        // ── Lock all three wallets (same order as everywhere else to prevent deadlock) ──
        $syCashWallet  = $this->lockWalletByPhone(config('admin.sycash.phone'));
        $primaryWallet = $this->lockWalletByPhone(config('admin.system_admin.phone'));
        $driverWallet  = $this->lockWalletByUserId($driver->id);

        // ── Guard: SyCash must have enough to release ─────────────────────────────
        $this->assertSufficientBalance(
            $syCashWallet,
            $total,
            "SyCash escrow has insufficient balance to release booking #{$booking->id}. "
            . "Required: {$total} SYP. Available: {$syCashWallet->balance} SYP."
        );

        // ── Snapshot previous balances ────────────────────────────────────────────
        $syCashPrev  = (float) $syCashWallet->balance;
        $driverPrev  = (float) $driverWallet->balance;
        $primaryPrev = (float) $primaryWallet->balance;

        // ── Apply balance changes ─────────────────────────────────────────────────
        $syCashWallet->balance  = $syCashPrev  - $total;
        $driverWallet->balance  = $driverPrev  + $driverShare;
        $primaryWallet->balance = $primaryPrev + $primaryShare;

        $syCashWallet->save();
        $driverWallet->save();
        $primaryWallet->save();

        // ── Transaction IDs ───────────────────────────────────────────────────────
        $ts    = now()->timestamp;
        $txId  = 'ESC_REL_' . $booking->id . '_' . $ts;
        $txRef = "booking:{$booking->id}";

        // ── 1. SyCash debit (escrow released) ────────────────────────────────────
        WalletTransaction::create([
            'wallet_id'        => $syCashWallet->id,
            'user_id'          => null,
            'type'             => 'escrow_release',
            'amount'           => -$total,
            'previous_balance' => $syCashPrev,
            'new_balance'      => (float) $syCashWallet->balance,
            'description'      => "Escrow released — booking #{$booking->id}, {$booking->seats} seat(s)",
            'transaction_id'   => 'SYCASH_' . $txId,
            'status'           => 'completed',
            'reference'        => $txRef,
        ]);

        // ── 2. Driver credit (95%) ────────────────────────────────────────────────
        WalletTransaction::create([
            'wallet_id'        => $driverWallet->id,
            'user_id'          => $driver->id,
            'type'             => 'ride_earning',
            'amount'           => $driverShare,
            'previous_balance' => $driverPrev,
            'new_balance'      => (float) $driverWallet->balance,
            'description'      => "Earnings (95%) — booking #{$booking->id}, {$booking->seats} seat(s)",
            'transaction_id'   => 'DRIVER_' . $txId,
            'status'           => 'completed',
            'reference'        => $txRef,
        ]);

        // ── 3. Primary Admin credit (5%) ──────────────────────────────────────────
        WalletTransaction::create([
            'wallet_id'        => $primaryWallet->id,
            'user_id'          => null,
            'type'             => 'platform_fee',
            'amount'           => $primaryShare,
            'previous_balance' => $primaryPrev,
            'new_balance'      => (float) $primaryWallet->balance,
            'description'      => "Platform fee (5%) — booking #{$booking->id}",
            'transaction_id'   => 'PRIMARY_' . $txId,
            'status'           => 'completed',
            'reference'        => $txRef,
        ]);

        Log::info('Escrow released per passenger confirmation', [
            'booking_id'    => $booking->id,
            'ride_id'       => $ride->id,
            'driver_id'     => $driver->id,
            'total'         => $total,
            'driver_share'  => $driverShare,
            'primary_share' => $primaryShare,
        ]);
    }
}
