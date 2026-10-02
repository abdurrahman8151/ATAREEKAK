<?php

namespace App\Enums;

/**
 * RV-39 — the shared vocabulary for `wallet_transactions.type`.
 *
 * Why this exists: the same column was written in THREE dialects — the vocabulary the
 * migration intended (2025_07_18_212423), string literals in the money services
 * (dialect two), and a third set of invented names in SyrideSeeder
 * (`ride_payment`, `escrow_hold`, `ride_creation_fee_received`). Because
 * AdminReportService and PassengerProfileController query by exact type string, every
 * row written under a non-matching dialect was invisible: seeded databases reported
 * 0.00 on the admin financial dashboards, which is the defect class T1-2
 * (`escrow_release` vs `escrow_released`) proved in production-shaped data.
 *
 * This enum is the single list of names that are ALIVE in app/ today (each case names
 * its writer). RV-39 moves the SEEDER onto it and pins the drift with
 * tests/Feature/Review/RV39SeederHygieneTest; the services still pass literals, and
 * converting them is AF-6's `LedgerEvent` job (Wave 3, owner-paused) — AF-6 will absorb
 * or rename this enum, so do NOT add new cases here without a live writer.
 *
 * The column itself is varchar(255), not a DB enum: the migration intended an ENUM but
 * Laravel's renameColumn() rebuilt it as VARCHAR (measured in T1-2, S P2 sec T1-2), so
 * an unknown value never fails at the database — only at the readers. That is exactly
 * why drift must be pinned in code, not in the schema.
 */
enum LedgerType: string
{
    // ── Admin/operations top-ups ───────────────────────────────────────────────
    case ADMIN_CREDIT = 'admin_credit';                       // AdminWalletService::chargeWallet
    case ADMIN_CHARGE = 'admin_charge';                       // AdminWalletRequestController + PassengerProfileController top-ups
    case WITHDRAWAL = 'withdrawal';                           // AdminWalletRequestController:135; ORPHAN (no reader) - AF-6 decides its name

    // ── Booking charge and escrow (WalletTransactionService) ───────────────────
    case RIDE_BOOKING_PAYMENT = 'ride_booking_payment';       // passenger debit on booking
    case ESCROW_RECEIVED = 'escrow_received';                 // SyCash credit on booking  (report: total_escrow_in)
    case ESCROW_RELEASE = 'escrow_release';                   // SyCash debit, per booking (report: total_escrow_out)
    case ESCROW_RELEASED = 'escrow_released';                 // legacy ride-wide release; kept counted by the report
    case RIDE_EARNING = 'ride_earning';                       // driver credit, per booking (95%)
    case RIDE_EARNINGS = 'ride_earnings';                     // driver credit, legacy ride-wide (66%)
    case PLATFORM_FEE = 'platform_fee';                       // Primary credit (5%)     (report: total_platform_fees)

    // ── Cancellation (WalletTransactionService) ─────────────────────────────────
    case DRIVER_CANCELLATION_REFUNDS = 'driver_cancellation_refunds'; // SyCash debit, ride-wide legacy
    case DRIVER_CANCELLATION_REFUND = 'driver_cancellation_refund';    // passenger credit
    case CANCELLATION_PROCESSING = 'cancellation_processing';          // report: total_refunds_paid
    case TIME_BASED_REFUND = 'time_based_refund';
    case CANCELLATION_NO_REFUND = 'cancellation_no_refund';
    case CANCELLATION_FEE_EARNINGS = 'cancellation_fee_earnings';

    // ── No-show settlement (WalletTransactionService) ───────────────────────────
    case PASSENGER_NO_SHOW_SETTLEMENT = 'passenger_no_show_settlement';
    case PASSENGER_NO_SHOW_EARNING = 'passenger_no_show_earning';
    case DRIVER_NO_SHOW_REFUND = 'driver_no_show_refund';              // report: total_refunds_paid

    // ── Ride creation fee ──────────────────────────────────────────────────────
    // Migration-enum vocabulary (2025_07_18_212423): listed there, no app/ writer today.
    // The cash path uses CASH_RIDE_CREATION_FEE(_RECEIVED) below instead. AF-6's
    // LedgerEvent owns the final vocabulary - do not add more cases without a writer.
    case RIDE_CREATION_FEE = 'ride_creation_fee';
    case RIDE_CREATION_FEE_RECEIVED = 'ride_creation_fee_received';   // no writer/reader in app/

    // ── Cash-ride creation fee (CashRideFeeService) ─────────────────────────────
    case CASH_RIDE_FEE_DEFERRED = 'cash_ride_fee_deferred';
    case CASH_RIDE_CREATION_FEE = 'cash_ride_creation_fee';
    case CASH_RIDE_CREATION_FEE_RECEIVED = 'cash_ride_creation_fee_received';
    case CASH_RIDE_FEE_DEBT_CANCELLED = 'cash_ride_fee_debt_cancelled';
    case CASH_RIDE_FEE_NO_REFUND = 'cash_ride_fee_no_refund';
    case CASH_RIDE_FEE_REFUND = 'cash_ride_fee_refund';
    case CASH_RIDE_FEE_REFUND_ISSUED = 'cash_ride_fee_refund_issued';
    case CASH_RIDE_DEBT_CLEARED = 'cash_ride_debt_cleared';
    case CASH_RIDE_DEBT_RECEIVED = 'cash_ride_debt_received';

    /** All type values, for scans/tests. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
