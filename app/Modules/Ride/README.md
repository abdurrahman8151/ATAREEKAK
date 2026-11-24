# Ride

The ride lifecycle: the booking, the trip, the completion, and everything that can go wrong on the way
(no-show, cancellation, conflict).

## What it owns

- `Services/BookingService`, `BookingActionService`, `BookingCompletionService`, `BookingCancellationService`
  - the booking state machine.
- `Services/RideService`, `RideCompletionService`, `RideCancellationService` - the trip itself.
- `Services/Noshowservice`, `NoShowReportService`, `NoShowConflictService`, `NoShowPenaltyService` - the
  no-show surface (this is the code audit sec 244 refused to publish as one broad port).
- `Domain/Score` (`ScoreService`, `ScorePolicyFactory`, the policies) and `Domain/Policies` - the driver
  score.
- `Services/RideSearchService`, `RideValidationService`, `GeocodingService`, `RouteCalculationService`,
  `ArabicPlaceNameService` - discovery and validation.
- `Repositories/RideRepository`, `Http/Controllers` - persistence and the passenger-facing endpoints.
- `Events/RideCreated`, `Events/RideBooked`, `Events/RideCancelled` - the three broadcast events, moved here
  with their publisher (ADR 0009). All three use `SerializesModels` and are queued for broadcast, so they are
  listed in [ARCHITECTURE section 12](../../../docs/ARCHITECTURE.md) under "drain the queue before deploying".

## Public Contracts (4)

| Port | Implemented by | Consumers today |
|---|---|---|
| `RideRepositoryInterface` | `RideRepository` | Console, Identity (read-only ride views) |
| `ScoreServiceInterface` | `Domain/Score/ScoreService` | Identity (`ProfileController`), Console (`StaffUserProfileService`) |
| `UserRideStatsInterface` | `UserRideStatsService` | Identity (`ProfileController`), Console (`StaffUserProfileService`) |
| `NoShowReportInterface` | `Noshowservice` | the `ResolveExpiredNoshowReports` artisan command |
| `GeocodingServiceInterface` | `GeocodingService` | internal; published so tests and future consumers can fake it |

`BookingView` and `RideView` used to sit in this folder. Stage 5 moved them to `app/Contracts/` because they are
shared READ-ONLY vocabulary for a row - Money types its parameters against them - not a service Ride publishes.
That is a move into the kernel, not a dependency removed: Money still depends on Ride for the escrow ledger.
`CashRideCounter` stayed here but lost its only cross-module caller: Ride counts its own cash rides and passes
the number to Money's fee tiers (stage 5 B1(c)).

## Who may call it

Identity and Console, through the ports above. Ride's own dependencies are the widest in the graph -
Money (5 ports: charge, refund, payout, cash fee, no-show settlement), Communication (`NotificationPort`,
12 files), Identity (`DocumentVerificationInterface`, for driver-document checks at booking time).

That `Ride <-> Identity` pair is one of the four declared uncut cycles: Ride checks documents through
Identity, and Identity's profile page renders Ride's stats and score. The declared layer order is
`Communication < Identity < Money < Ride < Console`, and the references that point up it are pinned in
`tests/Feature/AppFuture/ModuleLayeringTest` with their reasons (ADR 0013).

## What it may not do

- Import another module's implementation. The one place that used to is fixed: `RideServiceProvider`
  imported `App\Services\Score\ScorePolicyFactory`, which no longer existed (stage 1.5 item 1).
- Grow the no-show surface into a broad port by convenience. `NoShowReportInterface` publishes exactly
  the scheduled sweep; the penalty and confirmation calls stay inside the module until a batch decides
  their domain API deliberately.
- Change a score policy, a cancellation tier or a no-show penalty. Those are policy decisions recorded
  in the audit, not refactoring.

## Tests

`tests/Feature/Rides`, `tests/Feature/Bookings`, `tests/Unit/Domain` (score and policies), plus the Ride
ratchets (`RV13`, `RV16`, `RV30`, `RV38`).

## Owned tables

Every table has one owner ([ADR 0006](../../../docs/adr/0006-strict-data-ownership-schema-frozen.md));
the schema is frozen and each model declares its table explicitly.

| Model | Table | Class location |
|---|---|---|
| `Ride` | `rides` | `App\Models` - ownership enforced, class move pending |
| `Booking` | `bookings` | `App\Models` - ownership enforced, class move pending |
| `NoshowReport` | `noshow_reports` | `App\Models` - ownership enforced, class move pending |
| `ScoreTransaction` | `score_transactions` | `App\Modules\Ride\Models` |
| `UserScore` | `user_scores` | `App\Modules\Ride\Models` |
