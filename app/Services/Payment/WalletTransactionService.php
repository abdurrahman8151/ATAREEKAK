<?php

namespace App\Services\Payment;

use App\Domain\ValueObjects\Money;
use App\Enums\WalletKind;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\FeeSplit;
use App\Support\PostingKey;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin facade over the money classes (RFB-3). Kept so existing callers keep working; their
 * behaviour lives in BookingCharge, RidePayout, CancellationRefund and NoShowSettlement.
 */
class WalletTransactionService
{
    public function __construct(
        private readonly LedgerService $ledger = new LedgerService,
    ) {}

    private ?WalletAccess $access = null;

    private function walletAccess(): WalletAccess
    {
        return $this->access ??= new WalletAccess;
    }

    private function bookingCharge(): BookingCharge
    {
        return new BookingCharge($this->walletAccess(), $this->ledger);
    }

    private function ridePayout(): RidePayout
    {
        return new RidePayout($this->walletAccess(), $this->ledger);
    }

    private function cancellationRefund(): CancellationRefund
    {
        return new CancellationRefund($this->walletAccess(), $this->ledger);
    }

    private function noShowSettlement(): NoShowSettlement
    {
        return new NoShowSettlement($this->walletAccess(), $this->ledger);
    }

    public function chargePassengerForBooking(Booking $booking, Ride $ride, User $passenger): void
    {
        $this->bookingCharge()->chargePassengerForBooking($booking, $ride, $passenger);
    }

    public function releaseEarningsToDriver(Ride $ride, Collection $confirmedBookings): void
    {
        $this->ridePayout()->releaseEarningsToDriver($ride, $confirmedBookings);
    }

    public function refundPassengersForDriverCancellation(Ride $ride, Collection $bookings): void
    {
        $this->cancellationRefund()->refundPassengersForDriverCancellation($ride, $bookings);
    }

    public function refundPassengersForStaffCancellation(Ride $ride, Collection $bookings): array
    {
        return $this->cancellationRefund()->refundPassengersForStaffCancellation($ride, $bookings);
    }

    public function calculateRefundPolicy(Carbon $departureTime, Carbon $bookingCreatedAt): array
    {
        return $this->cancellationRefund()->calculateRefundPolicy($departureTime, $bookingCreatedAt);
    }

    public function processTimeBasedCancellation(Booking $booking, Ride $ride, int $seatsCancelled, array $refundPolicy): void
    {
        $this->cancellationRefund()->processTimeBasedCancellation($booking, $ride, $seatsCancelled, $refundPolicy);
    }

    public function processPassengerNoShow(Booking $booking, Ride $ride, User $passenger): void
    {
        $this->noShowSettlement()->processPassengerNoShow($booking, $ride, $passenger);
    }

    public function processDriverNoShowRefund(Ride $ride, Booking $booking, User $passenger): void
    {
        $this->noShowSettlement()->processDriverNoShowRefund($ride, $booking, $passenger);
    }

    public function releaseEscrowToDriver(Booking $booking, Ride $ride, User $driver): void
    {
        $this->ridePayout()->releaseEscrowToDriver($booking, $ride, $driver);
    }

}