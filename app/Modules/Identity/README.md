# Identity

Who the user is, and who the staff member is: authentication, tokens, OTP, verification (KYC) and
profiles. Two doors, one module: the passenger door and the staff door do not share secrets, tables or
claims, but they do share the identity model.

## What it owns

- `Services/JwtService` - passenger access/refresh tokens, the token-version check, the user cache.
- `Services/StaffJwtService` - staff tokens: their own secret, audience and claims.
- `Services/EmployeeAuthService` - the staff login path.
- `Services/EmailOtpService`, `TextMeBotOtpService`, `Repositories/OtpRepository` - OTP issue and verify.
- `Services/DocumentVerificationService`, `VerificationApprovalService`, repositories - KYC.
- `Services/ProfileUpdateService`, `ProfileInteractionService` - profile reads and writes.
- `Http/Controllers` - login, registration, logout, refresh, password reset, OTP verification, profile,
  documents.

## Public Contracts (17)

| Group | Ports | Consumers today |
|---|---|---|
| Repositories | `UserRepositoryInterface`, `ProfileRepositoryInterface`, `PhotoRepositoryInterface`, `VerificationRepositoryInterface`, `OtpRepositoryInterface`, `PasswordResetRepositoryInterface` | Console (staff admin, document review) |
| Staff auth | `EmployeeAuthInterface`, `StaffJwtInterface`, `TokenRevokerInterface` | Console (login, ban, staff admin) |
| Token lifecycle | `UserTokenCleanupInterface`, `UserTokenVerifierInterface`, `StaffTokenCleanupInterface`, `StaffTokenVerifierInterface` | the two cleanup commands, `JwtAuthHandler`, `StaffJwtMiddleware` (stage 1.5 item 3) |
| OTP / mail | `EmailOtpServiceInterface`, `OtpMailInterface` | Money (`WalletController`), kernel `App\Mail\OtpMailer` |
| Verification | `DocumentVerificationInterface`, `VerificationApprovalInterface` | Ride (booking-time document check), Console (dashboard and staff approval) |

The token lifecycle ports are split user/staff on purpose: separate secrets, separate tables, separate
`ver` claims. One generic port would have to be generic over the subject type and would blur exactly the
boundary that matters.

## Who may call it

Console (6 ports: staff admin, documents, login, bans), Money (withdrawal OTP), Ride (document checks),
Communication (only through `NotificationPort` in the other direction - Identity *calls* Communication).
Identity's own dependencies are Communication (`NotificationPort`), Console
(`EmployeeRepositoryInterface`), Money (`PassengerWalletChargerInterface`) and Ride (`ScoreServiceInterface`,
`UserRideStatsInterface`).

## What it may not do

- Import another module's implementation. Its only outside implementation is the kernel mailer
  `App\Mail\OtpMailer`, and stage 1.5 item 4 moved that binding to the kernel catalogue
  (`App\Support\ContainerBindings`) so neither module provider names the other's Contracts for it.
- Weaken an auth rule to make a caller simpler. `JwtAuthHandler`, `StaffJwtMiddleware` and the token
  ports are behaviour-preserving wrappers around the existing checks: no claim, version or ban rule
  changed when the ports were introduced, and the tests around them (`tests/Feature/Auth`,
  `tests/Feature/Staff`, `tests/Unit/Middleware`) were the gate.

## Tests

`tests/Feature/Auth`, `tests/Feature/Staff`, `tests/Feature/Otp`, `tests/Feature/Security`,
`tests/Unit/Middleware`, plus the identity ratchets (`RV28`, `RV29`, `RV36`, `RV38`, `RV42a`).

## Owned tables

Every table has one owner ([ADR 0006](../../../docs/adr/0006-strict-data-ownership-schema-frozen.md));
the schema is frozen and each model declares its table explicitly.

| Model | Table | Class location |
|---|---|---|
| `Otp` | `otps` | `App\Models` - ownership enforced, class move pending |
| `RefreshToken` | `refresh_tokens` | `App\Models` - ownership enforced, class move pending |
| `StaffRefreshToken` | `staff_refresh_tokens` | `App\Models` - ownership enforced, class move pending |
| `Photo` | `photos` | `App\Models` - ownership enforced, class move pending |
| `Profile` | `profiles` | `App\Models` - ownership enforced, class move pending |
| `ProfileComment` | `profile_comments` | `App\Models` - ownership enforced, class move pending |
| `UserRating` | `user_ratings` | `App\Models` - ownership enforced, class move pending |

## Known gap

C3 (Identity owns the user directory) is not built: modules that need another user's name or email still
read the `users` table through their own paths. The target is `Identity\Contracts\UserDirectory` returning a
`UserSummary`, with batched lookups so no list renders a query per row. `App\Models\User` itself stays in the
kernel as the authenticated principal ([ADR 0007](../../../docs/adr/0007-user-is-the-authenticated-principal.md)).
