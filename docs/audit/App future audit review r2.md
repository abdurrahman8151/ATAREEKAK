# SyRide — Review Addendum R2

Delta to `APP_FUTURE_AUDIT_REVIEW.md` (called **R1** below). Read R1 first; this file only changes or adds.
Date: 2026-09-29. Same working rules as R1 §0 and `AGENTS.md`: one task at a time, explain → smallest fix → verify → `VERIFIED FIX` / `VERIFIED ROLLBACK`, log in `docs/audit/`, no secrets in the record. New tests go in `tests/Feature/Review/`; never edit an existing assertion just to turn it green (list it in RV-35 instead).

> **Execution status lives in §12 (progress table).** §9–§11 are the per-task logs
> (Wave-0 check results, RV-07, RV-06) with the checks, causality and regressions that
> back each terminal state. Current task and next task are stated in §12.

---

## 0. Inputs and limits

**Seen this round:** `tests/` (~130 files), ~60 more migrations, all seeders/factories, `SYRIDE_COMPREHENSIVE_AUDIT.md`, `ARCHITECTURE_MAP.md`, every `k6-load/*.js`, `perf-results/*.json`.
**Not seen this round:** `app/` source, `routes/api.php`. R1 was written with `app/` visible; anything below about app code comes from R1, from the audit log, or is **[inferred from tests]** and must be confirmed by the matching `V` check before coding.
**Still missing:** `config/system_admin.php` (V8), the k6 script that produced `perf-results/*`, production env variable *names*.

---

## 1. Changes to R1

### 1.1 Upgrades (evidence is now stronger)

| R1 item | New evidence | New status |
|---|---|---|
| V2 / RV-25 — no spatial index on `rides` | `2025_05_20_143208_fix_ride_spatial_columns` drops `pickup_location` and `destination_location` (their spatial indexes go with them) and recreates plain `geometry` columns; none of the migrations I can see re-adds an index. | Treat as confirmed; run V2 only to record it. |
| V5 / RV-13 — `Handler` strips validation `errors` | The audit log lists `OtpTest` (2), `TextMeOtpControllerTest` (2), `EmailVerificationControllerTest` (2) as "pre-existing, 422-vs-401 / validation-shape mismatches", all `assertJsonValidationErrors` failing with "array has the key 'phone_number' / 'email'". Those are FormRequest endpoints returning 422 without the field bag. The audit never fixed them. | Treat as confirmed; V5 only records it. |
| RV-01 — KYC on public disk | `VerificationControllerTest`, `DocumentControllerTest`, `ComplaintControllerTest`, `ComplaintAttachmentTest` all use `Storage::fake('public')`; `ComplaintAttachmentTest` asserts the URL contains `storage`. Complaint evidence photos are public too. | Confirmed; RV-01 scope grows (see 1.3). |
| RV-24/25 — fixtures disagree about geometry | 14 test files each carry their own raw `INSERT INTO rides` with three conventions: `POINT(lat lng)` + SRID 4326 (RideTest, RideControllerFullTest, RideResourceTest, EPayPaymentStrategyTest…), `POINT(lat lng)` **without** SRID (BookingTest, WalletTransactionServiceTest, RideFactory), `POINT(lng lat)` + 4326 (RideSearchServiceTest, T4 tests, seeders). | RV-34 `RideBuilder` (below). |

### 1.2 Corrections (R1 was wrong or imprecise)

1. **RV-17 search contract.** R1 said the k6 search request "sends wrong params → 422". The tests show search is **`POST /api/rides/search`** with `source_lat, source_lng, dest_lat, dest_lng, departure_date (Y-m-d), seats_required`. Every k6 script sends **`GET`** with `pickup_lat/pickup_lng/destination_lat/destination_lng/seats`. Unless `routes/api.php` also defines a GET variant (V11), the 20 % "search" traffic is actually `GET /api/rides/{rideId}` with `rideId="search"` → **404**, which `setResponseCallback` counts as success. So the biggest slice of the "load" never touched search.
2. **RV-25 / AF-4a fixture "fix" is unproven.** AF-4a says the old `RideSearchServiceTest` fixture `POINT(33.5138 36.2765)` was "397 km off" from the query point and flipped it to `POINT(36.2765 33.5138)`. **397 km is the distance between a point and its own transpose — it is the same number whichever axis order MySQL uses**, so it proves nothing about which side is wrong. The only discriminating evidence is the T4-2 test `test_near_location_widens_with_the_radius`: a ride written `POINT(36.2765 33.5138)`, queried from `nearLocation(36.2021, 37.1343, 300)` (the scope builds `POINT(lng lat)`), and it **matches at 300 km**. True Damascus–Aleppo is ≈ **309 km**; if MySQL reads SRID-4326 WKT as (lat, lng) the same pair is ≈ **258 km** and matches. The test's own docblock says "~150 km" which is wrong either way. This points to MySQL reading (lat lng) while the app writes (lng lat). **Not conclusive → V1 decides. Freeze all geometry-fixture edits until V1 is recorded.**
3. **RV-26 vs audit T2-2.** T2-2 concluded `sycash` "is a system wallet, not a persona". `SpecialAccountSeeder`'s docblock says the opposite (`sycash : financial / wallet management only`, a real Employee). The contradiction stays an **owner decision** (R1 §6 #1); do not resolve it in code.
4. **Load-test scale, R1 RV-17.** `perf-results/{A,B,C}*.json` are a two-endpoint (`rides`, `transactions`), closed-model test (750 VUs, ~1 s iteration incl. sleep, 100 % 2xx). It is not the 13-API mix and its script is not in the repo. Throughput of a closed model is bounded by VUs ÷ (think + latency), so A/B/C rps (518/538/531) cannot show capacity; only p95 (415/323/337 ms) is informative, and B→C shows no gain from cache + LB.

### 1.3 Amendments to existing tasks (add these acceptance items)

**RV-01**
- Passenger/driver KYC submission currently returns **201 with an empty payload** and sets `verification_status=pending` (`test_passenger_verification_without_files_still_submits`; the driver "twice" test posts `[]`). Require the full document set (passenger: `face_id`, `back_id`; driver: + `license`, `mechanic_card`, car fields) before `pending`. **Owner decision #11.**
- `GET /profile/verify/status/{nonexistent}` returns **500** (a test pins it) → 404.
- Complaint attachments move to the private disk with signed access, same as KYC.

**RV-02**
- A settled report can leave the scheduler retrying forever: if SyCash is empty the second settlement throws, the per-report `catch` logs, and the report stays `pending` every minute. Add terminal states `void` and `failed` (attempt counter) — note `noshow_reports.status` is a DB ENUM without `void`; see RV-40.
- Test skeleton (move `escrowedBooking()` out of `MoneyPathBatchTest` into the shared trait first):
```php
public function test_no_show_settlement_skips_a_booking_already_released(): void
{
    [$ride, $b1] = $this->escrowedBooking();   // 50,000 in SyCash
    [, $b2]      = $this->escrowedBooking();   // second booking: another 50,000 must survive
    $report = NoshowReport::create([/* driver reports passenger on $b1, expires_at => now()->subMinute() */]);

    $this->withToken($this->passengerToken)->postJson("/api/bookings/{$b1->id}/passenger-confirm")->assertOk();
    $driverAfter = (float) $this->driverWallet->fresh()->balance;      // 47,500
    $syCashAfter = (float) $this->syCash->fresh()->balance;            // 50,000 (b2 only)

    app(Noshowservice::class)->resolveExpiredReports();

    $this->assertSame($driverAfter, (float) $this->driverWallet->fresh()->balance);
    $this->assertSame($syCashAfter, (float) $this->syCash->fresh()->balance); // b2 escrow untouched
    $this->assertSame('void', $report->fresh()->status);
}
```
Pre-fix this should fail by moving 50,000 more out of SyCash, i.e. out of `$b2`'s escrow.

