# SyRide — Review Addendum R2

Delta to `APP_FUTURE_AUDIT_REVIEW.md` (called **R1** below). Read R1 first; this file only changes or adds.
Date: 2026-09-29. Same working rules as R1 §0 and `AGENTS.md`: one task at a time, explain → smallest fix → verify → `VERIFIED FIX` / `VERIFIED ROLLBACK`, log in `docs/audit/`, no secrets in the record. New tests go in `tests/Feature/Review/`; never edit an existing assertion just to turn it green (list it in RV-35 instead).

> **Start here for "where are we": §18.** It holds the commit map (which commit produced each
> terminal state), the consolidated open-decision list with what each one blocks, the explicit
> next sequence, and the RV-35 handoff. §12 is the per-wave progress table, and §§9–§17 are the
> per-task logs carrying the checks, causality evidence and regressions behind each terminal
> state. Wave-0 verify results are in §9 (R2) and R1 §9 (V1–V10).

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
| RV-07 | **VERIFIED FIX** (agent-side) — owner rotation/history outstanding | §10 — 524 JWTs → 0, `node --check` 6/6, guard both branches, staged `phpunit.xml` scanned — commit `0c0ea3c` |
| RV-06 | **VERIFIED FIX** | §11 — 6 tests/86 assertions incl. unauthenticated `GET /horizon` ⇒ 403/404; causality needle; regression green — `896c331` |
| RV-01 | **PARTIAL — authorization + filenames VERIFIED FIX**; storage half OPEN (owner decision: private disk needs a staff streaming route first) | §13 — 5 Review tests incl. causality needle; `Review` 20/120, `Verification` 22/30, `Documents` 16/23; Profile/Complaints/Chat failures proven pre-existing by stash comparison — `95ad30d` |
| RV-04 | **PARTIAL — staff→user replay + empty-secret boot guard VERIFIED FIX**; token unification + TTL (decision 9) OPEN | §14 — pin failed 200≠401 before the fix; causality needle; `Review` 25/128, `StaffAdminIdentityAttributionTest` 10/44; Middleware/JwtSecretCommand failures proven pre-existing — `d5043b5` |
| RV-05 | **VERIFIED FIX** | §15 — 8 tests (trust off/on, CIDR, untrusted-source spoof denied, separate buckets, nginx directive); causality needle; Review 33/142, RateLimiting 23/151, DebugEndpointDisclosure 9/209, SessionCookieAndCors 11/20 — `09e86c5` |
| RV-02 (L1) | **VERIFIED FIX** (headline refuted by V16; residue fixed) | §16 — needle reproduced a 47,500 double payout (95000 vs 47500); Review 36/153, MoneyPathBatch 7/21, UntangleBatch 8/213, DriverNoShowPolicy 18/26, ScoreTransaction 21/33 — `ba45e4b` |
| RV-03 | BLOCKED — owner decision 6 required | — |
| RV-08 | PENDING — listed in R1 §7 Wave 2; R2's wave table omits it (placement to confirm) | — |

### Waves 2–6 (not started)

| Wave | Tasks | Status |
| --- | --- | --- |
| 1 | RV-07, RV-06, RV-01, RV-04, RV-05, RV-02 (L1) | **DONE** — all VERIFIED FIX (§§10, 11, 13–16) |
| 1 | RV-03 | BLOCKED on owner decision 6 |
| 2 | **RV-34** | **DONE — VERIFIED FIX** (§17): errors 443 → 71 (**−372**), 0 regressions; Causes A/B/C all at zero; ratchet green — `5414344` |
| 2 | **RV-35** | **DONE — VERIFIED FIX** (§19): inventory of all 117 unmasked tests by root cause + owner; found and fixed a 4th Cause-B copy (19 errors → 0); ratchet strengthened; 98 inventoried, not acted on — errors 71 → **52** |
| 2 | **RV-13** | **PARTIAL** (§20): the verified V5 defect is **VERIFIED FIX** (422 now carries the `errors` bag; failures 74 → 68). The domain-exception refactor, the 96 controller `catch`/`getMessage()` sweep and the envelope change remain **open** — the envelope is a product decision, and the `assertNotEquals` ratchet is deferred until the domain exceptions land (§20.2) |
| 2 | **RV-14** | **PARTIAL** (§21): `POST /api/rides` no longer 500s (route → `create`, so the validated `CreateRideRequest` is finally reachable); the 2 dead unrouted duplicates (`cancel`, `finish`) deleted; **new `RoutesIntegrityTest` ratchet** (route→method + unrouted-allowlist, causality-tested). Open: `finish`/`driver-confirm` deprecation (product), `price_per_seat` width, `distance`/`duration` units |
| 2 | **RV-16** | **PARTIAL** (§22): **two verified security halves fixed** — OtpDisclosure choke point stops otp_code leaving local/testing on all 3 services (including the TextMeBot *send-failure* path), plus a boot guard (8 tests/15 assertions). Open: phone-OTP endpoint deletion (owner), plaintext OTP storage, mail-in-transaction, enumeration, sleep(5) worker |
| 2 | RV-37, RV-18, RV-22, RV-36, RV-38 | PENDING -- next is **RV-37** (test determinism: 7 files leak putenv(), preventStrayRequests() absent, order-dependence confirmed — and 22.3 is a live instance of it) |
| 3 | RV-40, RV-09, RV-02 (L2), RV-10, RV-11, RV-15, RV-21, RV-20 | PENDING (RV-40 is the prerequisite for RV-02 L2 / RV-09 / RV-15; RV-02 L1 already consumed the `void` enum value it needed) |
| 4 | RV-25, RV-24, RV-17 | PENDING — **unblocked**: V1 is recorded, so R2's decision table selects the "rows are transposed" branch. Note: RV-34 preserved the transposed fixtures verbatim, so the baseline for RV-25 is unchanged |
| 5 | RV-12, RV-26, RV-27, RV-29, RV-19, RV-23 | PENDING |
| 6 | RV-28, RV-30, RV-31, RV-32, RV-33, RV-39 | PENDING |

**Current task:** RV-34 complete (§17). Remaining red is 117 non-passing tests, all pre-existing
and previously masked by `setUp` errors — inventoried for RV-35.
**Also awaiting the owner:** the `phpunit.xml` remote-database hazard (§17.5) — the worktree file
points the suite at a reachable Aiven database and `RefreshDatabase` drops tables.
**Next:** RV-35 (inventory the remaining red), then RV-37/RV-18. RV-03 still needs decision 6.
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

---

## 16. RV-02 (L1) — no-show settlement could settle a booking twice — VERIFIED FIX

**Scope note first.** V16 refuted RV-02's *headline* claim (cancelling all seats via
`cancel-seats` and via `cancelBooking` produce the same ledger and the same score), so L1
was reduced to the residue R1 and R2 both name: the missing **booking-level** re-check.

**Problem** — `Noshowservice::applyPenalty()` loaded the booking with
`Booking::with(['ride','user'])->findOrFail(...)` — **no lock, no status check** — and then
unconditionally ran `$booking->update(['status' => 'no_show', ...])` and, for e-pay, moved
the fare out of SyCash (escrow → driver 95% / primary 5%). `resolveExpiredReports()` had
already been hardened under T3-16 to lock and re-check the **report**, but nothing
re-checked the **booking**, so a settlement still ran against a booking that another path
had already settled:
- the passenger confirming completion (`passengerConfirmCompletion`, which releases escrow
  95/5), then the report expires ~2 h later and moves the same fare **again** — the second
  payment comes out of SyCash, i.e. out of *other* bookings' escrow;
- any cancel flow (passenger `cancelBooking`, driver `cancelRide`, staff cancel) leaving a
  pending report that later settles.

R2 §1.3 additionally recorded the retry: when a skip/failure left the report `pending`, the
scheduler re-attempted it every minute, and `noshow_reports.status` was a DB ENUM with no
terminal "nothing to do" value.

**Fix**
- `applyPenalty()` now locks the booking (`Booking::lockForUpdate()`) and requires
  `status === confirmed`; otherwise it logs and returns **false**. A settled or cancelled
  booking can therefore never be settled a second time, and the lock makes the check
  authoritative against a concurrent resolver.
- `resolveExpiredReports()` treats `false` as terminal: the report is marked **`void`** with
  `resolved_at`, counted separately (`$voided`) and logged, so the scheduler stops retrying.
  The method still returns `$resolved` (its existing contract) — no caller changes.
