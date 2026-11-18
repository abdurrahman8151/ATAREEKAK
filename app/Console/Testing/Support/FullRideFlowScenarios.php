<?php

namespace App\Console\Testing\Support;

use App\Domain\ValueObjects\Money;
use App\Modules\Console\Models\Complaint;
use App\Modules\Ride\Models\NoshowReport;
use App\Modules\Ride\Models\Ride;
use App\Modules\Ride\Services\Noshowservice;
use Illuminate\Console\Command;

/**
 * The eight no-show scenarios of the full-ride test harness (gate check, cash and e-pay reports,
 * conflicts, disputed reports and the duplicate-report guard).
 *
 * Split out of Testfullrideflow (behaviour unchanged) so the command keeps its signature, its
 * output and its order of operations. The using command must provide the helpers these scenarios
 * call: runScenario(), check(), v(), makeScenario(), makeScenarioUsers(), setupWallets(),
 * resolveAdminWallet() and score(). Bodies moved verbatim.
 */
final class FullRideFlowScenarios
{
    public function __construct(
        private readonly RideFlowHarness $harness,
    ) {}

    /**
     * S1 — gate check: a no-show cannot be reported before 1 h after departure.
     */
    public function scenarioGateBeforeOneHour(Noshowservice $noshowService): void
    {
        $this->harness->runScenario(
            '1 — Gate: cannot report before 1 h after departure',
            function () use ($noshowService) {
                ['booking' => $booking, 'passenger' => $passenger] = $this->harness->makeScenario('cash');

                $booking->ride->update(['departure_time' => now()->subMinutes(30)]);

                $gateBlocked = false;
                try {
                    $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                } catch (\InvalidArgumentException) {
                    $gateBlocked = true;
                }

                $this->harness->check('Gate blocked (exception thrown)', true, $gateBlocked);
                $this->harness->check('No NoshowReport row created', 0, NoshowReport::where('booking_id', $booking->id)->count());
            }
        );
    }

