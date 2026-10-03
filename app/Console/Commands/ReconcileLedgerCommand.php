<?php

namespace App\Console\Commands;

use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Console\Command;

/**
 * Decision un3 (owner, 2026-10-02): does the ledger explain the balances?
 *
 * `LedgerService::postTransfer` guarantees each transfer's legs balance. The test
 * `DoubleEntryLedgerTest::the_ledger_agrees_with_the_balances_across_a_full_ride` proves, for a ride
 * lifecycle, that the legs exactly equal what the balances did. This command is the same check run
 * against REAL data, on demand.
 *
 * IT DELIBERATELY REPORTS INCOMPLETENESS RATHER THAN HIDING IT. Only flows converted so far write
 * legs (every RIDE money movement). The admin wallet paths - `AdminWalletService::chargeWallet`,
 * `AdminWalletRequestController`, `PassengerProfileController::chargeWallet` - are EXTERNAL
 * inflows/outflows with no internal counterparty and are NOT converted, pending the owner's decision
 * on whether this ledger should model external flows at all.
 *
 * So a wallet whose balance moved but whose movement has no legs is EXPECTED for those flows and is
 * reported as `unexplained`, not as a failure. Reading the output as "the ledger is broken" would be
 * the wrong conclusion in exactly the same way a silently-excluded flow would be.
 */
class ReconcileLedgerCommand extends Command
{
    protected $signature = 'ledger:reconcile
                            {--threshold=0.01 : Absolute per-wallet drift (in SYP) tolerated before reporting}';

    protected $description = 'Report whether every wallet balance change is explained by its ledger legs';

    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');

        $wallets = Wallet::orderBy('id')->get();

        $this->info('Ledger reconciliation');
        $this->line(sprintf(
            '  %d wallets | %d ledger entries | tolerance %s SYP',
            $wallets->count(),
            LedgerEntry::count(),
            number_format($threshold, 2),
        ));
        $this->newLine();

        // Conservation first: if the whole system does not balance, no per-wallet reading means much.
        $systemImbalance = round((float) LedgerEntry::sum('amount'), 2);

        if ($systemImbalance !== 0.0) {
            $this->error(sprintf(
                '  SYSTEM DOES NOT BALANCE: all legs sum to %s SYP (expected 0.00).',
                number_format($systemImbalance, 2),
            ));
            $this->error('  Every transfer must conserve money. Fix this before trusting any per-wallet row below.');

            return self::FAILURE;
        }

        $this->info('  System balances: all ledger legs sum to 0.00 SYP.');
        $this->newLine();

        $explained = 0;
        $unexplained = [];

        foreach ($wallets as $wallet) {
            // What the single-sided table says this wallet moved, versus what the ledger says.
            $recorded = round((float) $wallet->transactions()->sum('amount'), 2);
            $ledgered = round((float) LedgerEntry::where('wallet_id', $wallet->id)->sum('amount'), 2);
            $drift = round($ledgered - $recorded, 2);

            if (abs($drift) <= $threshold) {
                $explained++;

                continue;
            }

            $unexplained[] = sprintf(
                '    wallet #%d (%s): wallet_transactions %s, ledger %s, drift %s SYP',
                $wallet->id,
                $wallet->wallet_number,
                number_format($recorded, 2),
                number_format($ledgered, 2),
                number_format($drift, 2),
            );
        }

        $this->info(sprintf('  %d of %d wallets fully explained by the ledger.', $explained, $wallets->count()));

        if ($unexplained !== []) {
            $this->newLine();
            $this->warn(sprintf('  %d wallet(s) with movement the ledger does not yet explain:', count($unexplained)));
            foreach ($unexplained as $line) {
                $this->line($line);
            }
            $this->newLine();
            $this->comment('  Expected for ADMIN wallet paths (external credit / withdrawal), which are');
            $this->comment('  intentionally not double-entry until the owner decides whether this ledger');
            $this->comment('  models external flows. Unexpected anywhere else: a converted money path');
            $this->comment('  moved a balance without posting legs.');
        }

        return self::SUCCESS;
    }
}
