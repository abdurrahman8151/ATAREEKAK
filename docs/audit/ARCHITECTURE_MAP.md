# Syride — Architecture & Bounded-Context Map (AF-2′)

> Written as part of the app-future audit remediation. This is the reference the
> enforcement file `tests/Feature/AppFuture/BoundaryDependencyTest.php` protects.
> The bug audit lives in `SYRIDE_COMPREHENSIVE_AUDIT.md`; the architecture analysis
> in `APP_FUTURE_AUDIT.md`. This file is the *agreed structure*; the test is the
> *machine-checked rule*.

## 1. Bounded contexts (6 + shared kernel)

| Context | Owns | Today lives in |
|---|---|---|
| **Ride lifecycle** | create/search/book/cancel/complete/no-show | `Services/Ride`, `Domain/Score`, `Repositories/RideRepository` |
| **Money** | wallet, escrow, ledger, top-up/withdraw, cash fees | `Services/Wallet`, `Services/Payment`, `Domain/Payment` |
| **Identity & access** | auth (JWT passenger / staff-JWT), OTP, KYC verification | `Services/Auth`, `Services/Staff`, `Services/Verification`, `Services/Otp` |
| **Communication** | notifications, chat, push, email | `Services/Notification`, `Services/PushNotification`, `Services/Chat`, `Listeners`, `Events`, `Jobs` |
| **Console (admin/staff)** | dashboards, moderation, exports | `Controllers/API/Admin*`, `Controllers/API/Staff`, `Services/Admin` |
| **Shared kernel** | models, enums, DTOs, interfaces | `app/Models`, `app/Enums`, `app/DTOs`, `app/Interfaces` |

## 2. Allowed direction (ratchet baselines are *tolerated debt*, not permissions)

```
Controllers ──► Services ──► Repositories ──► Models
     │             │              │            │
     ▼             ▼              ▼            ▼
   (Form)       DTOs/Enums     Interfaces    Enums(2)
Domain (pure, target) ──► Enums, DTOs only (today: Models ×10, Services ×2)
Async (Events/Listeners/Jobs/Notifications) ──► Services, Models (never Http: 0)
```

Rules enforced (counted files, baseline = max today, may only shrink):
- `request_below_http = 0` — HARD. Nothing below the HTTP layer imports `Illuminate\Http\Request`.
- `request_in_services = 1` — grandfathered `AdminAuthService`; dies at AF-4.
- `domain_to_http = 0` — HARD.
- `async_to_http = 0` — HARD.
- `domain_to_services = 2` — dies at AF-6 (strategies must call a payment *interface*, not the concrete ledger).
- `domain_to_models = 10` — the Domain/Score policies type-hint Eloquent; shrinks as AF-6 replaces them with DTOs.
- `repos_to_services = 2` — `PasswordReset→Jwt`, `RideRepo→Geocoding`; die at AF-6 (repositories must be persistence-only).
- `controllers_to_models = 21 of 38` — the extraction target; shrinks as AF-6/7 move reads behind services.
- `models_to_enums = 2` — `Complaint`, `Employee`.

**Ratchet behavior:** any new violation fails the suite. If a fix *removes* a violation,
the suite fails until the human lowers `BASELINES` — improvement is claimed deliberately,
so debt can never be silently re-introduced at the old number.

## 3. The four modularity defects from the audit, assigned to AF steps

| Defect | Step |
|---|---|
| Two ledger dialects (`escrow_release` vs `escrow_released`) | AF-6 `LedgerEvent` enum + Money VO into services |
| Three search implementations, one dead | AF-4 — owner decision needed: which survives |
| Three auth systems, Sanctum unused | AF-4 — owner decision: delete or document |
| 21 controllers doing model queries inline | AF-6/AF-7 service extraction |
| Domain island (Money VO unused) | AF-6 |

## 4. Style and static-analysis gates (CI)

- `pint.yml` — Pint in **check mode** on `app/`, `database/`, `routes/`, `tests/`
  (Laravel preset; the whole tree was made compliant in the AF-2′ sweep — 392 files).
  Style violations block the PR.
- `tests.yml` — `php artisan test` (MySQL service, same as Sonar) must pass; the
  boundary + pin suites live in this suite and therefore gate every future change.
- **Larastan is deliberately deferred**: it is not installed and running it today over a
  392-file untyped surface would produce noise nobody acts on. The honest sequencing is
  AF-6/AF-7 (which adds types on the way) → then `larastan` level 5 as a *second* CI gate.
  (Owner decision: composer network may be unavailable in this env — flagged, not skipped
  silently.)

## 5. What this document does NOT yet do

It records the *target* shape. It does not move files. File moves only ever happen as
part of a verified AF item with tests green before and after.