    /**
     * S2 — cash: passenger reports driver no-show; driver −15.
     */
    public function scenarioCashPassengerReportsDriver(Noshowservice $noshowService): void
    {
        $this->harness->runScenario(
            '2 — Cash: passenger reports driver no-show → driver −15 pts',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->harness->makeScenario('cash');

                $scoreBefore = $this->harness->score($driver);

                $r = $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                $this->harness->check('Report accepted (report_id present)', true, isset($r['report_id']));
                $this->harness->check('Report status = pending', 'pending', NoshowReport::where('booking_id', $booking->id)->value('status'));

                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);

                $resolved = $noshowService->resolveExpiredReports();
                $this->harness->check('Scheduler resolved 1 report', 1, $resolved);
                $this->harness->check('Report = resolved_reporter_wins', 'resolved_reporter_wins', NoshowReport::where('booking_id', $booking->id)->value('status'));
                $this->harness->check('Booking status = no_show', 'no_show', $booking->fresh()->status);
                $this->harness->check('Driver score −15', -15, $this->harness->score($driver) - $scoreBefore);
            }
        );
    }

    /**
     * S3 — cash: driver reports passenger no-show; passenger −15.
     */
    public function scenarioCashDriverReportsPassenger(Noshowservice $noshowService): void
    {
        $this->harness->runScenario(
            '3 — Cash: driver reports passenger no-show → passenger −15 pts',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->harness->makeScenario('cash');

                $scoreBefore = $this->harness->score($passenger);

                $r = $noshowService->reportPassengerNoShow($booking->id, $driver);
                $this->harness->check('Report accepted', true, isset($r['report_id']));

                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);
                $noshowService->resolveExpiredReports();

                $this->harness->check('Booking = no_show', 'no_show', $booking->fresh()->status);
                $this->harness->check('Passenger score −15', -15, $this->harness->score($passenger) - $scoreBefore);
            }
        );
    }

    /**
     * S4 — both sides report within 2 h → disputed + auto-complaint (type = no_show).
     */
    public function scenarioBothReportDispute(Noshowservice $noshowService): void
    {
        $this->harness->runScenario(
            '4 — Both report within 2 h → disputed + auto-complaint (type=no_show)',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->harness->makeScenario('cash');

                $complaintsBefore = Complaint::count();

                $r1 = $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                $this->harness->check('First report (passenger) accepted', true, isset($r1['report_id']));

                $r2 = $noshowService->reportPassengerNoShow($booking->id, $driver);
                $this->harness->check('Counter-report triggered conflict', true, $r2['conflict'] ?? false);

                $disputedCount = NoshowReport::where('booking_id', $booking->id)
                    ->where('status', 'disputed')
                    ->count();
                $this->harness->check('At least 1 report = disputed', true, $disputedCount >= 1);

                $newComplaintCount = Complaint::count() - $complaintsBefore;
                $this->harness->check('Complaint count +1', 1, $newComplaintCount);

                $complaint = Complaint::latest()->first();
                $this->harness->check('Complaint type = no_show', 'no_show', $complaint->type);
                $this->harness->check(
                    'Complaint user_id is a dispute party',
                    true,
                    in_array($complaint->user_id, [$driver->id, $passenger->id])
                );
            }
        );
    }

    /**
     * S5 — disputed reports: the scheduler skips them, so nobody is penalised.
     */
    public function scenarioDisputedSkipsPenalties(Noshowservice $noshowService): void
    {
        $this->harness->runScenario(
            '5 — Disputed: scheduler skips; zero penalties applied',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->harness->makeScenario('cash');

                $dScoreBefore = $this->harness->score($driver);
                $pScoreBefore = $this->harness->score($passenger);

                $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                $noshowService->reportPassengerNoShow($booking->id, $driver);

                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);

                $resolved = $noshowService->resolveExpiredReports();

                $this->harness->check('Scheduler resolved 0 (disputed skipped)', 0, $resolved);
                $this->harness->check('Driver score unchanged', 0, $this->harness->score($driver) - $dScoreBefore);
                $this->harness->check('Passenger score unchanged', 0, $this->harness->score($passenger) - $pScoreBefore);
            }
        );
    }

    /**
     * S6 — e-pay: the passenger reports the driver; escrow is refunded to the passenger.
     */
    public function scenarioEPayDriverNoShowRefund(Noshowservice $noshowService): void
    {
        $this->harness->runScenario(
            '6 — E-pay: passenger reports driver no-show → escrow refunded to passenger',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->harness->makeScenario('e-pay');

                $escrowAmount = (float) ($booking->seats * $booking->ride->price_per_seat);

                [$adminWallet, $driverWallet, $passengerWallet] = $this->harness->setupWallets(
                    $driver, $passenger, $escrowAmount
                );

                $pBalanceBefore = (float) $passengerWallet->fresh()->balance;
                $dScoreBefore = $this->harness->score($driver);

                $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);
                $resolved = $noshowService->resolveExpiredReports();

                $this->harness->check('Resolver processed 1 report (0 = wallet threw — check laravel.log)', 1, $resolved);

                $pBalanceAfter = (float) $passengerWallet->fresh()->balance;

                $this->harness->check('Passenger balance increased (refund received)',
                    true,
                    $pBalanceAfter > $pBalanceBefore
                );
                // `R2 sec 117`: both sides normalised through `Money` so the comparison is in minor
                // units, matching how the ledger itself decides whether a transfer balances.
                $this->harness->check('Refund amount matches booking cost',
                    Money::from((float) $escrowAmount)->amount(),
                    Money::from($pBalanceAfter - $pBalanceBefore)->amount()
                );
                $this->harness->check('Driver score −15', -15, $this->harness->score($driver) - $dScoreBefore);
                $this->harness->check('Booking = no_show', 'no_show', $booking->fresh()->status);
            }
        );
    }

    /**
     * S7 — e-pay: the driver reports the passenger; escrow is released to the driver.
     */
    public function scenarioEPayPassengerNoShowRelease(Noshowservice $noshowService): void
    {
        $this->harness->runScenario(
            '7 — E-pay: driver reports passenger no-show → escrow released to driver',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->harness->makeScenario('e-pay');

                $escrowAmount = (float) ($booking->seats * $booking->ride->price_per_seat);

                [$adminWallet, $driverWallet, $passengerWallet] = $this->harness->setupWallets(
                    $driver, $passenger, $escrowAmount
                );

                $dBalanceBefore = (float) $driverWallet->fresh()->balance;
                $pScoreBefore = $this->harness->score($passenger);

                $noshowService->reportPassengerNoShow($booking->id, $driver);
                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);
                $noshowService->resolveExpiredReports();

                $dBalanceAfter = (float) $driverWallet->fresh()->balance;

                $this->harness->check('Driver balance increased (payment received)',
                    true,
                    $dBalanceAfter > $dBalanceBefore
                );
                $this->harness->check('Driver receives ≥ 90% of fare',
                    true,
                    ($dBalanceAfter - $dBalanceBefore) >= $escrowAmount * 0.90
                );
                $this->harness->check('Passenger score 0 (e-pay: wallet is the penalty)', 0, $this->harness->score($passenger) - $pScoreBefore);
                $this->harness->check('Booking = no_show', 'no_show', $booking->fresh()->status);
            }
        );
    }

    /**
     * S8 — a duplicate report by the same role is rejected.
     */
    public function scenarioDuplicateReportRejected(Noshowservice $noshowService): void
    {
        $this->harness->runScenario(
            '8 — Duplicate report by same role is rejected',
            function () use ($noshowService) {
                ['booking' => $booking, 'passenger' => $passenger] = $this->harness->makeScenario('cash');

                $r1 = $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                $this->harness->check('First report accepted', true, isset($r1['report_id']));

                $dupBlocked = false;
                try {
                    $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                } catch (\InvalidArgumentException) {
                    $dupBlocked = true;
                }
                $this->harness->check('Duplicate rejected (exception thrown)', true, $dupBlocked);
                $this->harness->check('Still only 1 NoshowReport row', 1, NoshowReport::where('booking_id', $booking->id)->count());
            }
        );
    }
}