**RV-04**
- `token_version` defaults differ: users default **1** (`2026_05_10_172303`), `UserFactory` sets 0, employees default 0 (seeders set 0 or 1). A staff token for employee #N and user #N can therefore share `sub` and `ver`. The suite only tests user→staff (`StaffAdminIdentityAttributionTest::test_user_token_is_rejected_by_staff_guard`). Add the missing direction: **staff access token on `/api/user` → 401** (write the test first, expect it to fail today, then fix with `aud`).

**RV-07**
- ~70 JWTs in a public repo are an offline oracle for HS256 secret strength (hashcat mode 16500). If `JWT_SECRET` is short or shared with dev, rotate to ≥ 32 random bytes (`php artisan jwt:secret` exists).
- The OpenRouteService key sits in `phpunit.xml` because feature tests reach the network (`RideTest::test_verified_driver_can_create_ride` has no `Http::fake`). Fix the cause (RV-37), then rotate the key.
- `BookingTest::seedAdminWallets` hard-codes a personal Gmail address and `admin123` / `sycash123`. Remove.
- The scanner must cover JWTs: regex `eyJ[A-Za-z0-9_-]{10,}\.eyJ` over the whole repo. `SeedCredentialsBatchTest` only covers seeders and 5 of the k6 files.

**RV-13**
- Tests that bless 5xx for business-rule violations (fix together with the Handler): `ChatTest::test_cannot_start_conversation_with_self` (comment says the app returns 500), `VerificationControllerTest::test_status_for_nonexistent_user_returns_error` (asserts 500), and a family of `assertNotEquals(201|200|500, …)` denied-path checks in `BookingTest`, `ProfileTest`, `NotificationTest`. After the exception mapping lands, replace each with the exact status (403/404/409/422) and add a ratchet on `assertNotEquals(` in `tests/Feature`.

**RV-14**
- `rides.price_per_seat` is `decimal(8,2)` (max 999,999.99); T3-2 did not widen it; `create-with-route` has no max; `CreateRideRequest` max is 100000 (tested). One bound in config, widen the column with the T3-2 pattern (mysql-guarded, never shrinks).
- `rides.distance` / `duration` are unsigned ints (`distance` commented "Meters") but fixtures insert `320.5` and `320500`. Decide units (meters, seconds) and normalise fixtures.

**RV-17 — replace the request contracts with these (from the tests)**

| k6 today | Real contract |
|---|---|
| `GET /api/rides/search?pickup_lat…` | `POST /api/rides/search` `{source_lat, source_lng, dest_lat, dest_lng, departure_date, seats_required}` |
| `POST /api/rides/{id}/book {seats, pickup_lat, pickup_lng}` | `{seats, communication_number (09xxxxxxxx), idempotency_key (uuid)}` |
| `POST /api/rides/create-with-route {from_lat…}` / `{origin_lat…}` | `{pickup_lat, pickup_lng, destination_lat, destination_lng, pickup_address, destination_address, departure_time (now+5 min … +30 d), available_seats, price_per_seat, vehicle_type, payment_method, booking_type, communication_number, route_index}` |
| `POST /api/otp/send {phone: '+96277…'}` | `{phone_number: '09xxxxxxxx'}` — Jordanian numbers fail the Syrian-only validation |
| `departure_time: '2026-12-15 09:00:00'` | 77 days out → rejected (max 30 days) |

Also:
- Scripts use Amman coordinates and numbers; validation and the address enum are Syria-only.
- `create-with-route` calls OpenRouteService (or its straight-line fallback): load tests need a fake routing driver (`ROUTING_DRIVER=fake`) or they burn provider quota and measure a third party.
- Three different databases were used: hammer/70pct ride ids up to 150,173 and booking ids to 21,267 (the 500k/1M `BulkRideSeeder` DB), capacity-validation ids 2–48, stage1-900vu-confirm ids 132–652 with users 626–673. Record a dataset fingerprint (row counts) with every run.
- `Syride-70pct-stage1.js` carries five different targets in one file (header 618/Stage 2, name stage1, config 643, comment 489/Stage 1, "699"). Thresholds `p(95)<60000` can never fail.
- Note for reading `--summary-export` JSON: `thresholds: {"…": false}` means *not failed*.

**RV-19**
- Pinned-as-expected bugs in `AdminDriverServiceTest`: `stats.total_rides` capped at 5 by the eager-load limit (`…_capped_by_the_five_ride_eager_load_limit`), `suspended_drivers` always 0, unknown `period` silently treated as week. Fix all three (`withCount`, real suspended definition, validate `period`) and flip the tests.
- Driver "earnings" are recomputed as `seats × price × 0.95` for completed bookings instead of read from the ledger, so cash rides, cancellation payouts and fee changes are misreported.
- The 5 % platform fee is hard-coded in `WalletTransactionService`, `AdminDriverService`, `SyrideSeeder` and docs → `config/fees.php` + `Money::percentage()` (belongs to RV-20).

**RV-25 — decision table once V1 is recorded**
- V1 ≈ 258 km (MySQL reads lat, lng): production rows are transposed. Write `POINT(lat lng)` through one `GeoPoint::wkt()` helper (or use `'axis-order=long-lat'` everywhere), backfill with `ST_SwapXY`, keep AF-4a's fixture only if it is rewritten through the helper.
- V1 ≈ 309 km (function ignores SRS order): AF-4a stands, but then the T4-2 radius test cannot have passed at 300 km — **re-run the T4 batch on MySQL and find out why**.
- Either way: declare the columns `POINT SRID 4326 NOT NULL` so wrong-SRID inserts fail at write time (fixtures mix SRID 0 and 4326 today), add a **golden-distance test** (Damascus→Aleppo = 309 km ± 3) that fails on the wrong order, and route every fixture through RV-34's `RideBuilder`.

**RV-26**
- `StaffJwtMiddleware::handleAdminToken()` **[inferred from `StaffJwtMiddlewareTest` + audit T3-8]** accepts a *user* JWT whose email equals `config('admin.system_admin.email')` and auto-creates a `SYSTEM_ADMIN` Employee. T3-8 says that config key no longer exists, so it is inert in production, but tests re-enable it with `Config::set`. Delete the path (privilege by email-string equality) and move its tests to real employee tokens.

**RV-33**
- The boundary ratchet counts *files*, not edges, and cannot see a fully-qualified class without a `use`. Move it to **Deptrac** (or phpat/Arkitect) with a baseline file.
- The audit deferred Larastan because level 5 over 392 files "produces noise". A **baseline** solves exactly that: `phpstan analyse --generate-baseline` at level 5, CI fails only on new errors. Do it in CI (the agent sandbox has no composer network; CI does).

---

## 2. Why the suite is red (374 errors / 56 failures) — two root causes

The audit log records both causes but treats them as "pre-existing". They are mechanical and fixable in one task (RV-34).

