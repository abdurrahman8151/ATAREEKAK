# Ride module

Owns rides, the bookings that reserve seats on them, and the consequences of a no-show: the report, the score
movement and the user's score total.

## Owned tables

| Model | Table | Status |
|---|---|---|
| `Ride` | `rides` | pending (class still in `app/Models`) |
| `Booking` | `bookings` | pending |
| `NoshowReport` | `noshow_reports` | pending |
| `ScoreTransaction` | `score_transactions` | pending |
| `UserScore` | `user_scores` | pending |

## Contracts others may call

- `RideRepositoryInterface` - the ride reads (search, detail, driver/ passenger views).
- `NoShowReportInterface` - record and read no-show reports.
- `UserRideStatsInterface` - a user's ride statistics.
- `ScoreServiceInterface` - score movements.
- `GeocodingServiceInterface` - route/coordinate work.
- `BookingEscrowLedger` - the escrow column on a booking row: `conditionallyDebit()` with the
  `escrow_held >= :amount` guard in SQL, and `heldFor()`. The escrow is a column on the booking row (the schema
  is frozen), so the conditional debit lives here, in the module that owns the row.

## Rules

- Escrow arithmetic happens in `BookingEscrowWriter`, never in a caller: a debit that is not conditional on the
  held amount is a way to release money twice.
- A no-show settlement asks Ride for the escrow debit and Money for the ledger legs; neither module reaches into
  the other's tables.
- Ratings and comments about a ride belong to Identity (`UserRating`, `ProfileComment`), not here.
