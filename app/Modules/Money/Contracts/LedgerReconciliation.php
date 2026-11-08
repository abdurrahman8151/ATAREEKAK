<?php

namespace App\Modules\Money\Contracts;

/**
 * Stage 2 C2: the data `ledger:reconcile` reports, without the command holding Money's models.
 *
 * The command is a kernel entrypoint whose whole job is to ask whether the ledger explains the balances:
 * wallet count, ledger entry count, the system-wide imbalance, and per-wallet drift between what
 * `wallet_transactions` recorded and what `ledger_entries` posted. Every one of those reads belongs to Money,
 * so the reads move and the command keeps only the presentation and the exit code.
 *
 * Drift rows are returned as plain values, including the wallet number the report prints - the command still
 * formats the message, so the output text is unchanged.
 */
interface LedgerReconciliation
{
    public function walletCount(): int;

    public function ledgerEntryCount(): int;

    /** All ledger legs summed and rounded to 2dp; 0.00 means every transfer conserved money. */
    public function systemImbalance(): float;

    /**
     * Per-wallet comparison, in wallet id order.
     *
     * @return array{0: int, 1: list<array{id: int, number: string, recorded: float, ledgered: float, drift: float}>}
     *                                                                                                                [fully explained count, wallets whose drift exceeds the threshold]
     */
    public function perWalletDrift(float $threshold): array;
}