**Cause A — system-wallet fixture reads removed config keys.** `seedAdminWallets()` / inline setUp do `config("admin.{$type}")['email'|'password'|'first_name']`; `config/admin.php` now only has `phone` and `wallet_prefix` → `Undefined array key "email"` in `setUp`. Files with no `Config::set` (all of their tests error): `WalletTest`, `RideTest`, `RideControllerFullTest`, `RideResourceTest`, `WalletTransactionServiceTest`, `StaffComplaintControllerTest`, `StaffOperationsControllerTest` ≈ **220 tests** (39 + 27 + 10 + 13 + 16 + ~39 + ~80). Working reference already in the repo: `PassengerConfirmCompletionTest::setUp`, `AdminFinancialReportEscrowTest::setUp` (system wallets by phone, `user_id NULL`).

**Cause B — admin login helpers post a config email/password to `/api/admin/login`, but admin auth now authenticates an Employee by username.** `AdminBanControllerTest`, `AdminDriverControllerTest`, `AdminDashboardControllerTest`, `StaffAdminControllerTest`, `EmployeeManagementControllerTest` (admin JWT), `StaffAuthControllerTest::adminToken`, `NationalIdVerificationTest::adminToken` ≈ **110–130 tests**. Working reference: `AdminFinancialSurfaceAuthorizationTest::{employee,adminToken,staffToken}`.

Estimated total ≈ 340 of the 374. **Confirm with V15 before and after.**

Most of the 56 failures are stale assertions (RV-35 inventory) plus the OTP/validation-shape group (RV-13).

---

## 3. New tasks

### RV-34 [P1] Shared test-support layer (fixes both root causes)
- `tests/Support/Concerns/SeedsSystemWallets.php`: idempotently creates Primary + SyCash by `config('admin.*.phone')`, `user_id NULL`, configurable balance. No emails, no passwords.
- `tests/Support/Concerns/ActsAsStaff.php`: `staffToken(StaffRole $role)` (Employee + `/api/staff/login`), `adminToken()` (Employee `system_admin` + `/api/admin/login` with `username`), `userToken(User)`.
- `tests/Support/RideBuilder.php`: the only place that inserts a ride; writes through the model mutators / one `GeoPoint` helper; default Damascus→Aleppo; explicit SRID; used by all 14 files that carry `insertRide`.
- Move `escrowedBooking()` and `book()` helpers here (used by RV-02 tests).
- **Acceptance:** V15 error count drops by ≥ 300; no test file declares `function insertRide(`, `function seedAdminWallets(`, `function primaryToken(`; add a ratchet counting those declarations (→ 0). Do not touch existing assertions in this task.

### RV-35 [P2] Stale and bug-pinning tests (inventory; act only after the owning fix or with owner approval)

| File | Problem | Action |
|---|---|---|
| `Unit/Broadcasting/NotificationChannelTest` | pins an empty stub that throws `TypeError` | delete class + test (RV-31) or implement `join` |
| `Unit/Notifications/{RideBooked,RideCancelled,UserVerified}NotificationTest` | scaffold stubs (`toArray()` = `[]`) nothing sends | delete with the classes |
| `Unit/Providers/BroadcastServiceProviderTest` | `class_exists` / `method_exists` only | delete |
| `Unit/Domain/PaymentStrategyFactoryTest::test_direct_instantiation_fails…` | pins a design bug (factory `new`s the e-pay strategy) | fix factory to take injected strategies, then flip the test |
| `Feature/Admin/AdminDashboardControllerTest` | stale docblock, `*_currently_500s` pin bugs, config-email login | rewrite on RV-34 |
| `Unit/Enums/StaffRoleTest`, `ComplaintTypeTest` | assert 3 roles/levels 3-2-1 and 8 types; enums have SYCASH / 9 types | update to enum truth |
| `Unit/Enums/ComplaintTest` (namespace `Tests\Unit\Models`), `Unit/Models/NotificationTest` (`Models`), `EmployeeManagementServiceTest` (`Services\Staff`), `StaffAdminControllerTest` / `ReviewModerationServiceTest` (`Staff`) | namespace ≠ path | fix namespaces; add a test that namespace == path |
| `Feature/Rides/RideControllerFullTest` | 2 skipped tests, expects `awaiting_confirmation` confirmable (contradicts T1-1) | rewrite |
| vanity: `TrustHostsTest`, `JwtSecretCommandTest` signature reflection, `RideCancelledNotificationTest::via_does_not_throw` | assert nothing useful | delete or fold |
| `T*/UntangleBatchTest::test_reporting_a_no_show_before_the_hour_gate_is_refused` | builds a `Booking` with `pickup_stop_id, total_price, amount_paid, payment_method, booking_code, passenger_phone` — none exist in the schema; mass assignment silently drops them | clean up; RV-38 catches this class |
| `RateLimiterIdentityKeyTest` docblock | says throttling is disabled globally (T4-1 removed that) | fix comment |

### RV-36 [P1, V12] Notification endpoints are no-ops under JWT
`NotificationTest` says `bulkAction()` "filters by `auth()->id()`, which this app's JWT middleware never populates — same known quirk as `test_can_mark_notification_as_read` and `test_can_delete_notification`", and those tests only assert "not 500". `JwtAuthMiddleware` sets the request user resolver, not the default guard, so `auth()->id()` is `null`. **[inferred]** `POST /notifications/{id}/read`, `DELETE /notifications/{id}` and `bulk-action` therefore never act on the caller's rows (or 404).
**Fix:** use `$request->user()`; grep-ratchet `auth()->` / `Auth::id()` / `Auth::user()` in `app/Http` and `app/Services` (→ 0); strengthen the three tests to assert the row was actually marked/deleted and that another user's row is untouched (IDOR).

### RV-37 [P2] Test determinism and hermeticity
- `putenv('EMAIL_OTP_MODE=testing')` in `SignupPasswordOverwriteTest`, `ResetPasswordControllerTest`, `EmailVerificationControllerTest` (and `putenv('MAPBOX_ACCESS_TOKEN…')` in `ArabicPlaceNameServiceTest`) leaks across the whole PHP process, so results depend on test order. This is the real cause of the "environment artifact" the audit saw on `AuthTest::test_user_can_register` (500 real-SMTP when run alone, 201 after another suite leaked the flag). Put `EMAIL_OTP_MODE`, `WALLET_OTP_MODE`, `MAIL_MAILER=array` in `phpunit.xml` `<env>`; replace every `putenv` with `config([...])`; ratchet `putenv(` in `tests/` (→ 0).
- `Http::preventStrayRequests()` in `TestCase::setUp`; fake routing/geocoding/FCM at the container level (`ROUTING_DRIVER=fake`). Any test that fails is a hidden network dependency.
- CI runs the suite twice with `--order-by=random` and prints the seed.

### RV-38 [P2] Eloquent strictness outside production
`Model::shouldBeStrict(! app()->isProduction())` in `AppServiceProvider::boot()` (lazy-loading, silently-discarded attributes, missing attributes); in production only `handleLazyLoadingViolationUsing` → log. Roll out tests first, fix what surfaces (expect: N+1 from RV-24, `Complaint` dropping `ride_id`/`complained_id`, the fixture above). This is also what makes the rolled-back **T4-5** (`User::$fillable`) safe: a missed `->update([...])` becomes a loud exception in tests instead of a silent non-persist.

