# Money

The ledger, wallets, escrow and every way money moves. The most load-bearing module in the project: a
bug here loses or duplicates money, so its rules are the ones the audit treats as untouchable.

## What it owns

- `Domain/Ledger` - posting keys, ledger types, double-entry posting.
- `Services` - wallet charging, escrow hold/release, refunds, cancellation refunds, driver payouts, cash
  ride fee, no-show settlement, payment strategies (`Domain/Strategies`).
- `Http/Controllers` - the wallet endpoints (balance, top-up, withdrawal with OTP).
- `Contracts/LedgerServiceInterface` etc. - the surfaces other modules are allowed to touch.

## Public Contracts (8)

| Port | Implemented by | Consumers today |
|---|---|---|
| `LedgerServiceInterface` | ledger service | Console (`AdminWalletRequestController`, `AdminWalletCharger`) |
| `BookingChargeInterface` | booking charge service | Ride (`BookingService`, `BookingCompletionService`) |
| `CancellationRefundInterface` | cancellation refund service | Ride (4 files), Console (`StaffCancellationService`) |
| `CashRideFeeServiceInterface` | cash ride fee service | Ride (3 files), Console (`AdminWalletRequestController`) |
| `RidePayoutInterface` | payout service | Ride (`RideCompletionService`, `RideService`) |
| `NoShowSettlementInterface` | no-show settlement | Ride (`NoShowPenaltyService`, `Noshowservice`) |
| `PassengerWalletChargerInterface` | `PassengerWalletCharger` | Identity (`PassengerProfileController`) |
| `PaymentStrategyFactoryInterface` | `Domain/Strategies/PaymentStrategyFactory` | Ride (`BookingService`, `BookingCompletionService`) |

## Who may call it

Ride, Console and Identity, through the ports above. Every one of those consumers needs a *result*
(a posted ledger entry, a charged wallet, a resolved settlement) - which is why none of these edges can
be replaced by an event, and why `Money <-> Identity` is one of the four declared uncut cycles.

## What it may not do

- Import another module's implementation. Its own dependencies are Communication
  (`NotificationPort`) and Identity (`EmailOtpServiceInterface` for the withdrawal OTP).
- Change an amount, a refund tier, the 95/5 split, a posting key, an escrow semantic or a ledger type
  name to make a caller happier. The ledger's vocabulary is `App\Enums\LedgerType`, and
  `RV39SeederHygieneTest` fails if a `wallet_transactions.type` literal outside it appears anywhere in
  `app/` or the seeders.

## Tests

`tests/Feature/Wallet`, `tests/Feature/Payment`, `tests/Unit/Domain`, plus the money ratchets (`RV39`,
`CommittedRowLeakGuardTest`).
