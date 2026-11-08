# Money module

Owns balances, the transaction ledger and every movement of money. This is the module with the least tolerance
for a boundary shortcut: a caller that can write a balance can break the ledger.

## Owned tables

| Model | Table | Status |
|---|---|---|
| `Wallet` | `wallets` | pending (class still in `app/Models`) |
| `WalletRequest` | `wallet_requests` | pending |
| `WalletTransaction` | `wallet_transactions` | pending |
| `LedgerEntry` | `ledger_entries` | pending |

All four already declare an explicit `protected $table`, so moving the classes cannot change where rows land.

## Read contracts

- `WalletDirectory` - a user's wallet summary, batched by user id, plus `byPhone()` and
  `systemWalletByPhone()` (the latter carries the RV-21 boundary: platform-owned wallets only).
- `WalletChargeHistory`, `WalletTransactionPager` - paginated transaction pages.
- `LedgerAggregates` - the admin report's four totals; owns the ledger type spellings, including the two
  escrow-release variants and the three refund types.
- `LedgerReconciliation` - `ledger:reconcile`'s reads: wallet/entry counts, system imbalance, per-wallet drift.
- `DriverEarningsReader` - the admin driver dashboard's settled-earnings sum (both RV-39 spellings).

## Write contracts

- `WalletTransactionRecorder` - record a `wallet_transactions` row (a single leg).
- `AdminWalletCredit` - an admin credit: row lock, balance write, `admin_credit` leg and the `external_inbound`
  leg against External Capital.
- `LedgerServiceInterface` - post a balanced transfer.
- `BookingChargeInterface`, `RidePayoutInterface`, `NoShowSettlementInterface`, `CancellationRefundInterface`,
  `PassengerWalletChargerInterface`, `CashRideFeeServiceInterface` - the named money paths.

## Rules

- Amounts are never re-typed by a caller: `WalletSummary::balance` is `mixed` on purpose so a decimal string
  stays a decimal string.
- Every leg is traceable to the wallet transaction that caused it; historical rows stay `NULL`.
- Reconciliation (`php artisan ledger:reconcile`) exits non-zero if any wallet's movement is unexplained. That
  is an alarm by design, not a report.

## Known gap

Two Console endpoints still embed Eloquent rows in their responses (the whole wallet row, and the wallet-request
review list). Retiring the last `Wallet`/`WalletRequest` imports needs a response-shape decision, and the
wallet-request approval workflow still writes balances from Console - it is parked with that reason in the audit
record.
