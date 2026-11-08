# Console module

The admin and staff surface: dashboards, reports, the complaint queue, employee management and the wallet
review screens. It is the module most tempted to read everything, and therefore the one with the largest
reporting allowance - a named, shrink-only list of files that may still name a foreign model.

## Owned tables

| Model | Table | Status |
|---|---|---|
| `Complaint` | `complaints` | pending (class still in `app/Models`) |
| `ComplaintAttachment` | `complaint_attachments` | pending |
| `Employee` | `employees` | pending |

## Contracts it publishes

- `ComplaintRepositoryInterface` - complaint reads.
- `ComplaintRecorder` - record a complaint (one verb, returns the new id). Ride's no-show path uses it.
- `EmployeeRepositoryInterface`, `EmployeeManagementInterface` - employee reads and management.
- `EmployeeTokenVersion` - bump an employee's token version (revocation).
- `BanLifterInterface`, `SupportAgentRoutingInterface` - support surfaces.

## Contracts it calls

Money (`WalletDirectory`, `AdminWalletCredit`, `LedgerAggregates`, `LedgerReconciliation`,
`WalletTransactionPager`, `DriverEarningsReader`), Ride (`NoShowReportInterface`, `UserRideStatsInterface`),
Identity (`EmployeeAuthInterface`, `StaffJwtInterface`, ...) and Communication (`NotificationPort`) - all
through their contracts, never their tables.

## Rules

- **Reporting files are an allowance, not a right.** `ModelOwnershipTest` keeps a named list of Console files
  that may still name a foreign model, records which models each one touches, and fails if a listed file grows a
  new one or an unlisted file appears. Removing an entry is the goal; adding one needs a reason in the same
  commit.
- Admin money screens call Money's contracts. `AdminWalletCharger` performs no writes itself any more: the credit
  happens inside `AdminWalletCredit`.
- Two endpoints are **parked** because their responses serialise Eloquent rows: `showWalletTransactions`
  (whole wallet row) and the wallet-request review list (paginated requests with `user` and `wallet`). The
  wallet-request approval workflow also still writes balances from here - it is the last Console-side money
  write, and moving it into Money is a decision, not a refactor.

## Dev-only tools

`app/Console/Testing` is exempt as a directory, not per file
([ADR 0005](../../../docs/adr/0005-console-testing-directory-exemption.md)): it fabricates state, is registered
only under `local`/`testing`, and `DevCommandRegistrationTest` pins that guard.
