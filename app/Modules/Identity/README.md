# Identity module

Owns the user's credentials, tokens, profile, photos and verification state, and is the module that issues
tokens. `App\Models\User` is deliberately **not** owned here: it is the authenticated principal
([ADR 0007](../../../docs/adr/0007-user-is-the-authenticated-principal.md)).

## Owned tables

| Model | Table | Status |
|---|---|---|
| `Otp` | `otps` | pending (class still in `app/Models`) |
| `RefreshToken` | `refresh_tokens` | pending |
| `StaffRefreshToken` | `staff_refresh_tokens` | pending |
| `Photo` | `photos` | pending |
| `Profile` | `profiles` | pending |
| `ProfileComment` | `profile_comments` | pending |
| `UserRating` | `user_ratings` | pending |

## Contracts others may call

- `UserRepositoryInterface` - user reads.
- `ProfileRepositoryInterface`, `PhotoRepositoryInterface` - profile surfaces.
- `OtpRepositoryInterface`, `EmailOtpServiceInterface`, `OtpMailInterface` - signup and verification.
- `PasswordResetRepositoryInterface`, `VerificationRepositoryInterface`, `VerificationApprovalInterface`,
  `DocumentVerificationInterface` - the verification lifecycle.
- `EmployeeAuthInterface`, `StaffJwtInterface`, `StaffTokenVerifierInterface` - staff authentication.
- `TokenRevokerInterface`, `StaffTokenCleanupInterface`, `UserTokenCleanupInterface` - revocation and cleanup
  (the cleanup commands are kernel entrypoints that call these).

## Rules

- Token claims are minted here and nowhere else. A module that needs to know who the caller is reads the
  authenticated principal; it does not decode a token itself.
- Revocation is a write contract: bumping `token_version` happens in `EmployeeTokenVersionBumper` (Console) or
  `TokenRevokerInterface` (Identity), never by a caller writing the column.
- `User` is imported by other modules only as a parameter/return type, never queried for its relations.

## Known gap

CU-3 (Identity owns the user *directory*) is not built yet: modules that need another user's name or email still
read the `users` table through their own paths. The target is `Identity\Contracts\UserDirectory` returning a
`UserSummary`, with batched lookups so no list renders a query per row.