### RV-39 [P2] Seeders
- No production guard: `SyrideSeeder` (prompts to **truncate all user data**), `BulkRideSeeder` (500k rides), `Atarikaktestseeder` (`Carbon::setTestNow`), `UserRealFlowSeeder` (mutates user #36) can be run with `db:seed --class=… --force`. Add a `RefusesProduction` trait (AF-4f covered commands only).
- `Syrideseeder.php` declares class `SyrideSeeder`: fine on Windows, **class not found on Linux** without an optimised classmap. Rename the file; add the namespace==path test from RV-35 for `database/seeders` too.
- `SyrideSeeder::truncateTables` omits `noshow_reports`, `refresh_tokens`, `otps` (orphans with FK checks off) and creates its own SyCash wallet (`+963999000001`, `SYR-ESCROW-001`) that no service reads, and no Primary wallet at all.
- It hand-writes ledger rows with a **third** type vocabulary (`escrow_hold`, `ride_payment`, `ride_earning`, `ride_creation_fee_received`), so dashboards show 0 on seeded data. Make seeders drive `RideService`/`BookingService` (as `Atarikaktestseeder` does).
- `DriverSeeder`/`PassengerSeeder` give every wallet the same phone (`wallets.phone_number` is unique).

### RV-40 [P1] Money schema additions (prerequisite for RV-02 L2, RV-09, RV-15)
Evidence: in the migrations I can see, `bookings` has **no monetary column** (user, ride, seats, status, timestamps, `communication_number`, `cancelled_at`, no-show flags). Every settlement recomputes `seats × ride.price_per_seat` (tests confirm the derivation). Any price change, partial cancel or fee change silently changes what a booking "paid".
- `bookings`: `unit_price`, `amount_paid`, `escrow_held` (decimal(15,2)), `payment_method` snapshot, `idempotency_key` + `unique(user_id, idempotency_key)`. Backfill from `wallet_transactions` where `reference = 'booking:{id}'`; services start writing only after backfill.
- `noshow_reports`: add `void` (and `failed`) to status; `unique(booking_id, reporter_id)`.
- `wallet_transactions`: nullable unique `posting_key`; index `(reference, type)`.
- **Policy: no new DB ENUMs for mutable statuses.** `rides.status` was `string`, then `2026_08_18_120000` converted it to ENUM with an unguarded raw `ALTER` (siblings have the driver guard). Enums drifted before (T1-2). Use `string(30)` + PHP backed enum cast; keep the mysql-only guard on migrations.
- `rides.price_per_seat` → `decimal(15,2)` (see RV-14).

---

## 4. New verify checks (read-only, record results first)

| ID | Check | Decides |
|---|---|---|
| V11 | `php artisan route:list --path=rides/search -v` — expect POST only; if a GET exists, record its validator | RV-17 |
| V12 | `grep -rnE "auth\(\)->(id\|user)\(\|Auth::(id\|user)\(" app/` — every hit inside a JWT-guarded controller/service is a `null` | RV-36 |
| V13 | run the suite with network blocked / `Http::preventStrayRequests()`; list failures | RV-37, RV-07 |
| V14 | `phpunit --order-by=random` ×3 with seeds; diff failure sets vs default order | RV-37 |
| V15 | `phpunit --log-junit` → group errors by first message line; expect two dominant groups (`Undefined array key "email"`, `Return value must be of type string, null returned`) | RV-34 |
| V16 | ledger + score effect of `POST /bookings/{id}/cancel-seats` with **all** seats vs `BookingService::cancelBooking`, at 80 % elapsed — both must refund 0 and apply the same penalty. There is no whole-booking cancel route, so the all-seats path is the only public cancellation | RV-09 |

---

## 5. Additional owner decisions

11. Reject KYC submissions until every required document is attached? (RV-01)
12. Delete the stub classes and their tests (`NotificationChannel`, the three ride/user notifications)? (RV-35 / RV-31)
13. Statuses as `string` + PHP enum instead of DB ENUM? (RV-40)
14. Add a whole-booking cancel route, or keep cancel-via-all-seats? (V16)

---

## 6. Updated wave plan

| Wave | Tasks |
|---|---|
| 0 | V1–V16 (record results) |
| 1 | RV-07, RV-06, RV-01, RV-04, RV-05, RV-02 (L1), RV-03 — unchanged |
| 2 | **RV-34**, RV-37, RV-18, RV-13, RV-14, RV-16, RV-22, RV-36, RV-38, RV-35 (RV-34 first: it turns ~340 red tests green and gives Wave 3 a usable safety net) |
| 3 | RV-40, RV-09, RV-02 (L2), RV-10, RV-11, RV-15, RV-21, RV-20 |
| 4 | RV-25 (after V1), RV-24, RV-17 |
| 5 | RV-12, RV-26, RV-27, RV-29, RV-19, RV-23 |
| 6 | RV-28, RV-30, RV-31, RV-32, RV-33, RV-39 |

Send next: `routes/api.php`, `config/system_admin.php`, the perf script, and `app/Http/Controllers/API/NotificationController.php` + `app/Exceptions/Handler.php` if you want V5/V12 closed without running anything.

---

## 9. Wave-0 execution log — R2 (agent, 2026-09-29)

R2 supersedes R1 (`APP_FUTURE_SONNET.md`) for the backlog; R1's §1.2 corrections are
honored below. V1–V10 were recorded under R1 (section 9 of that file); V11–V16 continue
here. New tests go only in `tests/Feature/Review/`. No product code was changed by any
Wave-0 step; this log plus the pinning tests is the entire deliverable so far.

### Corrections to R2's own premises (recorded, not inherited)

| R2 claim | Verified state |
|---|---|
| §1.2 #1: k6 `GET /api/rides/search` → "404, rideId='search'" | **Half wrong.** `route:list` (V11) shows **GET and POST `api/rides/search` BOTH exist**, both → `RideController@searchRides`, both `throttle:api + throttle:search + jwt`. The k6 GET therefore reaches the handler and gets **422** (wrong field names: it sends `pickup_lat/pickup_lng/destination_*`; the handler validates `source_lat/source_lng/dest_lat/dest_lng, departure_date, seats_required`). R2's *conclusion* (that traffic never exercised search) still holds; its mechanism is 422, not 404. RV-17 must target the right validator. |
| §RV-36: `auth()->id()` is null under this app's JWT middleware | **Refuted.** `JwtAuthMiddleware` calls **both** `setUserResolver()` **and** `Auth::setUser($user)` (lines 90–91, 112–113), so `auth()->id()` resolves. There are exactly **2** `auth()->id()` sites (V12): `NotificationController::bulkAction:148` and `PushNotificationController::99`. The green `NotificationTest` only asserts `assertNotEquals(500, …)` and its docblock ("never populates") pins a stale assumption. The dead-endpoint half of RV-36 (`PushNotificationController`) is separately confirmed: **0 routes** reference it (V12). `markAsRead`/`markAsUnread`/`destroy` correctly use `$request->user()->id`; only `bulkAction` uses the facade. |
| §1.2 #2: the AF-4a "397 km" proof is axis-order-agnostic | **Accepted.** 397 km is a point-to-its-own-transpose distance (same number either way MySQL reads axes). The *decisive* evidence is recorded V1: `POINT(36.2765 33.5138)`(lng-first, as the app writes) w/ SRID 4326 ⇒ **258 km** Damascus→Aleppo; lat-first ⇒ **309 km** (true distance). MySQL 4326 reads **lat,lng** ⇒ **production geometry is transposed**. Per R2's decision table, V1 hits the "rows are transposed" branch: fix through one `GeoPoint::wkt()` helper + `ST_SwapXY` backfill (RV-25); AF-4a's fixture survives only if rewritten through that helper. No production write path was edited in Wave 0 (R2 §1.2 freeze respected). |

### V11–V16 results

| ID | Result | Settled fact |
|---|---|---|
| V11 | **answered** | `GET`+`POST api/rides/search` both exist → `searchRides`; validator = `source_address`/`source_lat,source_lng`/`dest_lat,dest_lng`/`departure_date`/`seats_required` (no `pickup_*`). k6's GET ⇒ **422**. (See correction table.) |
| V12 | **refuted as stated** | Only 2 `auth()->id()` sites; both work (middleware sets the default-guard user). `PushNotificationController` unrouted (0). `bulkAction` is the one inconsistent site. |
| V13 | **mostly clean; hygiene gap real** | No test calls a live HTTP provider without `Http::fake` (V13a/b both empty) and **no test string references the provider hosts** — R1's "RideTest reaches the network" does not hold as a direct call. But `preventStrayRequests()` is **absent (0)**, so a stray call would go out silently, and **7 test files leak `putenv()`** into the shared process (RV-37 determinism defect — the likely cause of the order-dependent reds). |
| V14 | **order-dependent — CONFIRMED** | Full suite twice: default order `1881 tests / 374E / 53F`; `--order-by=random --random-order-seed=20260929` → `1881 / 374 / **55F**`. Two extra failures under a different order ⇒ the suite is not order-independent (RV-37 is real, consistent with the 7 `putenv()`-leaking test files found in V13d). |
| V15 | **CONFIRMED, with counts** | 374E + 53F = 427, grouped by first message line: **223 `Undefined array key "email"`** + 3 `"image"` (config/admin fixture-key drift), **97 `*Token(): Return value must be of type string, null returned`** (stale test auth helpers), 13 status-code mismatches, **9 `GeocodingService::geocode()` undefined method** (tests pin a method that no longer exists → part of RV-14 class), 4 `StaffComplaintService::listAll()` arity, 4 validation-shape (V5 family), 2 `PendingRequest::throw()` arity, 2 `EmployeeManagementService::list()` undefined. **320/427 (75%) sit in the two provenance groups R2 predicted** — slightly below its "~340 of 374" estimate, because its denominator was errors-only (374) while this run's population is errors+failures (427). |
| V16 | **REFUTED — paths are equivalent** | `CancelSeatsEquivalenceCheck` (2 tests, money fixtures, ~75% elapsed = 70-100 tier): cancelling ALL seats via `cancel-seats` and via `cancelBooking` produce **the same settlement ledger and the same score delta** on both payment branches. e-pay: settlement rows written on both, score delta 0.0 on both (cash-only score path, proven in `ScoreService::recordPassengerCancel`). cash: negative penalty (-5/-10 tier) on both, identical. ⇒ **RV-02 (L1)'s headline defect is refuted**; the residue is its own listed sub-item only (re-check booking status in `applyPenalty`, since the report can still be created against a not-`confirmed` booking by other means). |

**Two extra facts surfaced by V13/V15 for the record (no fix attempted in Wave 0):**
- `GeocodingService::geocode()` is called by 9 tests but **does not exist** on the service — API drift of the same family as RV-14 (`createRide`).
- `StaffComplaintService::listAll()` (4 tests) and `EmployeeManagementService::list()` (2 tests) are also called with wrong arity/removed names.

*(V1–V13-V14 details are pinned by `tests/Feature/Review/WaveZeroVerificationTest.php`; V16 by
`tests/Feature/Review/CancelSeatsEquivalenceCheck.php`. No product code was modified in Wave 0.)*

---

## 10. RV-07 — committed secrets — VERIFIED FIX (agent-side); rotation still owed by the owner

**Problem** — credentials committed to the repository: **524 hard-coded JWTs** across 6
`k6-load` scripts (decoded one: `iss=https://api.onwayride.me`, `type=access`, sub 251+,
**expired 2026-08-14** — the load tests "passed" for weeks with dead tokens because the
search slice was 422-ing, see RV-17), a real third-party API key in `phpunit.xml`, and
three helper scripts that printed seeded admin credentials and passed the key on the
command line.

**Root cause** — load-test and local-convenience artifacts were committed instead of being
generated/parameterised; no secret gate existed to stop them.

**Fix (agent-side)**
1. All 6 k6 scripts: literal token arrays → `K6_PASSENGER_TOKENS` / `K6_DRIVER_TOKENS`
   env inputs with a **fail-fast throw** at module load when absent. `k6-load/README.md`
   documents the contract and how to mint tokens.
2. Deleted `start-syride.ps1`, `start-cluster.bat`, `stop-cluster.bat`; `README.md` updated
   (the only reference).
3. Committed `phpunit.xml` now carries `dummy-test-key-not-a-real-credential`; the owner's
   working-tree file (their own Aiven config) was preserved untouched.
4. Added `.github/workflows/gitleaks.yml` (pinned v8.18.4) — scans the working tree
   (`--no-git`) and fails on findings.
5. Redacted the partial key fragment from the R1 audit record — an audit record must not
   store secrets.

**Checks**
- `eyJ…` literals in `k6-load`: **524 → 0**; repo-wide (excluding untracked `all_code.txt`,
  which is already gitignored): **0**.
- `node --check` on all 6 rewritten scripts: **6/6 syntax OK**.
- Guard proven in both directions: with no env the throw fires; with `K6_PASSENGER_TOKENS=a,b`
   / `K6_DRIVER_TOKENS=c` it prints `tokens parsed: 2/1`.
- `sonar.yml` Pusher literals: none (T3-11 had already replaced them).
- Staged `phpunit.xml` scanned: no `aiven`, no key fragment. No test references the deleted
  scripts.

**Owner-only remainder (cannot be done from the repo):** rotate the OpenRouteService key at
the provider; confirm the dev/staging `JWT_SECRET` differs from production; decide whether to
rewrite git history (the old tokens/keys remain in history and in the GitHub copy — rotation
is what makes them harmless).

**Final state: VERIFIED FIX** for everything the agent can reach; rotation/history is
explicitly unverified and remains with the owner.

---

## 11. RV-06 — Horizon dashboard public + nginx upstream leak — VERIFIED FIX

**Problem (two exposures, one task)**
1. `HorizonServiceProvider::gate()` returned `true` for every caller, justified in-code by
   *"Safe: port 8080 is only exposed to localhost"*. That premise is false for the real
   deployment: `render.yaml` fronts the app with a **public web service**, and
   `nginx-docker.conf` proxies `location /`, which includes Horizon's `horizon` prefix.
   `config/horizon.php` had `middleware => ['web']` (no auth). An anonymous `GET /horizon`
   served the dashboard: every queued job with payloads (user ids, phone numbers,
   notification text) plus **retry/delete** actions on live jobs.
2. `nginx-docker.conf` added `X-Upstream-Addr $upstream_addr` to **every** response,
   disclosing which internal replica answered — cluster-enumeration assist, the same class
   the audit closed under T2-6 (`GET /api/health` returning `gethostname()`).

**Root cause** — the dashboard relied on a network assumption that no deployment file
enforced, and a "helpful" response header was added for debugging and never removed.

**Code path (verified, not assumed)** — `HorizonServiceProvider::boot()` registers the
`horizon` middleware group; Horizon's `SentinelMiddleware` calls
`Gate::check('viewHorizon', $request->user())`; the app's own `gate()` returned `true`, so
the check always passed. The gate — not route middleware — is the control, so the fix
belongs there.

**Files changed**
- `app/Support/HorizonAccess.php` (new) — the policy, in one place so it is testable:
  `local`/`testing` allowed (developer ergonomics); everything else requires the
  `HORIZON_ACCESS_TOKEN` secret, compared with **`hash_equals`**; **fails closed** when the
  secret is unset, the header is absent, or the request has no resolvable object. An IP
  allowlist was deliberately **not** used: V4 proved `TrustProxies::$proxies = null`, so
  behind nginx every request presents the proxy's address and an IP rule could only be
  all-or-nothing (that coupling is RV-05's separate fix).
- `app/Providers/HorizonServiceProvider.php` — gate delegates to the policy.
- `config/horizon.php` — `access_token` from `HORIZON_ACCESS_TOKEN`.
- `nginx-docker.conf` — `add_header X-Upstream-Addr` removed (replaced by a comment
  recording why).
- `.env.example` — documents `HORIZON_ACCESS_TOKEN` (closed-by-default) and `HORIZON_PATH`
  for the non-default path defence in depth.

**Verification** — `tests/Feature/Review/HorizonAccessTest.php` (6 tests, 86 assertions):
- the real denied path: `GET /horizon` with the app env forced to `production` and no token
  ⇒ **403/404**, dashboard not served;
- policy: no secret ⇒ deny; missing / wrong / **prefix-only** token ⇒ deny (`hash_equals`,
  not `==`); correct token ⇒ allow; `local`/`testing` ⇒ still allowed;
- nginx: no non-comment line sets `X-Upstream-Addr` or any `add_header`.
- **Causality needle:** restoring the pre-fix `return true` gate makes the unauthenticated
  production test fail with *"an unauthenticated GET /horizon must not serve the dashboard
  in production"*; restoring the fix returns the suite to green (byte-exact restore).
- Regression: `Review` 15/110, `AppServiceProviderTest` 18/19, `AppFuture` 20/253,
  `T3Batch` 37/429, `T4Batch` 16/50 — all green. Pint clean.

**Operator note:** with `HORIZON_ACCESS_TOKEN` unset, `/horizon` is closed everywhere
outside `local`/`testing` (intended fail-closed). Set it to inspect queues in staging, and
use `curl -H "X-Horizon-Token: …"`.

**Final state: VERIFIED FIX.**

**Genuinely unverified:** the nginx edit is validated structurally (braces balanced, header
gone, no consumer) — `nginx -t` needs the container image, and no k6/run of the cluster
exists here.

---

## 12. Progress table (execution status — update at every terminal state)

Style mirrors `SYRIDE_COMPREHENSIVE_AUDIT.md` §Progress Table. Detail for each terminal
state lives in the numbered sections above; this table is the index.

### Wave 0 — verify checks (results recorded before any fix was coded)

| Check | Status | Where recorded / how pinned |
| --- | --- | --- |
| V1–V10 | **RECORDED** | `APP_FUTURE_SONNET.md` §9 (V1 transposed 258≠309 km; V2 no spatial index; V3 `ST_Buffer(LINESTRING)` error 3618; V4 nginx-IP collapse; V5 errors bag stripped; V6 CI pinned to sqlite; V7 not run; V8 `config/system_admin.php` missing; V9 unique(rater,rated); V10 `createRide` missing) — pinned by `tests/Feature/Review/WaveZeroVerificationTest.php` |
| V11 | RECORDED | §9 — GET+POST search both exist; k6 GET ⇒ 422 (R2's "404" premise corrected) |
| V12 | RECORDED — **premise refuted** | §9 — 2 `auth()->id()` sites, both functional (middleware calls `Auth::setUser`); RV-36 severity reduced |
| V13 | RECORDED | §9 — no live-provider calls; `preventStrayRequests` absent; 7 files leak `putenv()` |
| V14 | RECORDED | §9 — order-dependent CONFIRMED (55F vs 53F) |
| V15 | RECORDED | §9 — 320/427 errors in the two predicted provenance groups |
| V16 | RECORDED — **RV-02(L1) refuted** | §9 — all-seats `cancel-seats` ≡ `cancelBooking`; pinned by `tests/Feature/Review/CancelSeatsEquivalenceCheck.php` |

### Wave 1

| Task | Status | Verification |
| --- | --- | --- |
| RV-07 | **VERIFIED FIX** (agent-side) — owner rotation/history outstanding | §10 — 524 JWTs → 0, `node --check` 6/6, guard both branches, staged `phpunit.xml` scanned |
| RV-06 | **VERIFIED FIX** | §11 — 6 tests/86 assertions incl. unauthenticated `GET /horizon` ⇒ 403/404; causality needle; regression green |
| RV-01 | **PARTIAL — authorization + filenames VERIFIED FIX**; storage half OPEN (owner decision: private disk needs a staff streaming route first) | §13 — 5 Review tests incl. causality needle; `Review` 20/120, `Verification` 22/30, `Documents` 16/23; Profile/Complaints/Chat failures proven pre-existing by stash comparison |
| RV-04 | **PARTIAL — staff→user replay + empty-secret boot guard VERIFIED FIX**; token unification + TTL (decision 9) OPEN | §14 — pin failed 200≠401 before the fix; causality needle; `Review` 25/128, `StaffAdminIdentityAttributionTest` 10/44; Middleware/JwtSecretCommand failures proven pre-existing |
| RV-05 | **VERIFIED FIX** | §15 — 8 tests (trust off/on, CIDR, untrusted-source spoof denied, separate buckets, nginx directive); causality needle; Review 33/142, RateLimiting 23/151, DebugEndpointDisclosure 9/209, SessionCookieAndCors 11/20 |
| RV-02 (L1) | PENDING — **scope reduced** by V16 (headline defect refuted; only the `applyPenalty` booking-status re-check remains) | — |
| RV-03 | BLOCKED — owner decision 6 required | — |
| RV-08 | PENDING — listed in R1 §7 Wave 2; R2's wave table omits it (placement to confirm) | — |

### Waves 2–6 (not started)

| Wave | Tasks | Status |
| --- | --- | --- |
| 2 | RV-34, RV-37, RV-18, RV-13, RV-14, RV-16, RV-22, RV-36, RV-38, RV-35 | PENDING (RV-34 first — it un-reds ~340 tests and is Wave 3's safety net) |
| 3 | RV-40, RV-09, RV-02 (L2), RV-10, RV-11, RV-15, RV-21, RV-20 | PENDING (RV-40 is the prerequisite for RV-02 L2 / RV-09 / RV-15) |
| 4 | RV-25, RV-24, RV-17 | PENDING — **unblocked**: V1 is recorded, so R2's decision table selects the "rows are transposed" branch |
| 5 | RV-12, RV-26, RV-27, RV-29, RV-19, RV-23 | PENDING |
| 6 | RV-28, RV-30, RV-31, RV-32, RV-33, RV-39 | PENDING |

**Current task:** none open — RV-01, RV-04 and RV-05 each reached a terminal state.
**Next:** RV-02 (L1) → RV-03.
**Awaiting the owner:** (a) RV-01 storage half — approve a staff-authenticated document
streaming route so KYC can move off the public disk; (b) RV-04 — token unification is a
refactor, and decision 9 (access TTL 600 → 15–60) is yours; (c) RV-08 placement;
(d) decision 6 (RV-03), decision 11 (KYC completeness); (e) the deployment value for
`TRUSTED_PROXIES` (RV-05) — the code is inert until an environment sets it.

**Baseline for regression comparison** (recorded, do not treat as a target): full suite
`1881 tests / 374 errors / 53 failures` (V14 random-order run: 55 failures — the suite is
order-dependent, so a like-for-like comparison needs the same seed/order).

---

## 13. RV-01 — KYC documents exposed — PARTIAL: authorization + filenames VERIFIED FIX; storage half OPEN (owner decision)

**Problem** — national-ID / licence scans were exposed two ways at once:
1. **IDOR** — `ProfileController::formatProfileData()` accepted `$isOwner` and then *ignored
   it*, so `GET /api/profile/{anyUserId}` returned every other user's `face_id_pic`,
   `back_id_pic`, `license_pic` URLs to any authenticated caller.
   `VerificationController::status($userId)` had no ownership check at all.
2. **Storage** — the files sit on the `public` disk at `/storage/...`, i.e. served by nginx
   **without any authentication**, under a guessable `{userId}_{time()}.{ext}` name. Even
   with the IDOR closed, any URL ever returned (or brute-forced from a known user id and a
   timestamp window) still resolves for an anonymous visitor. The stored extension was also
   the client-supplied one, so an `.html` payload with image magic passed `image|mimes` and
   was then served as HTML.

**Root cause** — the `$isOwner` flag was plumbed but never consulted, and KYC was treated as
ordinary user content ("public disk, `asset()` URL") instead of personal data needing
private storage plus authorization.

**Fixed and VERIFIED in this task**
- `formatProfileData()` now emits document fields **only for the owner**; other callers get
  the profile without them. Owner and non-owner payloads are cached under *different* keys
  (`profile.user.owner.{id}` vs `profile.user.{id}`), so the gate cannot be bypassed by the
  cache — checked, not assumed.
- `VerificationController::status()`: unknown user ⇒ **404**, known user but not the caller
  ⇒ **403**, owner unchanged. (403 rather than 404 deliberately: the document is the secret,
  not the existence of the id.)
- Filenames at all three KYC upload sites (`VerificationController` ×2,
  `FileUploadService::uploadVerificationDocument`): `{userId}_{time()}.{clientExt}` ⇒
  `Str::uuid().'.'.$file->guessExtension()` — content-derived extension, unguessable name.

**Deliberately NOT done, and why (the open half of RV-01)**
The files stay on the `public` disk for now. Staff KYC review (`/admin/verifications`,
`/staff/verifications`) currently reads the documents **through those public URLs**; moving
the files to a private disk without first adding a staff-authenticated streaming route
(`/api/staff/verifications/{userId}/documents/{type}`) or S3 `temporaryUrl` would delete
the admins' ability to review KYC submissions — a silent functional regression in a P0
workflow. R2 §0 says a product decision is the owner's to make, so this is raised rather
than guessed. `Storage::disk('kyc')->temporaryUrl()` is not an option on the local driver,
which is why the streaming route is the path.

**Also still open in RV-01** (recorded, not attempted): complaint/chat attachments → same
private-disk treatment; `users.national_id` is still plaintext (encrypting it needs a data
migration, not a code tweak); and R2's acceptance item "reject a KYC submission until the
required document set is attached" is **owner decision #11**.

**Verification**
- New `tests/Feature/Review/KycDocumentExposureTest.php` (5 tests): a non-owner receives no
  document fields from `GET /api/profile/{id}`; the owner still receives their own;
  `status()` of another user ⇒ 403 with no `documents` key; owner keeps access;
  nonexistent user ⇒ 404 (not 500).
- **Causality needle:** re-inserting `if (true)` in place of `if ($isOwner)` makes the IDOR
  test fail by name, then restores green — the pin has teeth.
- Regressions: `Review` **OK (20 tests, 120 assertions)**, `Verification` **OK (22/30)**,
  `Documents` **OK (16/23)**, and the pinned floor unchanged (`T3Batch` 37/429, `T4Batch`
  16/50, `AppFuture` 20/253, `RateLimiting` 23/151). `Profile` 3F, `Complaints` 1F, `Chat`
  1F were **proven pre-existing** by stashing this task's product files and re-running the
  same three suites: identical counts either way.
- One mid-task self-inflicted failure, found and fixed: `VerificationController` had no
  `use Illuminate\Support\Str`, so the two upload tests 500'd (`Class
  App\Http\Controllers\API\Str not found`) — `php -l` cannot catch that; the suite did.

**RV-35 inventory (tests this task updated, per R2 §0 — never to make a change green, only
because the assertion pinned the vulnerability):** `VerificationControllerTest::
test_status_for_nonexistent_user_returns_error` asserted **500** for an unknown user id,
i.e. it pinned the `findOrFail` blow-up; renamed to
`test_status_for_nonexistent_user_returns_404` with the R2 acceptance item cited in-line.

**Final state: VERIFIED FIX for the authorization and filename exposure; the storage half of
RV-01 remains OPEN pending the owner decision above.**

---

## 14. RV-04 — JWT integrity — VERIFIED FIX (replay + boot guard); token unification and TTL still open

**Problem — two independent integrity defects, both proven, both P0**

1. **Privilege confusion (staff token ⇒ user session).** User and staff tokens are signed
   with the **same** secret and both carry `type=access`. `JwtAuthMiddleware` checked only
   `type === 'access'`; `StaffJwtMiddleware` is protected because
   `StaffJwtService::decodeToken()` rejects anything without `sub_type === 'employee'`
   (line 62) — so *user → staff* was already impossible. The **reverse was never tested and
   was wide open**: a staff access token whose `sub` (employee id) collides with a user id
   and whose `ver` matches that user's `token_version` was accepted as that user.
   R2 §1.3 named the default mismatch that makes a collision likely (users default **1**,
   `UserFactory` sets 0, employees 0 or 1).
2. **Empty-secret signing.** `JwtService::generateSignature()` hands
   `config('jwt.secret')` straight to `hash_hmac()`. PHP 8.2 coerces the `null` from an
   unset/blank `JWT_SECRET` to `''`, so the app signs HS256 tokens with an **empty key** —
   public knowledge, i.e. every access token forgeable. `.env.example` ships
   `JWT_SECRET=` blank, and the failure is silent: traffic is served normally while
   forgeable credentials are issued. `StaffJwtService::secret()` throws on this; the user
   path never did.

**Evidence recorded before the fix (R2 asked for the test first)** — the new pin
`test_staff_token_is_rejected_by_the_user_guard` was written first and **failed against the
unfixed code**: `Failed asserting that 200 is identical to 401` — a real staff token
returning HTTP **200** from `GET /api/user` as user #1.

**Fix (smallest correct, no infrastructure, no new claim vocabulary)**
- `JwtAuthMiddleware`: after the `type` check, reject any token that carries `sub_type`.
  This reuses the discriminator the staff side already enforces, cannot affect legitimate
  user tokens (they never carry the claim), and needs no re-issuance of existing tokens.
  The reverse direction remains protected by the staff decoder, unchanged.
- `AppServiceProvider`: new `guardJwtSecret()`, called first in `boot()`, throwing outside
  `local`/`testing` when `jwt.secret` is shorter than 32 bytes — including the empty case,
  with the reason and the `php artisan jwt:secret` remedy in the message and the length
  reported. Shaped identically to the existing T2-8 queue guard at the end of `boot()`;
  `local`/`testing` are exempt because the suite deliberately runs a dummy secret.

**Files changed** — `app/Http/Middleware/JwtAuthMiddleware.php`,
`app/Providers/AppServiceProvider.php`, new `tests/Feature/Review/StaffTokenAudienceTest.php`.

**Verification**
- `StaffTokenAudienceTest` (5 tests): staff token on `/api/user` ⇒ **401** (was 200);
  legitimate user token ⇒ still **200**; production boot with an empty secret ⇒ throws;
  with a 20-byte secret ⇒ throws "at least 32 bytes"; `testing` env ⇒ exempt.
- **Causality needle:** deleting the `sub_type` rejection makes the pin fail, restoring it
  returns green (byte-exact restore).
- Regressions: `Review` **OK (25/128)**; `StaffAdminIdentityAttributionTest` **OK (10/44)**
  (the existing user→staff direction still holds); pinned floor unchanged (`T3Batch`
  37/429, `T4Batch` 16/50, `AppFuture` 20/253, `RateLimiting` 23/151).
  `tests/Unit/Middleware` (2F, 3 skipped) and `JwtSecretCommandTest` (1F) were **proven
  pre-existing** by stashing this task's product files and re-running: identical counts.
- One mid-task self-inflicted error, found and fixed: my first version re-pointed a created
  user's primary key, breaking the `users→profiles` FK on teardown (`QueryException 1451`);
  the row is now created with the colliding id at INSERT time.

**Still open in RV-04 (deliberately not attempted)**
- **Owner decision 9** — lower the access TTL from 600 minutes to 15–60 (refresh exists).
- Unify the three token implementations onto one `TokenCodec`
  (`firebase/php-jwt`, declared in `composer.json`) with mandatory `iss`/`aud`/`exp`/`iat`/
  `jti`/`typ`, and remove the unused `php-open-source-saver/jwt-auth`, the hand-rolled
  encoder, `generateAdminTokenPair`/`generateAdminAccessToken` and
  `User::getJWTIdentifier/getJWTCustomClaims`. That is a refactor across both auth systems,
  not a defect fix, and the `sub_type` rejection already closes the exploitable path.
- Separate secrets (`JWT_SECRET` / `STAFF_JWT_SECRET`) — redundant once `aud`/`sub_type` is
  enforced, and it would invalidate every live token.

**Final state: VERIFIED FIX for the staff→user replay and the empty-secret boot hazard.**

**Genuinely unverified:** the `alg:none` and tampered-signature cases named in R1's Verify
line are covered by the existing middleware suite's shaping rather than a new pin here;
`alg` is pinned by `StaffJwtService::ALGORITHM` on the encode path, and a tampered signature
fails `hash_equals` in `JwtService::decodeToken()` (existing coverage), so I did not add
duplicates.

---

## 15. RV-05 — client IP collapse behind nginx — VERIFIED FIX (two coupled halves)

**Problem** — `TrustProxies::$proxies` was never assigned, so behind nginx every request
appeared to originate from the proxy container. Confirmed in V4. Consequences:
- every `ip:`-keyed rate-limit bucket (`RouteServiceProvider` lines 71 and 75) collapses
  into **one bucket shared by every user** — the looser per-address flood guard stops
  limiting anything, and `/auth/refresh` (identity-less, so per-IP only) throttles all
  users together;
- `GateDocumentation`'s client allow-list can never match a real client;
- request logs record the proxy address for every call, so nothing is attributable.

**Why this was not a one-line change.** `nginx-docker.conf` used
`proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for`, which **appends** the
client's own header value. Laravel reads the **left-most** entry, so enabling trust while
nginx appends would have let any client choose its own IP — i.e. it would have *created*
the spoofing bypass V4 explicitly refuted. The two halves had to land together:

1. **Laravel** — `TrustProxies` now reads `config('trustedproxy.proxies')`
   (`TRUSTED_PROXIES`, comma-separated addresses/CIDRs): unset/empty ⇒ trust nobody (the
   previous behaviour, so nothing widens by accident), `'0'` ⇒ explicit trust-nobody.
   Deliberately **not** `'*'` and not a hard-coded network — the container network differs
   per deployment. Read via a **config file**, not `env()`, because `env()` outside config
   returns null once `config:cache` runs, which would silently disable trust in production
   (the same silent-failure class RV-04 fixed for the JWT secret).
2. **nginx** — `X-Forwarded-For` is now overwritten with `$remote_addr` instead of
   appended, so the value the app trusts cannot be client-supplied. Verified single-hop
   (this file has no outer TLS terminator), so `$remote_addr` is the true client.

**Files changed** — `app/Http/Middleware/TrustProxies.php`,
new `config/trustedproxy.php`, `nginx-docker.conf`,
new `tests/Feature/Review/ClientIpBehindProxyTest.php`.

**Verification** — 8 tests / 14 assertions:
- trust unset ⇒ the header is **ignored** (`ip()` = REMOTE_ADDR, unchanged default);
- `'0'` ⇒ trust nobody;
- a configured proxy/CIDR ⇒ the **real client** is visible;
- an **untrusted** peer cannot spoof its address (REMOTE_ADDR not in the list);
- two clients resolve to **different** `ip:` bucket keys (the actual defect);
- the middleware parses single addresses, CIDR lists, empty and null;
- nginx: every non-comment `X-Forwarded-For` directive contains `$remote_addr` and none
  contains `proxy_add_x_forwarded_for`.
- **Causality needle:** forcing `$trusted = null` (the pre-fix state) makes the pins fail,
  restoring returns green.
- Regressions: `Review` **OK (33/142)**, `RateLimiting` **OK (23/151)**,
  `DebugEndpointDisclosure` **OK (9/209)**, `SessionCookieAndCors` **OK (11/20)**,
  `T3Batch` 37/430, `T4Batch` 16/50, `AppFuture` 20/253 — all at or above the recorded
  floor. `T3Batch` moved 429 → 430 assertions at the same test count.

**Operator note:** `TRUSTED_PROXIES` is unset by default, so behaviour is unchanged until a
deployment opts in. For the Compose topology (nginx + app on one bridge network) set
`TRUSTED_PROXIES=172.16.0.0/12`; the value must be the network the proxy actually connects
**from**, and it must not include client-facing ranges. Documented in `.env.example`
alongside `DOCS_ALLOWED_IPS`, including why `'*'` and client-facing ranges are wrong.

**Final state: VERIFIED FIX.**

**Genuinely unverified:** the nginx edit is validated structurally (directive inspection,
single-hop topology read from the file) — `nginx -t` and a live two-proxy-hop check need
the container image, which is not available here. The `set_real_ip_from`/`real_ip_header`
recipe from R1 applies only if an outer TLS terminator is added in front; no such hop
exists in `nginx-docker.conf` today, so adding it now would be speculative.
