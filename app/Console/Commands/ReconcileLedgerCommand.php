<?php

namespace App\Console\Commands;

use App\Modules\Money\Contracts\LedgerReconciliation;
use Illuminate\Console\Command;

/**
 * Decision un3 (owner, 2026-10-02): does the ledger explain the balances?
 *
 * `LedgerService::postTransfer` guarantees each transfer's legs balance. The test
 * `DoubleEntryLedgerTest::the_ledger_agrees_with_the_balances_across_a_full_ride` proves, for a ride
 * lifecycle, that the legs exactly equal what the balances did. This command is the same check run
 * against REAL data, on demand.
 *
 * EVERY MONEY PATH IS NOW CONVERTED. This paragraph used to say that the admin wallet paths -
 * `AdminWalletService::chargeWallet`, `AdminWalletRequestController`,
 * `PassengerProfileController::chargeWallet` - were NOT converted, because they are EXTERNAL
 * inflows/outflows with no internal counterparty. That stopped being true in `R2 sec 57-60.1`:
 * they post `postExternalTransfer()` against the External Capital account, and
 * `AdminWalletService:129` THROWS if that account is missing rather than recording money the
 * ledger cannot explain. So the ledger is closed, and the old text was wrong in a way that
 * mattered: it told the operator to EXPECT unexplained movement here, which would have had them
 * dismissing a real defect on a money path.
 *
 * What this command DOES with per-wallet drift changed at `R2 sec 115`, and not for a technical reason.
 * It used to warn and return SUCCESS, because whether a daily job should fail the schedule is an
 * alerting decision, not a code one, and `AGENTS.md` reserves it for the owner. The owner has now
 * answered: FAIL. Every money path is ledgered, so unexplained movement has no remaining legitimate
 * cause, and a job whose entire purpose is to be an alarm should not exit 0 while reporting one.
 *
 * The exit code is the guard. Everything else here is the message.
 */
class ReconcileLedgerCommand extends Command
{
    protected $signature = 'ledger:reconcile
                            {--threshold=0.01 : Absolute per-wallet drift (in SYP) tolerated before reporting}';

    protected $description = 'Report whether every wallet balance change is explained by its ledger legs; exit non-zero if any is not';

    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');

        // Stage 2 C2: every read below is Money's; the command renders and sets the exit code.
        $ledger = app(LedgerReconciliation::class);
        $walletCount = $ledger->walletCount();

        $this->renderHeader($walletCount, $ledger->ledgerEntryCount(), $threshold);

        if (! $this->systemBalances($ledger->systemImbalance())) {
            return self::FAILURE;
        }

        [$explained, $unexplained] = $this->comparePerWallet($ledger->perWalletDrift($threshold));

        $this->info(sprintf('  %d of %d wallets fully explained by the ledger.', $explained, $walletCount));

        if ($unexplained !== []) {
            $this->reportUnexplained($unexplained);

            // Owner decision (AF-6a, R2 sec 115). The report above is the diagnosis; this is the alarm.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function renderHeader(int $wallets, int $ledgerEntries, float $threshold): void
    {
        $this->info('Ledger reconciliation');
        $this->line(sprintf(
            '  %d wallets | %d ledger entries | tolerance %s SYP',
            $wallets,
            $ledgerEntries,
            number_format($threshold, 2),
        ));
        $this->newLine();
    }

    /**
     * Conservation first: if the whole system does not balance, no per-wallet reading means much.
     */
    private function systemBalances(float $systemImbalance): bool
    {

        if ($systemImbalance !== 0.0) {
            $this->error(sprintf(
                '  SYSTEM DOES NOT BALANCE: all legs sum to %s SYP (expected 0.00).',
                number_format($systemImbalance, 2),
            ));
            $this->error('  Every transfer must conserve money. Fix this before trusting any per-wallet row below.');

            return false;
        }

        $this->info('  System balances: all ledger legs sum to 0.00 SYP.');
        $this->newLine();

        return true;
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function comparePerWallet(array $drift): array
    {
        // Stage 2 C2: Money compared the two sides; the command only formats the lines.
        [$explained, $rows] = $drift;
        $unexplained = [];

        foreach ($rows as $row) {
            $unexplained[] = sprintf(
                '    wallet #%d (%s): wallet_transactions %s, ledger %s, drift %s SYP',
                $row['id'],
                $row['number'],
                number_format($row['recorded'], 2),
                number_format($row['ledgered'], 2),
                number_format($row['drift'], 2),
            );
        }

        return [$explained, $unexplained];
    }

    /**
     * @param  list<string>  $unexplained
     */
    private function reportUnexplained(array $unexplained): void
    {
        $this->newLine();
        $this->warn(sprintf('  %d wallet(s) with movement the ledger does not yet explain:', count($unexplained)));
        foreach ($unexplained as $line) {
            $this->line($line);
        }
        $this->newLine();
        $this->comment('  Unexpected on ANY wallet: a converted money path moved a balance');
        $this->comment('  without posting legs. Every money path is double-entry now, the admin');
        $this->comment('  and external flows included (R2 sec 57-60.1), so no flow is left for which');
        $this->comment('  unexplained movement is expected.');
        $this->newLine();
    }
}
