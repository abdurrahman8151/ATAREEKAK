# Console

The back office: the staff-facing admin surface, and the employee/support plumbing it sits on.

## What it owns

- `Http/Controllers` - the admin and staff endpoints (dashboard, users, drivers, trips, wallet requests,
  refunds, complaints, reviews, chat, documents, exports).
- `Services/Admin*` - one service per admin area (dashboard, wallet, report, export, trip, driver, user).
- `Services/EmployeeManagementService` - employees, shadow users for chat, staff accounts.
- `Services/BanService` - bans, including the expired-ban lift that every authenticated request checks.
- `Services/SupportAgentRoutingService` - who the least-loaded support agent is.
- `Services/ReviewModerationService`, `StaffComplaintService`, `ComplaintService`,
  `StaffCancellationService`, `StaffUserProfileService`.
- `Repositories/EmployeeRepository`, `ComplaintRepository`.

## Public Contracts (5)

| Port | Implemented by | Consumers today |
|---|---|---|
| `EmployeeManagementInterface` | `EmployeeManagementService` | Communication (`ContactController`), `StaffJwtMiddleware` |
| `SupportAgentRoutingInterface` | `SupportAgentRoutingService` | Communication (`ContactController`) |
| `EmployeeRepositoryInterface` | `EmployeeRepository` | Identity (`EmployeeAuthService`) |
| `ComplaintRepositoryInterface` | `ComplaintRepository` | internal |
| `BanLifterInterface` | `BanService` | `JwtAuthHandler` (stage 1.5 item 3) |

## Who may call it

Communication (support routing, shadow users) and Identity (employee records). Console itself is the
biggest consumer in the graph: Communication (3 ports: notifications and chat), Identity (6: staff admin,
documents, login, bans), Money (3: wallet requests, ledger, cancellation refunds) and Ride (2: score and
ride stats on the staff user profile).

One of its edges is a declared uncut cycle - `Console <-> Identity` - with the reason recorded in
`ModuleGraphTest`, because the back office needs the domain it administers and the domain needs the back
office's employee records. The `Communication <-> Console` cycle was CUT in stage 4 D-1: Console implements
Communication's `SupportDeskDirectory`, so Communication names no Console class.

## What it may not do

- Import another module's implementation.
- Grow a ban, refund or identity rule inside an admin controller: the admin surface calls the owning
  module's port, so the rule stays in one place.
- Register dev-only commands in production: `app/Console/Testing` is a separate directory loaded only in
  `local` and `testing` (ADR 0005), and it is the single directory-level entrypoint exemption.

## Tests

`tests/Feature/Admin`, `tests/Feature/Staff`, `tests/Feature/Console`, plus the admin ratchets
(`RV13`, `RV16`, `RV28`, `RV29`, `RV30`).

## Owned tables

Every table has one owner ([ADR 0006](../../../docs/adr/0006-strict-data-ownership-schema-frozen.md));
the schema is frozen and each model declares its table explicitly.

| Model | Table | Class location |
|---|---|---|
| `Complaint` | `complaints` | `App\Models` - ownership enforced, class move pending |
| `ComplaintAttachment` | `complaint_attachments` | `App\Models` - ownership enforced, class move pending |
| `Employee` | `employees` | `App\Modules\Console\Models` (moved) |