- New migration `2026_09_30_000000_add_void_to_noshow_reports_status.php`: adds `void` to the
  `noshow_reports.status` ENUM. **This is the one piece R2 had assigned to RV-40 (wave 3),
  and L1 cannot function without it** — the smallest correct slice was taken (only `void`).
  `failed` + the attempt counter were deliberately **not** added: nothing writes them until
  L2 lands, so shipping an unused enum value would be speculative. `down()` moves any `void`
  rows back to `disputed` before shrinking the list, so the rollback cannot truncate data.
- The guard is scoped to the *booking*; the report-level T3-16 lock is unchanged, so the two
  layers now cover both halves of the original defect.

**Files changed** — `app/Services/Ride/Noshowservice.php`,
new `database/migrations/2026_09_30_000000_add_void_to_noshow_reports_status.php`,
new `tests/Feature/Review/NoshowSettlementGuardTest.php`.

**Verification**
- New `NoshowSettlementGuardTest` (3 tests, 11 assertions), using R2 §1.3's skeleton
  (a second escrowed booking proves the money came out of *someone else's* escrow):
  1. report expires after the passenger already confirmed → driver balance **unchanged**,
     SyCash **unchanged** (`b2`'s 50,000 survives), report **`void`**;
  2. booking cancelled while the report was pending → no money moves, booking stays
     `cancelled` (not flipped to `no_show`), report **`void`**;
  3. **the feature still works** — a genuinely expired report on a still-confirmed booking
     resolves to `resolved_reporter_wins`, booking becomes `no_show`, and the driver is paid.
- **Causality needle (lint-guarded after a bad first attempt):** with the lock and guard
  removed, the test fails with `Failed asserting that 95000.0 is identical to 47500.0` — a
  **double payout of 47,500** — plus the SyCash drain; restore is byte-exact (`md5`
  compared) and returns green.
- Regressions: `Review` **OK (36/153)**, `MoneyPathBatchTest` **OK (7/21)**,
  `UntangleBatchTest` **OK (8/213)**, `DriverNoShowPolicyTest` **OK (18/26)**,
  `ScoreTransactionTest` **OK (21/33)**, `T3Batch` 37/430, `T4Batch` 16/50, `AppFuture`
  20/253 — all at the recorded floor.

**Method note (recorded because it nearly produced a false pass):** my first needle did
brace surgery and left the file with a **parse error**; the suite reported "failures" that
proved nothing. The needle script now lints the patched file and **discards its own result**
if the file does not parse, and verifies the restore by hash. A red suite is not evidence
unless the code under test actually compiled.

**Final state: VERIFIED FIX** for the L1 defect. L2 (per-booking `escrow_held`, idempotent
`posting_key`, the `SyCash.balance == SUM(bookings.escrow_held)` invariant) remains in wave 3
with RV-40/RV-09 as recorded, and `failed` + attempt count land with it.

**Genuinely unverified:** the two-process concurrency test R1 asks for was not written — the
lock's correctness here is argued from `lockForUpdate()` semantics plus the existing T3-16
pattern, and the suite is single-process. A genuine concurrent-resolution test needs a second
connection and is really L2's territory (it belongs with the idempotency keys, where a
duplicate posting can be asserted regardless of interleaving).

---

## 17. RV-34 — shared test-support layer — **DONE (VERIFIED FIX, see §17.4)**

**Problem** — the suite's red is largely mechanical fixture rot, in two shapes:
- **Cause A (~220 tests)** — `seedAdminWallets()` / inline `setUp` read
  `config("admin.{$type}")['email'|'password'|'first_name']`, but `config/admin.php` now
  carries only `phone` and `wallet_prefix` (credentials moved to `employees`). The
  `Undefined array key "email"` fires inside `setUp`, so every test in the file errors
  before its body runs.
- **Cause B (~110–130 tests)** — admin-login helpers post a config email/password to
  `/api/admin/login`, but admin auth now authenticates an **Employee by `username`**.

**Baseline measured before any change (required by the acceptance criterion):**
`1908 tests, 3446 assertions, 443 errors, 52 failures, 3 skipped`. Note this is higher than
the 374 recorded earlier in §12 — that figure predates RV-01/02/04/05, so today's number is
the honest "before" and the target is a drop of **≥300 errors** (443 → ≤143).

**Delivered and VERIFIED so far**

`tests/Support/Concerns/SeedsSystemWallets.php` — idempotent system wallets by
`config('admin.*.phone')` with `user_id NULL`, no email/password. The seeded admin *user* in
the old helper was verified vestigial in every Cause-A file (they authenticate as their own
factory users, and the app locates system wallets by phone), so dropping it preserves the
real dependency.

`tests/Support/Concerns/ActsAsStaff.php` — `employee()`, `staffToken()` (staff door,
`identifier`), `adminToken()` (admin door, **`username`** — Cause B's fix), `userToken()`,
matching the proven `AdminFinancialSurfaceAuthorizationTest` reference.

`tests/Support/RideBuilder.php` + `tests/Support/GeoPoint.php` — the single place a ride is
inserted, with an explicit SRID and **explicit axis order**.

**Design decision worth recording (geometry).** The model mutator
(`Ride::setPickupLocationAttribute`) is typed `array $coords` and writes `POINT(lng lat)`,
while the raw-SQL fixtures write `POINT(lat lng)`. V1 measured that production's stored
geometry is transposed, but the fix is RV-25 and has **not** landed. So a builder that
silently normalised the order would change what every migrated test asserts — the exact
fixture-axis error the audit froze. `GeoPoint` therefore refuses to guess (`LAT_LNG` /
`LNG_LAT` must be stated), and `RideBuilder` reproduces the existing fixtures' order by
default, with `viaModelMutators()` available for tests that specifically want production's
write path.

**Two real bugs the self-test caught in the new builder** (both would have shipped silently):
1. `$ride->forceFill()` with a raw geometry expression → `TypeError`, because the mutator is
   typed `array`. Fixed by inserting through the query builder (as the fixtures do) and
   offering `viaModelMutators()` explicitly.
2. The query builder quotes a plain string as a literal → MySQL error 1416
   "Cannot get geometry object". Fixed with `DB::raw`.
Also: a raw string compare of the WKT failed because MySQL re-serialises with its own
precision (`POINT(33.513800 36.276500)`); the assertion now compares **parsed coordinates**,
since comparing text would assert MySQL's float formatting rather than the fixture's meaning.

**Verification so far** — `tests/Feature/Review/SharedTestSupportTest.php` **OK (7 tests, 21
assertions)**: wallets seeded by phone + idempotent, real staff/admin tokens minted through
the real doors, a staff token is **still rejected by the user guard** (protects RV-04 against
a helper that mints the wrong audience), builder inserts real geometry at SRID 4326, and
preserves the fixtures' existing coordinates exactly. The builder was additionally validated
against a real money path by migrating `NoshowSettlementGuardTest` onto it — the RV-02
double-payout pins stay **OK (3/11)**, so escrow still behaves.

**Ratchet (the falsifiable half of the acceptance criterion)** —
`tests/Feature/Review/NoDuplicatedFixtureHelpersTest.php` fails if any test file declares
`insertRide()`, `seedAdminWallets()` or `primaryToken()`, and asserts the support layer
exists. Run before the migration finished it correctly listed **23** violations, so it cannot
pass vacuously.

**Method constraint discovered and recorded:** the suite must **not** be run concurrently
against the single scratch MySQL (port 3399) — `RefreshDatabase`/`migrate:fresh` from a
parallel run drops tables under the other run, producing phantom "table doesn't exist"
errors. A 3-error result I saw mid-task was exactly this and was **not** a code regression;
it reproduced clean once the parallel runs stopped. No `migrate:fresh` is to be run while
other test runs are in flight.

**State: IN PROGRESS** — the support layer and ratchet are done and verified; the per-file
migration (Cause A and Cause B) is still running. The acceptance number (≥300 error drop)
must be measured after it lands, and the final state recorded then.

### 17.1 Delegation attempt and its failure (recorded as a method constraint)

Cause A and Cause B were delegated to two parallel subagents. **Both failed before
finishing, with empty closing messages**, having left partial edits in 11 test files
(ratchet violations went 23 → 18). Cause of death: both agents ran `phpunit` against the
**same** scratch MySQL while I was also running it, and `RefreshDatabase`/`migrate:fresh`
drops tables under a concurrent run.

**What I did about it** (rather than trusting or discarding the partial work):
1. Syntax-checked all 13 modified files: **0 failures**, so nothing was left un-parseable.
2. Re-ran every touched file individually and compared each against its **HEAD state via
   `git stash`**, which is the only way to tell a real regression from an unmasked one.
3. Finished the file the agent left half-applied (`StaffComplaintControllerTest`).
4. Fixed a defect **I** introduced (below).

**The rule this establishes:** never run this suite concurrently against the single scratch
database, and never delegate two agents that both run it. A "table doesn't exist" error is
contention, not a code regression — it must be re-run serially before being believed.

### 17.2 Cause A / Cause B results — attributed, not assumed

Every file below was in the "all tests error" group. Because a `setUp` error hides a test's
real assertion result, each was compared against its stashed HEAD state, so the improvement
is provable rather than claimed:

| File | Before (HEAD) | After | Verdict |
| --- | --- | --- | --- |
| AdminBanControllerTest | all erroring | **OK 29/58** | fixed |
| AdminDriverControllerTest | all erroring | **OK 25/49** | fixed |
| StaffOperationsControllerTest | all erroring | **OK 80/198** | fixed (largest single file) |
| StaffComplaintControllerTest | **38 errors** | **OK 38/125** | fixed (I finished it) |
| StaffAuthControllerTest | all erroring | **OK 17/30** | fixed |
| RideResourceTest | all erroring | **OK 16/49** | fixed |
| NationalIdVerificationTest | erroring | **OK 7/12** | fixed |
| BookingTest | erroring | **OK 11/15** | fixed |
| RideTest | **13 errors** | 11 pass / 2 fail | improved; 2 pre-existing defects unmasked |
| RideControllerFullTest | **39 errors** | 31 pass / 8 fail / 2 skip | improved; 8 unmasked |
| WalletTest | **10 errors** | 7 pass / 3 fail | improved; 3 unmasked |
| EmployeeManagementControllerTest | 12 errors / 2 fail | 11 pass / 4 fail | improved; 2 more unmasked |
| RideSearchServiceTest | OK | pending final pass | in progress |

**No file regressed.** The uncovered failures are pre-existing assertion defects that were
previously invisible behind the `setUp` error — which is precisely RV-34's purpose, and they
are **RV-35 inventory**, not something to edit green (R2 §0 forbids it). Observed so far:
wallet OTP-in-testing returns null and wrong-password returns 200 not 401; ride finish
before departure returns 200 not 400 (twice, plus a completion-status mismatch);
employee-management returns 403 where 200/201/409 expected; ride creation charging 2000 where
the test expects no fee.

### 17.3 Self-inflicted defects found and fixed during this task

1. **Removing a "now-unused" import broke type hints.** After migrating
   `NoshowSettlementGuardTest` off its local `insertRide`, I deleted
   `use App\Models\Ride;` because no `Ride::` call remained — but the import was
   load-bearing for the signatures `insertRide(): Ride` and
   `expiredReport(Ride $ride, ...)`, which then resolved to `Tests\Feature\Review\Ride`.
   Result: `TypeError ... must be of type Tests\Feature\Review\Ride` on all 3 tests. The
   import is restored and the file is **OK (3 tests, 11 assertions)**. Lesson recorded: an
   import can be referenced only by a type declaration, so "no `Class::` usage" is not proof
   it is unused.
2. **PowerShell string surgery on PHP failed to parse** while replacing the
   `StaffComplaintControllerTest` helper (the file contains Arabic text and a shell-quoting
   hazard). Because PowerShell parses the whole command before executing, nothing was
   written — confirmed by `php -l` and a byte count — and the replacement was then done with
   a **PHP script file** instead of shell string manipulation.

**Still outstanding:** none — completed as recorded in §17.4.

### 17.4 RV-34 — FINAL RESULT: **VERIFIED FIX**

**Acceptance criterion (both parts met, both measured):**

| Metric | Baseline | Final | Change |
| --- | --- | --- | --- |
| Tests | 1908 | 1918 | +10 (the new self-tests) |
| Assertions | 3446 | 4260 | **+814** |
| **Errors** | **443** | **71** | **−372** (required: ≥300) |
| Failures | 52 | 73 | +21 (unmasked defects, see below) |
| Skipped | 3 | 5 | +2 |

**Regression proof.** Both runs were captured to disk and their non-passing **test names**
diffed, not just their counts: 468 non-passing at baseline → 117 at the end, and
**0 tests that passed at baseline fail now**. The +21 failures are not regressions; they are
defects that were previously *invisible* because a `setUp` error aborted each test before its
assertions ran. The `assertions` column is the corroborating evidence — 814 more assertions now
execute than before, i.e. the suite is genuinely seeing more of the application, not hiding less.

**What was consolidated (three causes, all now zero):**

| Cause | Defect | Before | After |
| --- | --- | --- | --- |
| A | helpers reading `config('admin.*.email'\|'password'\|'first_name'\|'last_name')` — keys removed when credentials moved to `employees` | ~20 files | **0** |
| B | admin/staff login helpers posting a config email; admin auth authenticates an **Employee by username** | ~6 files | **0** |
| C | copy-pasted `INSERT INTO rides` fixtures, drifting in SRID, axis order, seats, units and addresses | 16 files | **0** |

**Ratchet (`NoDuplicatedFixtureHelpersTest`, now OK 3/6) — strengthened, not weakened.**
The first version only banned three *names*, which the Cause-C migration could have satisfied
by renaming. A second, substantive rule was therefore added: **no test file may contain
`INSERT INTO rides`**. That is the invariant that actually matters, since the raw SQL is what
drifted (some copies had no SRID, some had transposed geometry, `distance` in metres in some and
`320.5` in others). The test excludes itself explicitly, because it necessarily contains the
pattern literal. The per-file ride helper that survived Cause C is a thin shim supplying that
file's defaults once and delegating to `RideBuilder` — it holds no SQL and cannot drift, and the
no-raw-SQL rule is what actually prevents the duplication returning.

**Value preservation, and how it was protected.** Every fixture's distinctive values were carried
across explicitly: seats (3 vs 4), `e-pay` vs `cash`, distance `320.5` vs `320500`, duration
`240` vs `14400`, Arabic vs Latin addresses, `communication_number`, eager-loaded relations, and
`created_at` overrides. Two classes of value were treated as load-bearing after checking whether
tests actually observe them:
- **Addresses.** Only `AdminDriverServiceTest` asserts them (`'Damascus'`/`'Aleppo'`), so those
  are passed explicitly there rather than relying on the builder's Arabic default; the other
  files' addresses are passed too, so no fixture silently changes value.
- **Axis order.** `RideSearchServiceTest` and `WaveZeroVerificationTest` use **lng-first**
  (`POINT(36.2765 33.5138)`) while the rest use lat-first. This is V1's transposition, owned by
  **RV-25**, and the search/geo assertions depend on the literal values, so `rawPickup()`/
  `rawDestination()` reproduce them verbatim. A builder that quietly normalised axis order would
  have silently changed what these tests exercise — the exact fixture-axis error the audit froze.

**Four defects I introduced during this task, each caught by running the real tests and fixed:**

1. **Dropped a load-bearing assignment.** My scripted replacement of
   `AdminDashboardControllerTest::seedAdminWallets()` also deleted
   `$this->primaryAdminWallet = Wallet::where('phone_number', …)->first();`, which the tests
   read — producing "typed property … must not be accessed before initialization". Restored, and
   the `10_000_000` starting balance it also discarded was restored.
2. **Deleted a "now-unused" import that was not unused.** Removing `use App\Models\Ride;` from
   `NoshowSettlementGuardTest` (no `Ride::` call remained) broke the signatures
   `insertRide(): Ride` and `expiredReport(Ride $ride, …)`, which then resolved to
   `Tests\Feature\Review\Ride` → `TypeError` on all 3 tests. An import referenced only by a type
   declaration is still load-bearing; restored and the file is OK (3/11).
3. **Replaced bodies without the trait they call.** The delegation called `seedSystemWallets()`
   in files that only imported `ActsAsStaff`, giving "undefined method" on 29/25/17 tests. Caught
   immediately by running them, and the missing `SeedsSystemWallets` imports were added.
4. **Two dangling `DB::` fragments.** The inline-statement replacement cut at the wrong offset
   in `CancelSeatsEquivalenceCheck` and `WaveZeroVerificationTest`, leaving `DB::` that bound to
   the next `$ride` → "Access to undeclared static property DB::$ride". Both fixed; both files are
   back to their exact prior green counts (2/7 and 9/24).

**One file was missed by the name-based ratchet.** `WalletTransactionServiceTest` seeded admin
wallets **inline inside `setUp()`** rather than via a named helper, so the ratchet never flagged
it — it still raised `Undefined array key "email"` and errored all 27 tests. It was found only
because the full-suite error count was not dropping as far as expected. This is the concrete
argument for the ratchet's second rule and for not trusting a name-based check alone.

**Method constraints established by this task (all learned the hard way):**
1. **Never run this suite concurrently against the single scratch database.** Two parallel
   subagents (plus me) all running `phpunit` on port 3399 → `RefreshDatabase`/`migrate:fresh`
   drops tables under the other run, producing phantom "table doesn't exist" errors. Both
   delegated agents died this way. A 3-error result I saw mid-task was exactly this, not a code
   regression. No `migrate:fresh` while other runs are in flight.
2. **A partial agent result must be attributed, not trusted or discarded.** Partial edits were
   syntax-checked (0 failures) and each touched file compared against its stashed HEAD state
   before being kept.
3. **Scripted PHP edits must lint the output and refuse to write if it does not parse** — this
   gate is what prevented two corrupt files from landing.
4. **"Undefined array key X" in a test `setUp` is a stale-fixture signal**, not a test bug.
5. **Compare non-passing test *names* across runs, not counts**, or a masked→unmasked failure
   will be misread as a regression.

**Remaining red is not RV-34's.** 117 non-passing tests remain, in money/wallet, geo, staff
service and profile areas, and are recorded in the RV-35 inventory below. Representative,
all pre-existing and previously masked: wallet OTP returns null in testing and wrong-password
returns 200 not 401; ride finish before departure returns 200 not 400; employee-management
returns 403 where 200/201/409 are expected; ride creation charges 2000 where a test expects no
fee; admin login by email returns 401 (the app authenticates by username).

### 17.5 P0 HAZARD found while running this task — the test suite can destroy the owner's remote database

**Not part of RV-34, found during it, and reported because it is a data-loss risk.**

The worktree `phpunit.xml` (the **owner's own file**, mtime Sept 27, present before any of this
session's work) points the suite at a **remote** database:

```
DB_HOST = mysql-1e81c0db-atareeqak.b.aivencloud.com   DB_PORT = 10188
DB_DATABASE = Atareekak-tesdb                         DB_USERNAME = avnadmin
```

The endpoint is **reachable from this machine** (TCP connect verified). Since `RefreshDatabase`
and `migrate:fresh` **drop all tables**, running `php vendor/bin/phpunit` **without** overriding
the database environment variables executes those drops against that remote database.

**Why it has not caused damage so far:** `phpunit.xml` declares its `<env>` entries with **no
`force` attribute**, so PHPUnit does not override variables that are already set in the
environment. Every run in this session explicitly exported the scratch-DB variables first, and
the scratch database (`t3_batch` on 127.0.0.1:3399) is what all reported results refer to. This
was confirmed by inspecting the file rather than assumed. The committed copy of `phpunit.xml`
in HEAD is sanitised (`sqlite` `:memory:`), which is why V6 found the money/geo suites skipping
in CI.

**Residual risk and recommended owner action (not actioned — the file is user-owned):** anyone
running the suite without the explicit `DB_*` exports, or with `force="true"` added, would
target the remote database. Recommended, in order of preference: point the worktree `phpunit.xml`
at a local throwaway MySQL (or SQLite), and/or add `force="true"` to a local-only copy so the
value is always the intended one. This is recorded for the owner because the correct value
depends on how they want to run the suite; no change was made to the user's file.

**State: VERIFIED FIX** (RV-34). The `phpunit.xml` hazard is **reported, not fixed** — it needs
an owner decision, and the file is user-owned.

---

## 18. Master status, commit map, and open owner decisions

This section is the single place to look for "where are we". It adds three things the numbered
sections above did not carry: a **commit map** (so any terminal state can be traced to the exact
commit that produced it), a **consolidated open-decision list** with what each one blocks, and an
explicit **next** sequence.

### 18.1 Commit map (branch `Agentic`)

| Commit | Task | Terminal state |
| --- | --- | --- |
| `97cd3f1` | V1–V10 verify (Wave 0) | RECORDED + pinning tests |
| `c1bb4c7` | V11–V16 verify (Wave 0) | RECORDED; refuted RV-02 (L1) headline + part of RV-36 |
| `0c0ea3c` | RV-07 committed secrets | VERIFIED FIX (agent-side) |
| `896c331` | RV-06 Horizon + nginx upstream leak | VERIFIED FIX |
| `95ad30d` | RV-01 KYC IDOR + filenames | PARTIAL (storage half open) |
| `d5043b5` | RV-04 staff→user replay + JWT boot guard | PARTIAL (unification + TTL open) |
| `09e86c5` | RV-05 client IP behind nginx | VERIFIED FIX |
| `ba45e4b` | RV-02 (L1) double settlement | VERIFIED FIX |
| `726b094` | audit progress tables added | documentation |
| `5414344` | RV-34 shared test-support layer | VERIFIED FIX (443 → 71 errors) |
| `83ecd1d` | audit §18 (commit map, open decisions, next) | documentation |
| `d381a7a` | RV-35 inventory + 4th Cause-B copy fixed | VERIFIED FIX (71 → 52 errors) |
| `980741c` | RV-13 validation `errors` bag (V5) | **PARTIAL** — verified half fixed (§20) |
| 1c18c07 | RV-14 broken route + routes-integrity ratchet | **PARTIAL** -- verified 500 fixed (§21) |
| *this commit* | RV-16 OTP disclosure choke point + boot guard | **PARTIAL** — both verified halves fixed (§22) |
| `b9643f1`, `92f454e`, `2687872`, `fa33fca` | AF-1, AF-2′, AF-4 (pre-R2) | VERIFIED FIX, see `APP_FUTURE_AUDIT.md` |

### 18.2 Status summary

```
Backlog:  RV-01..RV-33 (R1) + RV-34..RV-40 (R2)  =  40 tasks
  RESOLVED - VERIFIED FIX ..............................  6   RV-02 (L1), RV-05, RV-06,
                                                              RV-07, RV-34, RV-35
                                                              (RV-07 agent-side only; key
                                                              rotation + history purge still
                                                              owed by the owner)
  PARTIAL - a verified half is fixed, remainder open .....  5   RV-01, RV-04, RV-13, RV-14, RV-16
  BLOCKED on the owner ................................  1   RV-03 (decision 6)
  NOT STARTED .........................................  28   RV-08..RV-12, RV-15, RV-17..RV-33,
                                                              RV-36..RV-40
                                                 -----
                                                  40   total
Verify checks  V1-V16:  15 recorded, 1 never run (V7 - no replica access)

Suite:  errors 443 -> 52 (-391)   failures 52 -> 68   regressions 0
        117 remaining = 26 schema-decision (19.3) + 91 across 7 other families (19.2)
```

### 18.3 Open owner decisions — consolidated

Decisions 1–10 originate in R1 §6; 11–14 in R2 §5. Their **current** state:

| # | Decision | Blocks | Status |
| --- | --- | --- | --- |
| 1 | KYC: approve a **staff-authenticated document streaming route** so files leave the public disk | RV-01 storage half | open |
| 2 | Auto-confirm window for unconfirmed rides / escrow release policy | RV-10 | open |
| 3 | Platform fee on cancellation payouts (currently none) | RV-09 | open |
| 4 | Phone-OTP endpoints: keep or delete | RV-16 | open |
| 5 | Show driver phone to all authenticated users, or booked passengers only | RV-29 | open |
| **6** | **Staff-initiated cancel: full refund? score impact?** | **RV-03 — the only Wave-1 blocker** | **open** |
| 7 | One deployment target: compose+nginx or Render | RV-08 | open |
| 8 | Account-enumeration policy (uniform vs friendly errors) | RV-16 | open |
| 9 | Access-token TTL (600 min → 15–60) | RV-04 completion | open |
| 10 | Add a real `users.phone`? | RV-19 | open |
| 11 | Reject KYC until every required document is attached? | RV-01 | open |
| 12 | Delete the stub notification classes and their tests? | RV-35 / RV-31 | open |
| 13 | Statuses as `string` + PHP enum instead of DB ENUM? | RV-40 | open |
| 14 | Add a whole-booking cancel route, or keep cancel-via-all-seats? | V16 follow-up | open |
| — | **`phpunit.xml` targets a reachable Aiven database**; `RefreshDatabase` drops tables. Point it at a local throwaway MySQL. | data-loss risk (§17.5) | **reported, not fixed — file is user-owned** |
| — | RV-08 placement: R1 lists it in Wave 2, R2's wave table omits it | RV-08 | unconfirmed |

**Only decision 6 blocks an already-started wave.** Everything else blocks work not yet begun.

### 18.4 What is next (in order)

Wave 2 remaining, in the order R2 §6 lists them:

1. **RV-16** — OTP and mail flows (4 tests): `otp_code` is returned whenever a provider key is
   unset *or* sending fails, in any environment; codes stored in plaintext; the boot guard that
   refuses to start with a testing OTP mode is missing. This is an **account-takeover-shaped** issue
   and is the highest-severity item left in Wave 2.
2. **RV-37** — test determinism: 7 files leak `putenv()` into the shared process (V13),
   `Http::preventStrayRequests()` absent, order-dependence confirmed (V14). A safety net for
   everything after it.
3. **RV-18, RV-22, RV-36, RV-38** — CI signal, TLS/log hygiene, notification `bulkAction`,
   Eloquent strictness.
4. **RV-13 remainder** (§20.1) — domain exceptions first, then the controller
   `catch`/`getMessage()` sweep, then the `assertNotEquals` ratchet. Only the envelope change
   needs the owner.
5. **RV-14 remainder** (§21.1) — `finish`/`driver-confirm` (product decision), the
   `price_per_seat` width, and the `distance`/`duration` units decision.

Then, per the wave plan: **RV-40** (money schema; prerequisite for RV-02 L2 / RV-09 / RV-15),
**RV-25** (unblocked — V1 recorded; RV-34 deliberately preserved the transposed fixtures so its
baseline is unchanged), and the remaining waves.

**Waiting on the owner:** the `wallet_requests.wallet_id` schema question (§19.3 — blocks 26
tests, the single largest family), the five product decisions in §19.4, the RV-13 envelope shape
(§20.1), and RV-14's `finish`/`driver-confirm` deprecation (§21.1).

### 18.5 Handoff note for RV-35 (what RV-34 left behind)

RV-34 did **not** fix the remaining red and did not weaken a single assertion to hide any of it.
The 117 non-passing tests are pre-existing defects that were previously invisible because a
`setUp` error aborted each test before its assertions ran. Verified at the end of RV-34: **0 tests
that passed at baseline fail now**, so this is exposure, not regression.

Non-passing tests by class, as of `5414344`:

| Class | Non-passing | Observed symptoms (pre-existing) |
| --- | --- | --- |
| `Feature\Payment\WalletTransactionServiceTest` | 27 | wallet/ledger mismatches |
| `Unit\Services\GeocodingServiceTest` | 15 | `geocode()` no longer exists (RV-14 class) |
| `Unit\Services\Staff\StaffComplaintServiceTest` | 15 | service arity/`listAll()` drift |
| `Feature\Wallet\WalletRequestControllerTest` | 14 | OTP returns null in testing; wrong password ⇒ **200, not 401** |
| `Unit\Services\Admin\AdminDriverServiceTest` | 10 | service/driver state drift |
| `Feature\Admin\AdminDashboardControllerTest` | 9 | admin login by email ⇒ 401 (app authenticates by username); two `*_currently_500s` recorders |
| `Feature\Rides\RideControllerFullTest` | 8 | finish-before-departure ⇒ **200, not 400**; completion-status mismatch |
| `Unit\Models\WalletRequestTest` | 8 | — |
| `Unit\Services\Payment\CashRideFeeServiceTest` | 5 | fee expectations (ride creation charges 2000 where a test expects none) |
| `Feature\Staff\EmployeeManagementControllerTest` | 4 | **403** where 200/201/409 are expected |
| `Unit\Services\ImageMessageTypeTest`, `Feature\Profile\ProfileTest`, others | ~9 | — |

The per-test inventory itself is **RV-35's first deliverable** and is deliberately not written
here; the table above is the grouped starting point, not a substitute.

---

## 19. RV-35 — inventory of the tests RV-34 unmasked — **VERIFIED FIX (1 defect fixed, rest inventoried)**

**Problem.** RV-34 stopped ~372 tests being *masked* by `setUp` errors, which exposed the real
defects underneath. R2 §RV-35 scopes this task to **inventory only**: "act only after the owning
fix or with owner approval." The job was to say what the remaining red actually is, and who owns
each piece — not to make it green.

**Root cause of the red itself.** Not one cause. Grouping all 117 remaining non-passing tests by
their first message line gives six distinct families, and only one of them turned out to be more
mechanical rot (fixed here). The rest are genuine product/test defects belonging to other tasks.

**Suite state at RV-35 entry and exit:**

| | After RV-34 | After RV-35 | Change |
| --- | --- | --- | --- |
| Errors | 71 | **52** | **−19** |
| Failures | 73 | 74 | +1 (the 19 fixed tests now run; 1 fails for an unrelated reason) |
| Tests | 1918 | 1919 | +1 (the ratchet's new assertion) |
| Regressions vs the original baseline | 0 | **0** | — |

### 19.1 The one defect RV-35 actually fixed

**`StaffAdminControllerTest::adminToken()` was a fourth copy of Cause B** — it posted
`config('admin.system_admin.email'|'password')` to `/api/admin/login`, but admin auth
authenticates an Employee by **username**, so it returned `null` and tripped the `: string`
return type, erroring **19 tests** in that file.

Why RV-34 missed it: the ratchet banned three *names* (`insertRide`, `seedAdminWallets`,
`primaryToken`). Nobody had thought to ban `adminToken`, so a broken copy slipped through under a
name the list did not cover. This is the concrete cost of a name-based check.

**Fix** — the file's local `adminToken()` declaration was deleted. The shared `ActsAsStaff` trait
provides a method of the *same name*, so all 19 existing `$this->adminToken()` call sites
resolved to the shared helper with no other change. **19 errors → 0** (24 tests, 36 assertions,
1 failure, which is a pre-existing 422-vs-200 validation-shape issue in the V5/RV-13 family).

**Ratchet strengthened, not loosened.** A new assertion bans posting an `'email'` to
`/api/admin/login` or `/api/staff/login` **anywhere in `tests/`**, which is the actual defect
rather than one spelling of it. Ratchet now **OK (4 tests, 7 assertions)**.

`adminToken` / `staffToken` were deliberately **not** added to the name list: those are legitimate
helper names — the shared trait defines them and three currently-green files have working
versions — so banning the names would forbid correct code. Recorded here because the first
attempt did exactly that and was reverted after the ratchet flagged the support layer itself.

### 19.2 Inventory — the 117 remaining, by root cause, with the owning task

Nothing below was edited. This is the deliverable.

| # | Root cause | Tests | Class(es) | Owning task |
| --- | --- | --- | --- | --- |
| 1 | `wallet_requests.wallet_id` is `NOT NULL` + FK, but every fixture creates a top-up request **without** a wallet ⇒ *"Field 'wallet_id' doesn't have a default value"* | 26 | `WalletRequestControllerTest` (14), `WalletRequestTest` (8), `UserRatingTest`, `ComplaintControllerTest` | **new — see §19.3** |
| 2 | `GeocodingService::geocode()` **no longer exists**; tests pin it | 9 | `GeocodingServiceTest` | RV-14 (route/controller drift) |
| 3 | `StaffComplaintService::listAll()` / `listEscalated()` **signature changed**; tests call the old arity | 13 | `StaffComplaintServiceTest` | RV-13 / RV-14 class — needs an owner ruling on the new signature |
| 4 | `EmployeeManagementService::list()` / `formatEmployee()` undefined | 4 | `EmployeeManagementControllerTest` | RV-13 class |
| 5 | Validation **shape** mismatch: 422 where 201/200 expected, and vice versa | ~20 | `ProfileTest`, `RideTest`, `OtpTest`, `TextMeOtpControllerTest`, `EmailVerificationControllerTest`, `AdminDashboardControllerTest` | RV-13 (422 carries no `errors` bag — V5) |
| 6 | `GeocodingServiceTest` distance/result mismatches (`'N.N' matches expected N.N`) | ~13 | `GeocodingServiceTest`, `CashRideFeeServiceTest` | RV-25 / RV-09 |
| 7 | Behaviour/bug-pinning tests: wrong password ⇒ **200 not 401**; finish-before-departure ⇒ **200 not 400**; admin login by email ⇒ 401; employee management ⇒ **403** where 200/201/409 expected; `*_currently_500s` recorders | ~17 | `WalletTest`, `RideControllerFullTest`, `AdminDashboardControllerTest`, `EmployeeManagementControllerTest` | **product decisions** — §19.4 |
| 8 | Stale stubs, dead helpers, namespace≠path, vanity assertions (R2 §RV-35's own list) | ~15 | `SendPushNotificationTest`, `ImageMessageTypeTest`, `StaffRoleTest`, `ComplaintTypeTest`, `StaffAdminControllerTest` (ns `Staff`), `JwtSecretCommandTest`, `StaffJwtMiddlewareTest`, `JwtAuthMiddlewareTest`, `ContactControllerTest`, `RideValidationServiceTest` | RV-35 (act after owning fix) / RV-31 |

**Families 2–8 were left untouched**, per §RV-35's rule. No assertion was edited anywhere in this
task.

### 19.3 New finding: `wallet_requests.wallet_id` is a schema design question, not a test bug (26 tests)

The 26 tests in family 1 cannot be fixed by editing a test: **a wallet top-up request is precisely
the case where no wallet may exist yet**, yet the column is
`$table->foreignId('wallet_id')->constrained()->cascadeOnDelete()` — `NOT NULL` with a FK. Either:

- **(a)** the column should be **nullable** (a top-up creates the wallet, or is pending one) — a
  migration; or
- **(b)** `wallet_id` is mandatory and the fixtures are simply wrong — a fixture change, no
  migration.

(a) and (b) have materially different designs, so this is a **product decision** and is raised
rather than chosen. It is also the largest single family in the inventory.

### 19.4 Product decisions surfaced by this inventory (not acted on)

1. **Wallet top-up without an existing wallet** — §19.3. Blocks 26 tests.
2. **Wrong password returns 200, not 401** (`WalletTest`) — is that a real auth defect, or is the
   test stale? Must not be "fixed" by weakening the test.
3. **Ride can be finished before its departure time** (returns 200, not 400) — real defect or stale
   expectation? Appears in both `RideTest` and `RideControllerFullTest`.
4. **Employee management returns 403** where 200/201/409 are expected — is the role gate correct and
   the test stale, or is the gate wrong?
5. **Admin login by email** now returns 401 by design (username-only auth). Tests that still
   expect 200 are stale and need rewriting, not the endpoint changing back.

### 19.5 State: **VERIFIED FIX**

- RV-35's own scope (inventory + map to owners) is complete: §19.2, §19.3, §19.4.
- The one mechanical defect surfaced by the inventory (19 tests) is fixed and verified.
- The ratchet was strengthened so this defect class cannot return, and stays green.
- **Genuinely unverified:** the 98 tests in families 1–8 were inventoried but not diagnosed to root
  cause, because that is the owning task's job. The counts are exact, but a test counted as
  "family 5" could share a cause with another family once examined.

---

## 20. RV-13 — error model — **PARTIAL: the verified V5 defect is VERIFIED FIX; the refactor is OPEN**

**Problem.** R1 RV-13 is a large, multi-part error-model refactor. This task took only the part that
is **verified, small, and independently valuable**, and deliberately did **not** start the parts
that are a client-breaking product decision or a large refactor.

**Verified root cause (V5, confirmed in code).** `App\Exceptions\Handler::register()` installs a
catch-all `renderable(function (Throwable $e, $request))`. For an `api/*` request it maps
`ValidationException` → 422 and then **rebuilds** the response as
`{"status":"error","message":…,"code":422}`, discarding `$e->errors()`.

**Why that mattered.** A validation failure is the one error a client must be able to act on, and
that shape tells it only that *something* failed — never which field or why. It affected every
`FormRequest` and `$request->validate()` endpoint (`BookRideRequest`, the OTP requests,
`Wallet*Request`, `searchRides`, `cancelPartialSeats`, `bulkAction`, …).

**Fix (smallest correct one).** A `ValidationException` renderer registered **ahead of** the
catch-all, returning Laravel's own shape:

```php
$this->renderable(function (ValidationException $e, $request) {
    if (! $request->is('api/*') && ! $request->expectsJson()) {
        return null;                       // let Laravel render HTML as before
    }
    return response()->json([
        'message' => $e->getMessage(),
        'errors'  => $e->errors(),
    ], $e->status);
});
```

Registering it before the catch-all is the whole mechanism — renderable callbacks are matched in
order, and the catch-all's `Throwable` type would otherwise win. Nothing else in the catch-all
(status mapping, `Retry-After` header preservation, generic 500s) was touched.

**A defect I introduced and fixed during the fix.** The first version passed `$e->headers`, which
`ValidationException` does not have (only `HttpException` does) → the renderer itself threw and
every request became a **500**. Caught by running the test, not by reading the code. Validation
errors carry no contract headers, so only `$e->status` is passed now.

**Verification.**

| | Before | After |
| --- | --- | --- |
| `ValidationErrorBagTest` (new, 4 tests) | 3 of 4 fail, `errors` is `null` | **OK (4 tests, 15 assertions)** |
| Suite failures | 74 | **68** (−6) |
| Suite errors | 52 | 52 |
| Regressions vs the original baseline | 0 | **0** |

The 6 tests that flipped to passing were asserting the missing bag. A 404-path assertion is
included in the new test on purpose: it proves the fix did not disturb the catch-all's other
branches.

**Recorder updated, as required.** `WaveZeroVerificationTest::test_v5_validation_errors_are_stripped_from_the_api_envelope`
asserted the *defect* (`! array_key_exists('errors')`) and therefore failed the moment the fix
landed. It was flipped to assert the fixed behaviour and renamed
(`…_reach_the_client_in_the_errors_bag`), with a comment stating it must not be flipped back. This
is the house rule: a recorder is updated as part of its finding's fix, never silently weakened.

### 20.1 What RV-13 still owes — measured, not estimated

The remaining halves are real work and are **not** started:

| Half | Measured size | Why not done here |
| --- | --- | --- |
| `App\Exceptions\Domain\*` (code + HTTP status) | **0 classes exist**; **61** services throw `InvalidArgumentException`, which the catch-all maps to **500** | Needs the exception hierarchy designed first; a domain rule violation being a 500 is what the ratchet in §20.2 would otherwise enshrine |
| Controllers stop catching `Throwable` / stop returning `getMessage()` | **96** catch blocks, **121** `getMessage()` returns (leak SQL text and table names to clients) | Large, touches every controller, and needs the domain exceptions to land first |
| One envelope `{success,data,error{…}}` + `/api/v1` | — | **Product decision**: this changes the response shape the Flutter client parses. Must not be done unilaterally |

### 20.2 The `assertNotEquals` ratchet — deliberately deferred, with the reason

R2 §1.3 asks for a ratchet banning `assertNotEquals(` in `tests/Feature` and for the 13
denied-path checks to be replaced with exact statuses. **I did not do this**, because half the
exception mapping is still missing: `passengerConfirmCompletion` still returns **500** for a domain
rule violation, and 96 catch blocks still return raw `getMessage()`. Writing "the exact status"
today would encode those wrong statuses as correct and destroy the signal. The ratchet is correct
as a task but must land **after** §20.1's first two halves.

The 13 occurrences (surveyed, untouched): `ProfileTest` (4), `NotificationTest` (3), `BookingTest`
(3), `StaffComplaintControllerTest` (2), `ChatTest` (1).

### 20.3 State: **PARTIAL — verified defect fixed, refactor open**

- Fixed and verified: the validation `errors` bag (V5), the highest-value, lowest-risk half.
- Recorded with measurements: the four remaining halves, so the next agent does not re-audit them.
- Deferred on purpose: the `assertNotEquals` ratchet (§20.2) and the envelope change (§20.1, product
  decision).

---

## 21. RV-14 — route/controller mismatches — **PARTIAL: the 500 on a documented endpoint is VERIFIED FIX; deprecations and schema remain open**

**Problem.** R1 RV-14 reported `POST /api/rides` → `RideController@createRide`, a method that does
not exist, so a documented endpoint returned 500 for every caller.

**Measured first, then fixed.** A throwaway reflection probe over `Route::getRoutes()` found
**exactly one** route in the whole app whose action cannot be invoked — V10 was correct and
complete:

```
POST api/rides  App\Http\Controllers\API\RideController@createRide   METHOD MISSING
```

**Fixes applied (test-only-visible, no client contract change):**

1. **`routes/api.php`** — `POST /api/rides` now points at `create`. This is more than a rename: it
   makes `CreateRideRequest` — the *validated* path — reachable. It was dead code while callers used
   `/create-with-route`, whose inline rules are weaker.
2. **`RideController::cancel()`** deleted. An unrouted duplicate of `cancelRide()`; the route calls
   `cancelRide`.
3. **`RideController::finish()`** deleted. An unrouted alias of `finishRide()` that existed only as
   a second name for the same response.

(2) and (3) are the "unrouted duplicate" half of R1's ask, and both were provably unrouted and
unreferenced before deletion.

**Ratchets added** — `tests/Feature/Review/RoutesIntegrityTest.php` (**OK, 3 tests**):

- `every_route_action_resolves_to_an_existing_public_method` — the general invariant. A route
  pointing at a missing/non-public method is a 500 at runtime that nothing in the suite notices,
  because the suite only calls endpoints that work. This is the structural form of the fix.
- `every_public_controller_method_is_routed_or_explained` — the reverse direction, which is what
  *found* the two dead duplicates above. Anything legitimately unrouted must appear in
  `UNROUTED_BY_DESIGN` **with a reason** (3 entries: `ScoreController@formatScore`, and
  `RideController@autocomplete` / `index`, both recorded as unwired-by-decision with the reason and
  a note to delete under RV-31 if they stay unwired).
- `every_unrouted_allowlist_entry_still_exists` — the allowlist cannot rot: deleting or routing a
  listed method forces the entry to be removed.

**Causality needle.** The ratchet was re-broken deliberately (route pointed back at `createRide`)
and failed naming the exact route — `POST api/rides => …@createRide (method does not exist)` — then
`routes/api.php` was restored **byte-identical (MD5 verified)**. The ratchet is falsifiable, not
vacuous.

**Behaviour pinned, not just resolution.** `tests/Feature/Review/CreateRideRouteTest.php`
(**OK, 3 tests**) asserts the route resolves to `create`, that an invalid payload yields **422 with
an `errors` bag** (not a 500 — proving the validated method was reached), and that an
over-maximum price is rejected before reaching the column. Resolution alone would not have caught a
route repointed at something weaker.

**Recorder updated per house rule.** `WaveZeroVerificationTest::test_v10_post_rides_routes_to_a_nonexistent_method`
asserted the defect and failed the moment the fix landed. Flipped to
`…_resolves_to_the_validated_create_method` and renamed, with a comment that it must not be flipped
back. This is the second recorder flip in a row (V5, V10) — the pattern is that a fix and its
recorder move together.

**Verification.**

| | Before | After |
| --- | --- | --- |
| `POST /api/rides` | 500 `BadMethodCallException` | resolves to `create`, validated |
| `Route::getRoutes()` broken actions | 1 | **0** |
| Unrouted public controller methods | 6 | 3 (all explained) |
| Suite errors / failures | 52 / 68 | **52 / 68** (unchanged — this was invisible to the suite) |
| Regressions vs the original baseline | 0 | **0** |

The suite being *unchanged* is itself the finding: the endpoint was broken and no test could see it,
which is exactly why the structural ratchet was needed rather than a one-line route edit.

### 21.1 What RV-14 still owes

| Half | Status | Why not done |
| --- | --- | --- |
| `/rides/{id}/finish` and `/driver-confirm` return a no-op body | **open** | They are routed and returning honestly ("No driver action required… `driver_confirmed:false`"), but R1 calls them "lying endpoints". R2 offers *delete, or return 410* — both change the client contract. **Product decision.** |
| `rides.price_per_seat` is `decimal(8,2)`; T3-2 never widened it | **open** | Needs a migration (mysql-guarded, never shrinks) with the bound sourced from one config value, so the bound and the column width stay in step. Pin test added above so the pair cannot silently drift. |
| `rides.distance` / `duration` unit disagreement | **open** | Fixtures insert `320.5` and `320500` into columns commented "Meters"/seconds. **Needs a units decision** before normalising fixtures. |
| `create-with-route` should reuse `CreateRideRequest`, then deprecate | **open** | Depends on the price bound landing first. |

### 21.2 State: **PARTIAL — the verified 500 is fixed and ratcheted; three items await a decision**

---

## 22. RV-16 — OTP disclosure — **PARTIAL: both verified security halves fixed; five items are owner decisions or larger refactors**

**Problem.** Three different services could hand a live one-time password back to the caller,
under three unrelated conditions. An OTP is a single-factor credential: disclosing it lets the
holder complete a signup, a password reset, or a wallet top-up.

| Service | Leaked the code when | Environment check? |
| --- | --- | --- |
| `EmailOtpService` | `EMAIL_OTP_MODE=testing` was set | **none at all** |
| `WhatsAppOtpService` | `WALLET_OTP_MODE=testing` was set | none |
| `TextMeBotOtpService` | provider API key unset — **and** when sending **fails** | none |

The TextMeBot failure path is the worst of the three: any transient SMS/WhatsApp outage would
publish every code.

**Fix — one choke point, not seven scattered guards.** `App\Support\OtpDisclosure::sanitize()`
strips `otp_code` unless `app()->environment('local','testing')`. Each service routes its **whole**
send path through it:

```php
public function sendOtp(...): array
{
    return OtpDisclosure::sanitize($this->dispatchOtp(...));
}
```

Wrapping the entire path rather than checking at each `return` means a future return statement
cannot bypass the guard. `sanitize()` deliberately leaves `success`/`message`/`expires_at` intact,
so callers can apply it without changing control flow.

**Second half — boot guard.** `AppServiceProvider::guardOtpTestingModes()` refuses to boot outside
local/testing when `EMAIL_OTP_MODE=testing`, `WALLET_OTP_MODE=testing` or
`OTP_BYPASS_ENABLED=true`, following the proven `guardJwtSecret()` pattern from RV-04. This is
defence in depth: `OtpDisclosure` stops the *disclosure*; the guard turns a dangerous deployment
into a loud boot failure instead of a silent misconfiguration.

**Verification** — `tests/Feature/Review/OtpDisclosureTest.php` (**OK, 8 tests, 15 assertions**),
asserting both directions: denied in production **even when the mode variable is set**, and still
delivered in `testing` so the suite keeps working. Both real services are exercised through their
actual code path, not a stand-in.

**Suite: 52 errors / 68 failures, 0 regressions vs the original baseline** (unchanged — these
leaks were invisible to the suite).

### 22.1 The guard caught a live dangerous configuration — OWNER ACTION REQUIRED

The guard immediately fired on this machine, because the working copy's `.env` contains:

```
APP_ENV=production
WALLET_OTP_MODE=testing
OTP_BYPASS_ENABLED=true
```

That is **exactly** the account-takeover configuration R1 RV-16 describes: an environment that is
`production`, carrying both the OTP testing mode and the OTP bypass. **Every `php artisan` command
fails until it is corrected.**

**The owner has chosen to fix `.env` and keep the guard strict.** The alternatives offered were:
let me edit `.env`, add a CLI escape hatch, or revert the guard. Their choice is the right one — each
alternative weakens a security guard.

**To unblock `php artisan`, either:**
- set `APP_ENV=local` (normal for a workstation), **or**
- set `WALLET_OTP_MODE=production` and `OTP_BYPASS_ENABLED=false`.

The **test suite is unaffected** — `phpunit.xml` sets `APP_ENV=testing`, which is exempt.

### 22.2 Two existing boot suites had to be corrected — and why that is legitimate

`PusherCredentialFallbackTest` and `EnvironmentGuardsBatchTest` simulate a production boot to probe
*other* guards (Pusher credentials, the queue driver). They broke, because `phpunit.xml` itself
sets `WALLET_OTP_MODE=testing` and `OTP_BYPASS_ENABLED=true` for the suite, so **no** simulated
production boot could ever succeed.

The existing code already documented this exact reasoning for the earlier T3-14 queue guard:

> "they must present an otherwise valid deploy — without it the T3-14 guard (correctly) throws
> first and the pusher behaviour under test is never reached."

My guard is simply the next one, so the same fix applies. New shared trait
`tests/Support/Concerns/SimulatesProductionBoot.php` clears the OTP modes for the duration of a
simulated boot and restores them afterwards. **No assertion was changed** — this alters the
environment being simulated, not what is asserted. Both suites are green again (**OK 8/11** and
**OK 10/14**).

Cost of getting there, recorded because partial environment knowledge produced a wrong fix three
times: (1) clearing only the Dotenv repository did not work — the guard still read `getenv()`;
(2) adding `putenv()` still did not work — PHPUnit also fills `$_ENV` and `$_SERVER`;
(3) all **three** sources must be cleared together. Each partial attempt was caught by re-running
the boot tests, not by inspection.

### 22.3 A test of mine made a live network call — hermeticity failure, self-reported

While testing the TextMeBot "not configured" branch, the test **called the real TextMeBot API**
(provider response: "Trial is over"), because `putenv('TEXTMEBOT_API_KEY')` does not remove a value
from the Dotenv repository that `env()` reads, so the real key was still present and the service
opened a connection. `Http::fake()` cannot intercept it, because that service uses Guzzle directly
rather than Laravel's HTTP client.

This is a genuine instance of what RV-37 is about, found by writing a test rather than by reading
the code. The test now clears the key via `Env::getRepository()->clear()` and asserts that as a
**precondition** before exercising the branch, so it cannot silently reach the network again.

### 22.4 What RV-16 still owes

| Half | Status | Why not done |
| --- | --- | --- |
| Delete the phone-OTP controllers/services/routes | **open** | **Owner decision.** R1 is explicit: "unless the client uses them (owner to confirm)". Removing a public endpoint breaks the app if the client calls it. |
| OTP codes stored in plaintext | **open** | Needs `hash_hmac` storage plus a read-path change; `OtpRepository` currently queries `where('otp_code', $code)`. |
| Mail sent synchronously inside `SignupController`'s DB transaction | **open** | Needs `Mail::queue` after commit, plus a failure-path decision. |
| Account enumeration (signup 409, forgot 404, `exists:users,email`) | **open** | **Product decision** — uniform 202 responses change client behaviour. |
| `sleep(5)` inside the TextMeBot worker (16 per node) | **open** | Belongs with the endpoint decision above. |

### 22.5 State: **PARTIAL — both verified security halves fixed; five items are owner decisions or larger refactors**
---

## 23. RV-37 — test determinism and hermeticity — **PARTIAL: order-dependence root-caused and fixed; `preventStrayRequests` still open**

**Problem.** V13 found seven test files calling `putenv()`. V14 measured the consequence: the
suite reported **53 failures in default order versus 55 under `--order-by=random`**. Tests changed
behaviour based on run order.

### 23.1 Fix 1 — `putenv()` leakage removed at the source (not per-file)

Three test files set `EMAIL_OTP_MODE=testing` in `setUp` and **none had a `tearDown`**, so the
value persisted for the rest of the PHP process. Adding a `tearDown` would have patched three
symptoms; the root cause is that the OTP modes were read straight from the environment.

**Root fix:** new `config/otp.php`, and every consumer now reads config:
`EmailOtpService::isTestingMode()`, `WhatsAppOtpService::isTestingMode()`, the boot guard, and
`TextMeBotOtpService`'s provider key (new `services.textmebot.api_key`). Config is rebuilt per
test, so an override cannot escape the test that made it. Deployments are unaffected — `env()`
inside the config files reads the same variables as before.

This also fixed the live-network incident from §22.3 at the root: the provider key is now
configurable, so "simulate an unconfigured provider" is `Config::set(..., null)` instead of
fighting three different env stores.

### 23.2 Fix 2 — read/write splitting was the real order-dependence

After fix 1 the suite was still order-dependent, so the remaining cause was measured rather than
guessed. Running the V14 seed again:

```
default order : 52 errors / 68 failures
random order  : 52 errors / 85 failures     <- still worse
```

Diffing the failing test names isolated it precisely: **15 extra failures, all in
`AdminDriverServiceTest`, all count-style assertions** (e.g. `total_drivers` = 2 where 0 was
expected). The class uses `RefreshDatabase`, and no static state exists, so the suspicion fell on
where the reads were served from.

**Root cause:** `config/database.php` configures read/write splitting (`read.host` =
`DB_REPLICA_HOST`, `sticky => true`). A plain `User::where(...)->count()` is served on the **read
connection — a separate PDO** — which cannot see the transaction `RefreshDatabase` opened. It sees
whatever earlier tests had already committed, so any aggregate assertion becomes order-dependent.

**Fix:** no splitting outside production. Local and testing read from the host they write to.

```php
'host' => [($_SERVER['APP_ENV'] ?? null) === 'production'
    ? env('DB_REPLICA_HOST', env('DB_HOST', '127.0.0.1'))
    : env('DB_HOST', '127.0.0.1')],
```

The check is `$_SERVER`, not `app()->environment()`, because config files are evaluated while the
container is still being built — the first attempt used `app()` and failed with
`Target class [env] does not exist` (135 errors), caught immediately by re-running.

**Result for that class:** identical **10 failures in both orders** (was 20 default / 35 random).

This is worth stating plainly: **the `putenv()` leak was the smaller half of V14.** Removing it
changed nothing about order-dependence on its own. The replica read path was the larger half, and
it was invisible until the failure sets were diffed by name.

### 23.3 Ratchets added — `TestDeterminismRatchetTest` (OK, 2 tests)

1. **No test file may call `putenv()`**, comments excluded. Three files are allow-listed, each
   with a stated reason, and all three qualify on the same bar — *the behaviour under test must be
   the resolution of an environment variable*:
   - `PusherCredentialFallbackTest` — `require`s `config/broadcasting.php` raw, whose `env()`
     calls read the process; `Config::set` cannot influence a re-required file.
   - `SessionCookieAndCorsTest` — same shape, proving a config file reacts to a changed variable.
   - `SeedCredentialsBatchTest` — the trait under test resolves a value from the environment.
2. **The suite must never use a real mailer.**

**Causality needle:** injecting `putenv('RV37_NEEDLE=1')` into a clean test file made the ratchet
fail naming it, and the file was restored MD5-identical. The ratchet is falsifiable.

Two bugs of my own were caught by that needle's first run: the allowlist is an **associative**
array keyed by path, so `in_array()` (which searches values) silently never matched — fixed to
`array_key_exists()`; and the path separator is `\` on Windows, so the keys never matched until
normalised.

### 23.4 What RV-37 still owes

| Half | Status | Why not done |
| --- | --- | --- |
| `Http::preventStrayRequests()` in `TestCase::setUp` | **open** | Must be measured first: enabling it will fail every test that legitimately calls a real provider, and each such test needs `Http::fake()`. That is a wider change than it looks. |
| Fake routing/geocoding/FCM at container level (`ROUTING_DRIVER=fake`) | **open** | Needs the same survey of which tests genuinely call providers. |
| CI runs the suite twice with `--order-by=random` and prints the seed | **open** | Belongs to RV-18 (CI signal). The measurement is now meaningful because RV-37 made order-independence real. |
| Remaining order dependence across other seeds | **open** | The V14 seed is fixed; two seeds do not prove general order-independence. |

### 23.5 State: **PARTIAL — the order-dependence root cause is found and fixed, verified in both orders; hermeticity ratchet open**
### 23.6 CORRECTION to 23.2 — the replica fix helped but did NOT make the suite order-independent

The 23.2 result is correct but incomplete, and stating it alone would overclaim. Measured on the
**full suite** after both fixes:

```
default order : 52 errors / 68 failures
random order  : 52 errors / 85 failures    <- still 17 worse
```

The 15 extra failures are still exactly the same set: the count-style assertions in
`AdminDriverServiceTest`. The fix is real but partial — run **in isolation** that class now gives
an identical **10 failures in both orders** (it was 20 default / 35 random), which proves the
replica read path was one real mechanism. What remains is that in a full run, some *other* test
leaves rows **committed** in MySQL, and `AdminDriverServiceTest`'s aggregates then count them.

**Causes ruled out by inspection, not assumption:**
- `putenv()` leakage — removed (23.1).
- any class using `DatabaseMigrations` — **none exist**;
- `artisan migrate` / `migrate:fresh` / `DB::commit` inside tests — **none found**;
- every `Tests\TestCase` subclass has a database trait (`RefreshDatabase`, `DatabaseTransactions`
  or `DatabaseMigrations`) — **0 classes without one**;
- `MigrationEffectsBatchTest` switches the default connection to an **in-memory SQLite**
  connection and restores it in a `finally` — it commits nothing to MySQL, so it is **not** the
  culprit, despite being the only class that touches the default connection.

**Next lead (not yet taken):** identify which class writes rows outside the per-test transaction
— most likely a service that opens its own connection, or a test that runs inside
`DatabaseTransactions` but performs a write on a second connection. The cheap way to find it is to
binary-search by running `AdminDriverServiceTest` after each other class in isolation, or to add a
teardown assertion that `users` is empty at the start of each `AdminDriverServiceTest` method.

This is left open deliberately rather than guessed at: three plausible causes were checked and
eliminated, and the next step is a measurement, not another hypothesis.