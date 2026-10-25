<?php

namespace App\Console\Testing\Support;

use App\Domain\ValueObjects\Money;
use App\Models\Complaint;
use App\Models\NoshowReport;
use App\Models\Ride;
use App\Services\Ride\Noshowservice;
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
trait FullRideFlowScenarios
{
    /**
     * S1 — gate check: a no-show cannot be reported before 1 h after departure.
     */
    private function scenarioGateBeforeOneHour(Noshowservice $noshowService): void
    {
        $this->runScenario(
            '1 — Gate: cannot report before 1 h after departure',
            function () use ($noshowService) {
                ['booking' => $booking, 'passenger' => $passenger] = $this->makeScenario('cash');

                $booking->ride->update(['departure_time' => now()->subMinutes(30)]);

                $gateBlocked = false;
                try {
                    $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                } catch (\InvalidArgumentException) {
                    $gateBlocked = true;
                }

                $this->check('Gate blocked (exception thrown)', true, $gateBlocked);
                $this->check('No NoshowReport row created', 0, NoshowReport::where('booking_id', $booking->id)->count());
            }
        );
    }

    /**
     * S2 — cash: passenger reports driver no-show; driver −15.
     */
    private function scenarioCashPassengerReportsDriver(Noshowservice $noshowService): void
    {
        $this->runScenario(
            '2 — Cash: passenger reports driver no-show → driver −15 pts',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->makeScenario('cash');

                $scoreBefore = $this->score($driver);

                $r = $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                $this->check('Report accepted (report_id present)', true, isset($r['report_id']));
                $this->check('Report status = pending', 'pending', NoshowReport::where('booking_id', $booking->id)->value('status'));

                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);

                $resolved = $noshowService->resolveExpiredReports();
                $this->check('Scheduler resolved 1 report', 1, $resolved);
                $this->check('Report = resolved_reporter_wins', 'resolved_reporter_wins', NoshowReport::where('booking_id', $booking->id)->value('status'));
                $this->check('Booking status = no_show', 'no_show', $booking->fresh()->status);
                $this->check('Driver score −15', -15, $this->score($driver) - $scoreBefore);
            }
        );
    }

    /**
     * S3 — cash: driver reports passenger no-show; passenger −15.
     */
    private function scenarioCashDriverReportsPassenger(Noshowservice $noshowService): void
    {
        $this->runScenario(
            '3 — Cash: driver reports passenger no-show → passenger −15 pts',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->makeScenario('cash');

                $scoreBefore = $this->score($passenger);

                $r = $noshowService->reportPassengerNoShow($booking->id, $driver);
                $this->check('Report accepted', true, isset($r['report_id']));

                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);
                $noshowService->resolveExpiredReports();

                $this->check('Booking = no_show', 'no_show', $booking->fresh()->status);
                $this->check('Passenger score −15', -15, $this->score($passenger) - $scoreBefore);
            }
        );
    }

    /**
     * S4 — both sides report within 2 h → disputed + auto-complaint (type = no_show).
     */
    private function scenarioBothReportDispute(Noshowservice $noshowService): void
    {
        $this->runScenario(
            '4 — Both report within 2 h → disputed + auto-complaint (type=no_show)',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->makeScenario('cash');

                $complaintsBefore = Complaint::count();

                $r1 = $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                $this->check('First report (passenger) accepted', true, isset($r1['report_id']));

                $r2 = $noshowService->reportPassengerNoShow($booking->id, $driver);
                $this->check('Counter-report triggered conflict', true, $r2['conflict'] ?? false);

                $disputedCount = NoshowReport::where('booking_id', $booking->id)
                    ->where('status', 'disputed')
                    ->count();
                $this->check('At least 1 report = disputed', true, $disputedCount >= 1);

                $newComplaintCount = Complaint::count() - $complaintsBefore;
                $this->check('Complaint count +1', 1, $newComplaintCount);

                $complaint = Complaint::latest()->first();
                $this->check('Complaint type = no_show', 'no_show', $complaint->type);
                $this->check(
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
    private function scenarioDisputedSkipsPenalties(Noshowservice $noshowService): void
    {
        $this->runScenario(
            '5 — Disputed: scheduler skips; zero penalties applied',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->makeScenario('cash');

                $dScoreBefore = $this->score($driver);
                $pScoreBefore = $this->score($passenger);

                $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                $noshowService->reportPassengerNoShow($booking->id, $driver);

                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);

                $resolved = $noshowService->resolveExpiredReports();

                $this->check('Scheduler resolved 0 (disputed skipped)', 0, $resolved);
                $this->check('Driver score unchanged', 0, $this->score($driver) - $dScoreBefore);
                $this->check('Passenger score unchanged', 0, $this->score($passenger) - $pScoreBefore);
            }
        );
    }

    /**
     * S6 — e-pay: the passenger reports the driver; escrow is refunded to the passenger.
     */
    private function scenarioEPayDriverNoShowRefund(Noshowservice $noshowService): void
    {
        $this->runScenario(
            '6 — E-pay: passenger reports driver no-show → escrow refunded to passenger',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->makeScenario('e-pay');

                $escrowAmount = (float) ($booking->seats * $booking->ride->price_per_seat);

                [$adminWallet, $driverWallet, $passengerWallet] = $this->setupWallets(
                    $driver, $passenger, $escrowAmount
                );

                $pBalanceBefore = (float) $passengerWallet->fresh()->balance;
                $dScoreBefore = $this->score($driver);

                $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);
                $resolved = $noshowService->resolveExpiredReports();

                $this->check('Resolver processed 1 report (0 = wallet threw — check laravel.log)', 1, $resolved);

                $pBalanceAfter = (float) $passengerWallet->fresh()->balance;

                $this->check('Passenger balance increased (refund received)',
                    true,
                    $pBalanceAfter > $pBalanceBefore
                );
                // `R2 sec 117`: both sides normalised through `Money` so the comparison is in minor
                // units, matching how the ledger itself decides whether a transfer balances.
                $this->check('Refund amount matches booking cost',
                    Money::from((float) $escrowAmount)->amount(),
                    Money::from($pBalanceAfter - $pBalanceBefore)->amount()
                );
                $this->check('Driver score −15', -15, $this->score($driver) - $dScoreBefore);
                $this->check('Booking = no_show', 'no_show', $booking->fresh()->status);
            }
        );
    }

    /**
     * S7 — e-pay: the driver reports the passenger; escrow is released to the driver.
     */
    private function scenarioEPayPassengerNoShowRelease(Noshowservice $noshowService): void
    {
        $this->runScenario(
            '7 — E-pay: driver reports passenger no-show → escrow released to driver',
            function () use ($noshowService) {
                ['booking' => $booking, 'driver' => $driver, 'passenger' => $passenger] = $this->makeScenario('e-pay');

                $escrowAmount = (float) ($booking->seats * $booking->ride->price_per_seat);

                [$adminWallet, $driverWallet, $passengerWallet] = $this->setupWallets(
                    $driver, $passenger, $escrowAmount
                );

                $dBalanceBefore = (float) $driverWallet->fresh()->balance;
                $pScoreBefore = $this->score($passenger);

                $noshowService->reportPassengerNoShow($booking->id, $driver);
                NoshowReport::where('booking_id', $booking->id)->update(['expires_at' => now()->subMinute()]);
                $noshowService->resolveExpiredReports();

                $dBalanceAfter = (float) $driverWallet->fresh()->balance;

                $this->check('Driver balance increased (payment received)',
                    true,
                    $dBalanceAfter > $dBalanceBefore
                );
                $this->check('Driver receives ≥ 90% of fare',
                    true,
                    ($dBalanceAfter - $dBalanceBefore) >= $escrowAmount * 0.90
                );
                $this->check('Passenger score 0 (e-pay: wallet is the penalty)', 0, $this->score($passenger) - $pScoreBefore);
                $this->check('Booking = no_show', 'no_show', $booking->fresh()->status);
            }
        );
    }

    /**
     * S8 — a duplicate report by the same role is rejected.
     */
    private function scenarioDuplicateReportRejected(Noshowservice $noshowService): void
    {
        $this->runScenario(
            '8 — Duplicate report by same role is rejected',
            function () use ($noshowService) {
                ['booking' => $booking, 'passenger' => $passenger] = $this->makeScenario('cash');

                $r1 = $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                $this->check('First report accepted', true, isset($r1['report_id']));

                $dupBlocked = false;
                try {
                    $noshowService->reportDriverNoShow($booking->ride_id, $passenger);
                } catch (\InvalidArgumentException) {
                    $dupBlocked = true;
                }
                $this->check('Duplicate rejected (exception thrown)', true, $dupBlocked);
                $this->check('Still only 1 NoshowReport row', 1, NoshowReport::where('booking_id', $booking->id)->count());
            }
        );
    }
}
