> SUPERSEDED for status/next steps by docs/audit/BACKLOG.md. History only.

# SyRide — Review Addendum R2

Delta to `APP_FUTURE_AUDIT_REVIEW.md` (called **R1** below). Read R1 first; this file only changes or adds.
Date: 2026-09-29. Same working rules as R1 §0 and `AGENTS.md`: one task at a time, explain → smallest fix → verify → `VERIFIED FIX` / `VERIFIED ROLLBACK`, log in `docs/audit/`, no secrets in the record. New tests go in `tests/Feature/Review/`; never edit an existing assertion just to turn it green (list it in RV-35 instead).

> **Start here:** see docs/audit/STATE.md (and docs/audit/BACKLOG.md for every task and its status).
> The map this block pointed at still exists below: sec 18 holds the commit map and the
> consolidated open-decision list, sec 12 is the per-wave progress table, and sec 9-sec 17 are
> the per-task logs. Their *status* role is superseded; their history role is not.

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
| 2 | **RV-37** | **PARTIAL** (§23): `putenv()` leakage removed at the source — new `config/otp.php`, and every OTP consumer plus the boot guard now read config, which Laravel rebuilds per test. Root-caused V14 order-dependence to read/write splitting: `->count()` ran on the separate read PDO and could not see the `RefreshDatabase` transaction — disabled outside production, and `AdminDriverServiceTest` went from 20 default / 35 random to an identical 10 / 10. Honest scope: the FULL suite is still order-dependent (68 default vs 85 under the V14 seed); §23.6 records four ruled-out causes and the next measurement. Determinism ratchet (§23.3) added and causality-needled. Suite unchanged: 52 errors / 68 failures, 0 regressions. |
| 2 | **RV-18** | **VERIFIED FIX** (§24): the V6 silent-skip is closed. Both workflows now set `DB_CONNECTION=mysql` at process `env:` level — proven (not remembered) to beat the non-forced sqlite pin — and the new `CiMySqlDriverTest` alarm turns any fallback RED instead of a skipped green. The money/geo floor is shown to genuinely RUN and PASS on MySQL (`OK (219 tests, 1412 assertions)`), so flipping CI does not make the gate red. Three raw-ENUM migrations gained the T3-6 driver guard — including this audit's own RV-02 L1 noshow migration, which had reintroduced the very defect RV-18 exists to remove; the harness is needle-proved. `phpunit.mysql.xml` is a convenience config, deliberately NOT wired into CI. |
| 2 | **RV-36** | **VERIFIED FIX** (§25): V12 had already refuted RV-36's headline (`auth()->id()` null), so measuring the real code found two defects the audit never named — `bulkAction`'s global `exists` rule was an **existence oracle** (422 vs 404 reveals which ids exist across the table) and **silently no-oped** foreign ids while returning success. Both closed by scoping `exists()` to the caller. Three blessed tests strengthened + 2 new IDOR/oracle tests added (needled: reverting the scope fails exactly those 2). `AuthFacadeRatchetTest` bans `auth()->`/`Auth::id()`/`Auth::user()` in app/Http+Services — zero sites remain, needle-proved. Dead `PushNotificationController::store()` conversion only; its deletion is RV-31. |
| 2 | RV-22, RV-38 | PENDING |
| 3 | RV-40, RV-09, RV-02 (L2), RV-10, RV-11, RV-15, RV-21, RV-20 | PENDING (RV-40 is the prerequisite for RV-02 L2 / RV-09 / RV-15; RV-02 L1 already consumed the `void` enum value it needed) |
| 4 | RV-25, RV-24, RV-17 | PENDING — **unblocked**: V1 is recorded, so R2's decision table selects the "rows are transposed" branch. Note: RV-34 preserved the transposed fixtures verbatim, so the baseline for RV-25 is unchanged |
| 5 | RV-12, RV-26, RV-27, RV-29, RV-19, RV-23 | PENDING |
| 6 | RV-28, RV-30, RV-31, RV-32, RV-33, RV-39 | PENDING |

**Current task:** RV-34 complete (§17). Remaining red is 117 non-passing tests, all pre-existing
and previously masked by `setUp` errors — inventoried for RV-35.
**Also awaiting the owner:** the `phpunit.xml` remote-database hazard (§17.5) — the worktree file
points the suite at a reachable Aiven database and `RefreshDatabase` drops tables.
**Next:** see docs/audit/STATE.md.
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
  RESOLVED - VERIFIED FIX ..............................  8   RV-02 (L1), RV-05, RV-06,
                                                              RV-07, RV-18, RV-34, RV-35,
                                                              RV-36
                                                              (RV-07 agent-side only; key
                                                              rotation + history purge still
                                                              owed by the owner)
  PARTIAL - a verified half is fixed, remainder open .....  6   RV-01, RV-04, RV-13, RV-14,
                                                              RV-16, RV-37
  BLOCKED on the owner ................................  1   RV-03 (decision 6)
  NOT STARTED .........................................  25   RV-08..RV-12, RV-15, RV-17,
                                                              RV-19..RV-33, RV-38..RV-40
                                                 -----
                                                  40   total
Verify checks  V1-V16:  15 recorded, 1 never run (V7 - no replica access)

Suite:  errors 443 -> 52 (-391)   failures 52 -> 68   regressions 0   (stable since RV-35)
          tests 1908 -> 1944 after RV-34/35/18/36; RV-36 = security hardening, not red burn
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

**Wave 2 status:** RV-34, RV-35, RV-18, RV-36 = VERIFIED FIX; RV-13, RV-14, RV-16, RV-37 = PARTIAL (verified halves done, remainder is owner decisions or larger refactors — see each §). Remaining untouched Wave-2 items: RV-22, RV-38. The order below is now the remaining backlog in priority order.

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
---

## 24. RV-18 — CI signal (the V6 driver leak) — **VERIFIED FIX: money/geo can no longer silently skip on green; migration guards closed; whole-suite red is separate tracked debt**

**Problem.** V6: the committed `phpunit.xml` pins `DB_CONNECTION=sqlite` / `:memory:` **without**
`force`; the workflows provision a real MySQL service but write `DB_CONNECTION=mysql` only into
`.env`; PHPUnit applies its `<env>` values before Laravel loads `.env`, and Laravel's Dotenv
repository is **immutable** — so nothing written later can win. Net effect: **CI ran on sqlite while
reporting green**, and every money/geo/lock suite guarded by
`if (env('DB_CONNECTION','sqlite') !== 'mysql') markTestSkipped(...)` **skipped**. A green pipeline
that had never executed one money path is worse than red: it hides regressions exactly where the
money lives.

**Fix — at the layer that can actually win.** The workflows now set `DB_CONNECTION=mysql` at the
**process (job/step `env:`)** level. A pre-set process variable is precisely what PHPUnit's
non-forced `<env>` refuses to overwrite, so it is the only layer that beats the pin.

> **Precedence proven, not remembered.** A throwaway config pinning `DB_CONNECTION=sqlite` (no
> force) was run with `DB_CONNECTION=mysql` set in the process, and the driver-alarm test reported
> a real MySQL connection — **OK (2 tests, 10 assertions)**. The temp config was deleted. Every one
> of this session's ~50 MySQL runs has relied on exactly this mechanism, and RV-37 hit the same
> precedence three times.

`phpunit.xml` is user-owned (its worktree copy holds the real local DB), so it was **not** rewritten.
A committed `phpunit.mysql.xml` that omits the driver pin is added for convenience
(`vendor/bin/phpunit -c phpunit.mysql.xml`); it is deliberately **not** referenced by the workflows,
because the fix is the process env, and its own header now says so (an earlier draft wrongly claimed
CI used it — corrected).

**The alarm — `tests/Feature/Review/CiMySqlDriverTest.php` (new).** Inert locally, and under
`CI_REQUIRE_MYSQL=1` (which the workflows now set) it **fails red** if the real driver is not mysql,
naming the leak and its cause. Falsifiable by construction:

| State | Result |
| --- | --- |
| locally, `CI_REQUIRE_MYSQL` unset | **Skipped: 2** (inert, announced — not a silent pass) |
| `CI_REQUIRE_MYSQL=1` + MySQL | **OK (2 tests, 10 assertions)** |
| `CI_REQUIRE_MYSQL=1` + sqlite (leak re-injected) | **RED** — "running on 'sqlite' … silent-skip leak" |
| process mysql + a config pinning sqlite (precedence proof) | **OK** — driver really mysql |

**Proof the fix is real and safe, not just plausible.** The money/geo floor *without* the process
variable (faithful to old CI) versus *with* it (the fix):
- **Without:** `RideChannelAuthorizationTest` Skipped 6, `AdminFinancialReportEscrowTest` Skipped 4,
  `MoneyPathBatchTest` Skipped 7, `PassengerConfirmCompletionTest` Skipped 10 — the silent lie.
- **With:** those same suites **OK** (6/11, 4/14, 7/21, 10/47) — they genuinely execute.

The **entire** architecture floor under real MySQL is **green: OK (219 tests, 1412 assertions)**, so
flipping CI to MySQL does **not** turn the gate red — the floor never depended on the leak. (The
stderr noise in that run is intentional negative-path logging: caught `InvalidArgumentException` /
`PDOException` in healthcheck and double-confirm tests.)

**Migration guards (V6's second half).** Three raw `ALTER TABLE ... MODIFY ... ENUM` migrations ran
with **no driver guard**, so a `migrate:fresh` on a foreign driver fataled instead of skipping. The
established T3-6 sibling guard `if (DB::connection()->getDriverName() !== 'mysql') return;` was
added to all three (up **and** down):
- `2026_08_18_120000_add_launched_status_to_rides.php`
- `2025_01_01_000001_add_sycash_to_employees_role_enum.php` (was leaning on a `try/catch` to swallow
  the non-MySQL failure — made explicit; MySQL behaviour unchanged)
- `2026_09_30_000000_add_void_to_noshow_reports_status.php` — **this audit's own RV-02 L1
  migration**, which had reintroduced the very defect RV-18 exists to remove. Recorded as a
  self-inflicted finding and now guarded like its siblings.

These three joined the existing `MigrationEffectsBatchTest` harness (in-memory sqlite with stub
`employees`/`noshow_reports` tables pre-created, so the *only* possible failure is SQLite failing to
**parse** MySQL ENUM syntax, never a misleading "no such table"). **Causality needle:** deleting the
rides guard made the harness fail with `SQLSTATE[HY000]: near "MODIFY": syntax error`, and the file
restored MD5-identical. Harness **OK (5 tests, 33 assertions)**.

**A bug in my own alarm, caught before shipping:** the suite-existence check used
`glob('tests/**/...')`, but **PHP's `glob` does not treat `**` as recursive** — it only passed via a
depth-3 `?:` fallback, and would false-red the green floor if a suite moved one folder deeper
(ironic for a task about flaky-ignored gates). Replaced with a real `RecursiveDirectoryIterator`.

### 24.1 Scope honestly held

- **Fixed and verified:** the V6 silent-skip mechanism (process env + alarm) and the three missing
  migration guards. CI's architecture floor is now provably meaningful on MySQL.
- **Not RV-18's remit:** the *broad* suite is still red (52 errors / 68 failures) — the tracked
  fixture/product debt burned down by RV-34/RV-37 and inventoried in §19/§23. The architecture
  workflow's header deliberately keeps its floor smaller than the whole suite, because an always-red
  gate is "the same disease as the `|| true`" removed under T3-11. Whole-suite green is RV-35+/
  product decisions, not a CI-signal fix.
- **Genuinely unverified:** the exact GitHub Actions runtime of `php artisan test` under the job env
  could not be executed locally (the owner's `.env` is still `APP_ENV=production`, and the RV-16
  boot guard blocks `php artisan` until §22.1 is corrected). The mechanism was instead proven at the
  PHPUnit layer that `php artisan test` shells out to, plus a direct precedence test against a config
  pinning sqlite. Both workflow files were YAML-validated (python yaml: OK).

### 24.2 State: **VERIFIED FIX**

---

## 25. RV-36 — notification `bulkAction` — **VERIFIED FIX (real defect found: existence-oracle + silent no-op; auth premise was already refuted by V12)**

**Problem.** R1/R2 RV-36 claimed `bulkAction()` (and `markAsRead`/`destroy`) were no-ops because
"`auth()->id()` is never populated by this app's JWT middleware."

**The premise was wrong, and it is recorded as corrected — not inherited.** V12 had already
**refuted** it: `JwtAuthMiddleware` calls BOTH `setUserResolver()` AND `Auth::setUser()`, so
`auth()->id()` resolves and `bulkAction` was functioning. RV-36's headline was a false alarm, and
this task does not repeat it.

**But measuring the actual code found two real defects in `bulkAction` that the audit did not
describe**, both in the validation rule (`'notification_ids.*' => 'exists:user_notifications,id'`):

1. **Existence oracle.** `exists` checked the id against the WHOLE table. So a
   non-existent id → 422, while another user's REAL id passed validation, was dropped by the
   `where('user_id', …)` filter → the empty-branch → 404. The status code therefore distinguished
   "does not exist" from "exists but belongs to someone else" — leaking which notification ids
   exist across the entire table to any authenticated caller.
2. **Silent no-op framed as success.** A request mixing own + foreign ids passed validation, acted
   only on the owned rows, and returned `"Notifications marked as read"` — reporting success for
   ids it never touched. A client cannot tell that its foreign ids were ignored.

Both are closed by scoping `exists()` to the caller's own rows
(`Rule::exists('user_notifications','id')->where('user_id', $userId)`): now a foreign id and a
non-existent id both produce the **same** 422 (no oracle), and no foreign id can slip into the
silent-success path.

**Fix** — `app/Http/Controllers/API/NotificationController::bulkAction()`:
- `auth()->id()` → `$request->user()->id` (consistency: the method was the lone exception in a
  controller that uses `$request->user()` everywhere; V12 confirms both resolve identically, so
  this is house-rule alignment, not a behaviour change).
- validation rule scoped to the caller (closes 1 and 2 above).

**R2 §1.3's acceptance** ("replace the `assertNotEquals(500,…)` blessings with exact statuses and
add a ratchet") — this was **sanctioned by the plan**, so strengthening is correct, not me
weakening a test to pass:
- `test_can_mark_notification_as_read` — `assertNotEquals(500)` → `assertStatus(200)` **and** the
  row is actually `read_at != null`.
- `test_can_delete_notification` — same → `assertStatus(200)` **and** the row is gone.
- `test_bulk_action_mark_read` — same → `assertStatus(200)`, `success:true`, **and both rows
  flipped**. Its docblock claiming `auth()->id()` "never populates" was deleted — that was the
  refuted premise.
- **New** `test_bulk_action_cannot_touch_another_users_notification` — foreign id → 422, victim's
  row untouched (IDOR).
- **New** `test_bulk_action_does_not_leak_id_existence` — asserts a real-foreign id and a
  non-existent id return the **same** status, so existence is not distinguishable.

`NotificationTest`: **OK (12 tests, 23 assertions)** (was 10).

**Causality needle (the falsifiability proof).** Reverting ONLY the scoped rule back to the old
global `exists:user_notifications,id` made exactly the two new security tests **fail**
(`Failures: 2`, assertions 23→22); restoring returned **12 OK**. So the new tests genuinely guard
the fix rather than passing vacuously. File restored MD5-identical.

**Ratchet — `AuthFacadeRatchetTest` (new, OK 1 test).** Bans `auth()->` / `Auth::id()` /
`Auth::user()` in `app/Http` and `app/Services`. The two legacy sites were both converted —
`NotificationController::bulkAction` (above) and `PushNotificationController::store`'s
`auth()->id()`. `Auth::setUser()` is a WRITE in the middleware and is deliberately not matched
(it must stay). Zero banned sites remain, so the ratchet passes on committed code.
Causality-needled: injecting one `auth()->id()` line into a controller made it fail naming that
line; restored MD5-identical.

**`PushNotificationController::store()` — note, not fixed.** It is **unrouted** (V12: 0 routes
reference it) and so is effectively dead code. RV-36 converted its `auth()->id()` only to satisfy
the ratchet; **deleting the dead class/methods is RV-31's call, not RV-36's.**

**Scope honestly held.** The remaining notification work — deleting the three stub notification
classes and `NotificationChannel` (the empty `join` stub) — is **decision 12** (open) plus RV-31;
not done here. The two genuine RV-36 security defects are fixed and the ratchet is green.

**Suite:** 1944 tests, 4348 assertions, 52 errors / 68 failures (unchanged), 0 regressions vs the
original baseline. RV-36 adds no red and burns none — it hardens an endpoint and locks the pattern
down; the +1 test is the ratchet, +2 the new security tests, and three existing blessed tests were
strengthened in place.

### 25.1 State: **VERIFIED FIX**
---

## 26. Wave 3 (money/lifecycle) — IN PROGRESS, run uncommitted for owner review

The owner asked for Wave 3 "at once," uncommitted, with progress visible. Worked in strict
dependency order (RV-40 is the prerequisite for RV-02 L2 / RV-09 / RV-15). **Nothing committed** —
all changes are in the working tree for the owner to inspect. Full suite after the three landed:
**1955 tests, 52 errors / 68 failures (unchanged), 0 regressions vs the original baseline**; the
+11 tests are the new pins. Money green-floor suites (MoneyPath, Booking, PassengerConfirm,
WalletTransactionService, AdminFinancialReportEscrow) all still OK.

### 26.1 RV-40 — money schema — **VERIFIED FIX**

**Root cause (proven in code, not assumed).** `bookings` stored no monetary column; 16 sites
recomputed money as `seats * ride.price_per_seat` at settlement time, and `Booking` cast a
`total_price` attribute for a column that does not exist. Because `price_per_seat` is mutable and
`seats` changes on a partial cancel, "what a booking paid" was never a fact — a price edit or a
seat cancellation silently rewrote a settled amount and the ledger could disagree with no
arbitrating source.

**Fix.**
- Migration `2026_10_01_000001_add_money_snapshot_to_bookings` adds `unit_price`, `amount_paid`,
  `escrow_held`, `payment_method`, `idempotency_key`, and `unique(user_id, idempotency_key)`.
  Purely additive + driver-portable Blueprint (no raw MySQL), so it cannot weaken existing money
  integrity and is safe on sqlite and MySQL. **Applied and confirmed on real MySQL** (all 5
  columns + the index present; migration recorded).
- `Booking` `$fillable`/`$casts` updated; the phantom `total_price` decimal cast replaced by real
  `unit_price`/`amount_paid`/`escrow_held` casts. The two `total_price` references in the API are
  display keys recomputed in the resource/controller (they never read a model attribute), so
  removing the dead cast changed nothing observable.
- `chargePassengerForBooking` (the single place e-pay money leaves a passenger, for both the
  direct and the accepted-request path) now writes the immutable snapshot `unit_price`/
  `amount_paid`/`payment_method`. `escrow_held` is deliberately NOT written here — it means
  "still sitting in SyCash", which only RV-02 L2 (where settlement paths clear it) can keep
  correct; writing it without clearing it everywhere would leave a stale truth. That is the
  honest, split-with-RV-02L2 decision.
- `bookings:backfill-money-snapshot` command fills existing rows **from the `escrow_received`
  ledger rows, never from the mutable ride** (a recompute-based backfill would commit the exact
  bug RV-40 removes). Idempotent (only `amount_paid = 0`), non-destructive, `--dry-run`, and it
  refuses to fabricate an amount for an e-pay booking with no ledger row.

**Verification.** `RV40MoneySnapshotTest` OK (3/9): snapshot captured; `amount_paid` does NOT move
when `ride.price_per_seat` is edited afterward; `payment_method` snapshot survives the ride flipping
to cash. Needle: disabling the snapshot write → those 3 fail (assertions 23→22→ restore MD5-identical).
`RV40BackfillSnapshotTest` OK (4/13): uses the ledger amount not the ride price; idempotent;
no-ledger e-pay row not fabricated; dry-run writes nothing. Needle: recompute-from-ride → 3 fail.

### 26.2 RV-15 — booking idempotency — **VERIFIED FIX**

**Root cause.** `bookRide` deduped on Redis `booking:idem:{key}`: (1) NOT user-scoped — another
user replaying a key got the first user's booking incl. their phone (cross-tenant leak); (2) the
check ran OUTSIDE the transaction — two concurrent same-key requests both created a booking; (3)
it lived only in Redis — a flush erased the dedup and a later replay duplicated the booking, and
the key was never persisted.

**Fix.** Dedup is now the DB: inside the transaction, look up
`Booking::where('user_id', …)->where('idempotency_key', …)`, and `Booking::create` persists
`idempotency_key`, backed by RV-40's `unique(user_id, idempotency_key)` index as the atomic
backstop. Redis/`Cache` import removed (no other use).

**Verification.** `RV15IdempotencyTest` OK (4/12): key persisted; same-user replay returns the
same booking id (existing `BookingTest` idempotency test still green); a different user replaying
the SAME key gets their OWN booking, not the first user's; the unique index exists. Needle:
restoring the old non-user-scoped lookup → the cross-tenant test fails (12→10 assertions).

### 26.3 RV-09 — money concurrency — **VERIFIED FIX (safe subset); the throughput redesign is separate**

**Deadlock, proven not assumed.** Every settlement path (`releaseEarningsToDriver`, the refunds,
the no-show settlements, `releaseEscrowToDriver`) locks the global SyCash wallet FIRST. But
`chargePassengerForBooking` locked `passenger → SyCash` — inverted. A user who is simultaneously a
booking passenger and a paid driver is the classic MySQL-1213 deadlock (charge holds the shared
wallet, a settlement holds SyCash, each waits on the other). The L723 "same order everywhere"
comment was false for the charge path.

**Fix.** `chargePassengerForBooking` now locks SyCash before the passenger wallet, so ALL seven
money entrypoints acquire SyCash first. Every money transaction serialises on the single global
SyCash row before touching any user wallet, which structurally removes the lock-order inversion —
no transaction can hold a user wallet while waiting for SyCash. Money math is untouched.
Side-effect ordering (defect #4): `config/queue.php` redis/database/sqs now `after_commit => true`,
so a job queued inside a money transaction is only pushed after commit (was `false` — a worker could
re-read a not-yet-committed model and lose the notification/broadcast).

**Verification.** All money + booking + RV-40/15 suites green after the reorder (behaviour-
preserving, as designed): MoneyPath 7/21, PassengerConfirm 10/47, WalletTransactionService 27/29,
Booking 11/15, escrow 4/14. Full suite 0 regressions.

**Not done here (recorded so it is not mistaken for finished):** RV-09's larger items are coupled
and money-critical — (a) wrapping strategies so they stop swallowing exceptions + `DB::transaction`
retry `attempts=3`; (b) events carrying ids not models; (c) the real throughput fix, replacing the
single mutable SyCash balance with per-booking `escrow_held` (RV-40's column) so SyCash is derived —
that IS RV-02 L2 and must not be half-wired. SyCash-first makes it deadlock-free; it does NOT fix
the serialisation *throughput* bottleneck (all money still serialises on one row). Left to RV-02 L2.

### 26.4 Grounded but NOT changed — needs an owner decision (money-adjacent, refuse to guess)

- **RV-11 (score).** The three mutation paths (`applyAction`, `applyScore`, `UserScore::applyDelta`)
  genuinely disagree: `recordRideCompleted` calls `applyAction` (which increments `total_rides`
  on a positive action) AND `incrementRides` → **double-count**; the `firstOrCreate` defaults write
  `tier`/`cancel_rate` as ATTRIBUTES with empty no-op mutators and are not fillable → silently
  dropped; two tier schemes disagree (`resolveTier` platinum≥200/gold≥150/silver≥100 vs
  `getTierAttribute` Gold≥80/Silver≥60/Bronze≥40); start score is 70 in `initializeScore` vs 100
  in the create paths; `applyDelta` clamps [0,100] while `applyAction`/`applyScore` don't clamp the
  top. The fix (one `ScoreLedger::apply()`, one clamp, one tier) requires the OWNER to pick the
  canonical clamp ceiling, tier bands, and starting score — there is no `config/score.php`. Choosing
  them unilaterally would rewrite trust scores, a decision the audit explicitly defers (§RV-11,
  decision-table style).
- **RV-20 (strategy).** Only `processRideCompletionPayment` routes through `PaymentStrategyFactory`
  (`BookingService:532`); charge (`:112/:172`) and refund (`RideService:184`) still call the
  concrete wallet service directly. Routing them through the factory is behaviour-preserving only
  if the strategies are faithful — but it overlaps the RV-02 L2 escrow redesign, so doing it now
  would half-wire two coupled tasks.
- **RV-10 (lifecycle).** The search `departure_time >= now()` and "no booking a past departure"
  fixes are safe correctness, but the auto-complete/`rides:advance-status` scheduler needs the
  **auto-confirm-hours** product decision (R1 RV-10). Not started this round; the safe search filter
  is a candidate for the next.
- **RV-21 / RV-02 L2** — wallet identity/money creation and the escrow settlement redesign (the
  latter is what makes RV-40's `escrow_held` and RV-09's throughput fix complete).

**State: 3 of 8 Wave-3 tasks VERIFIED FIX (RV-40, RV-15, RV-09-safe-subset); RV-11/20/10/21/02-L2
grounded, 4 of them gated on owner decisions or coupled-refactor sequencing. UNCOMMITTED.**
### 26.5 RV-21 — wallet identity / money-boundary — **PARTIAL: the escrow-hijack boundary is VERIFIED FIX; the wallets.kind / double-entry redesign is a coupled refactor**

**Root cause (proven in code).** `lockWalletByPhone()` — the resolver that finds the SyCash /
Primary system wallet in EVERY money path — matched on `phone_number` ALONE and never asserted
`user_id IS NULL`. The phone defaults ship in `config/admin.php` (`0987654321` / `0912345678`),
and `SystemWalletSeeder` uses `firstOrCreate(['phone_number' => …])`. So a normal user who
registered one of those reserved phones and created a wallet **before the seeder ran** would OWN
the wallet that receives all escrow, refunds and platform fees — money silently routed to whoever
grabbed the phone first. That is "money created from nothing by the wrong actor" at the identity
boundary.

**Fix (decision-free, deny-only).** `lockWalletByPhone()` now scopes to `whereNull('user_id')`
and fails closed with a clear `RuntimeException` if no system wallet matches, so a user-owned
wallet can never be adopted as the escrow sink. Legitimate system wallets (user_id NULL) resolve
unchanged, and the fix only *removes* a path to money — it never grants one, so it cannot weaken
integrity.

**Verification — `RV21SystemWalletBoundaryTest` OK (2 tests, 5 assertions):** a user-owned wallet
on the SyCash phone cannot receive escrow (charge fails closed; balance untouched); a correctly
seeded `user_id NULL` wallet still resolves and receives the escrow. **Causality needle:** the
first version used an EMPTY hijacker wallet and passed with the guard removed — `assertSufficientBalance`
threw first and masked the outcome (a VACUOUS test). Funding the hijacker's wallet so the attack
would truly succeed made it falsifiable: removing `whereNull('user_id')` now fails exactly the
fail-closed assertion. This is the third needle this session that caught its own test being
vacuous — pinning that a green pin test must be needle-checked, not trusted.

**No regression:** the full money floor is green after the stricter lookup (MoneyPath 7/21,
PassengerConfirm 10/47, WalletTransactionService 27/29, escrow 4/14, FinancialSurface 7/20,
ScoreTransaction 21/33, Noshow 3/11, CancelSeats 2/7, WaveZero 9/24, Booking 11/15, RideController
8 / WalletTest 3 — all their established pre-existing counts). No test asserted the old exception
message (checked), so renaming it is safe.

**Left open (not this boundary fix):** RV-21's full remedy — a `wallets.kind` enum
(user/escrow/platform/treasury), resolving by kind, double-entry top-ups (Σ balances = 0),
maker-checker + daily limits, and renaming Primary/SyCash — is a coupled schema+refactor with
product thresholds, grouped with RV-02 L2. The seeder's `firstOrCreate(['phone_number'=>…])`
adopting a claimed phone is the mirrored half and should also move to a kind-scoped idempotent
seed; recorded for that refactor rather than patched inconsistently here.

**Wave-3 total so far: RV-40, RV-15, RV-09 (safe subset), RV-21 (money-boundary) = 4 verified
fixes, all needle-checked.** RV-10/11 grounded-blocked on owner decisions; RV-20 / RV-02 L2 are
the coupled escrow redesign. UNCOMMITTED.
### 26.6 Regression-gate catch (recorded because it changed a test) — **VERIFIED FIX**

The targeted runs of RV-21 missed one file; the authoritative **full-suite** run then found
exactly 3 new failures: `EPayPaymentStrategyTest`'s booking-payment/refund tests. Root cause was
not the guard — the test's own `setUp` created the system_admin/SyCash wallets with
`'user_id' => $user->id`, i.e. the fixture encoded precisely the user-owned-on-system-phone
configuration RV-21 now rejects, while `SystemWalletSeeder` and every other money test use
`user_id => null`. **Fixed the fixture to match production reality (one line), not the guard** —
weakening the boundary to re-green a buggy fixture would have been the exact inversion of the
rule. After the fix that file is back to its established `OK (12 tests, 13 assertions)`.

**Final verified state of Wave 3 (full suite, against the `rv34_before.txt` baseline):**
1957 tests · 52 errors / 68 failures (unchanged from pre-Wave-3) · **0 regressions** ·
non-passing 468 -> 111. Money floor re-confirmed green after every Wave-3 change.

**Wave 3 ledger:** VERIFIED FIX = RV-40, RV-15, RV-09 (safe subset), RV-21 (money boundary),
+1 fixture correction. Grounded-and-blocked-on-owner-decisions = RV-10 (auto-confirm hours),
RV-11 (tier bands / clamp ceiling / start score). Coupled-refactor-not-half-wired = RV-02 L2
(= the RV-09 throughput escrow redesign and RV-20 strategy wiring's remaining third).
All left UNCOMMITTED in the working tree for the owner; `git status` = 11 files
(4 modified, 6 new, + phpunit.xml untouched/user-owned).
### 26.7 RV-10 — ride lifecycle: search-excludes-departed — **PARTIAL: the decision-free search guard is VERIFIED FIX; the booking rule + auto-complete scheduler remain owner-gated**

**Grounding that made this safe, not guessed.** Before editing I read every search
consumer's fixtures. `RideSearchServiceTest` and `UntangleBatchTest` create rides with FUTURE
departures (`addDays(3)`, default `departureMinutes = 2880` ≈ +48 h), so `departure_time >= now()`
cannot exclude them — confirmed empirically (both suites green: 17/22 and 8/213). The booking-side
guard that RV-10 also names ("book requires departure_time > now + min") is the collision risk
(the settlement corpus books PAST-departure rides deliberately — the 335 `RideBuilder` sites),
so it is NOT applied here; it is coupled to RV-02 L2 + the auto-confirm decision. Only the
search filter, which the audit states as correctness with no product input, was changed.

**Root cause (proven).** `searchRides` matched `whereDate(departure_time, date)` + ACTIVE + seats.
Because nothing advances ACTIVE → FINISHED outside the scheduler, a ride whose departure had
passed still satisfied its searched calendar day and stayed bookable-looking in results
indefinitely.

**Fix.** One added predicate: `->where('departure_time', '>=', Carbon::now())`, with a comment
stating it is strictly narrowing and decision-free.

**Verification.** `RV10SearchExcludesDepartedTest` OK (2 tests, 8 assertions), `Carbon::setTestNow`
frozen at 12:00 so the morning-has-departed / afternoon-has-not pair is deterministic (the audit's
own verify method): the departed 09:00 ride is excluded, the upcoming 15:00 ride is returned; a
second test asserts both rides are identical in status/seats/geometry and differ ONLY by
departure_time, so the exclusion cannot pass for the wrong reason. **Causality needle:** removing
the one filter line makes BOTH tests fail ("must NOT be surfaced", "only the future ride matches");
file restored MD5-identical. Non-vacuous. No new regressions (Untangle 8/213, MoneyPath 7/21;
RideControllerFull 8 / RideTest 2 are their established pre-existing counts).

### 26.8 Wave-3 running ledger (updated at §26.7)

- VERIFIED FIX (needle-checked): **RV-40, RV-15, RV-09 (safe subset), RV-21 (money boundary),
  +1 EPay fixture correction, RV-10 (search guard).**
- Owner-gated, grounded, NOT guessed: **RV-11** (score: two contradicting tier schemes, clamp
  100-vs-200, start 70-vs-100, no `config/score.php`); **RV-10 booking rule + `rides:advance-status`
  auto-complete** (needs the auto-confirm-hours product decision).
- Coupled escrow redesign — do not half-wire: **RV-02 L2** and **RV-20** (strategy wiring overlaps
  RV-09's throughput fix; both rewrite settlement money math, so they must land together with the
  per-booking `escrow_held` model RV-40's column enables).
- **RV-21 full** (`wallets.kind` enum + double-entry top-ups + maker-checker thresholds) and
  **RV-09 full** (txn `attempts=3` + strategies stop swallowing `Exception` + events carry ids) —
  the non-deadlock, non-boundary halves — are recorded for a coupled pass rather than partial edits.
### 26.9 RV-11 — dead third mutation path deleted — **PARTIAL (decision-free sub-step): VERIFIED; the consolidation itself is owner-gated, now with proof**

**What was done.** `ScoreService::applyScore()` (97 lines: a stray "drop this into" paste-
instruction docblock plus the method) was dead code — a repo-wide grep found **no caller in
app/, routes/ or tests/**; the only other `applyScore` is `SyrideSeeder`'s *own* private method
(a different symbol). R1 RV-11 explicitly lists "delete `applyScore`", so removing it is
decision-free and collapses RV-11's divergent implementations from three to two.
`applyAction`/`resolveTier`/`getScore`/`initializeScore` verified intact; the boundary-guarded,
lint-verified deletion reported `lines 445 -> 348`, and **Pint then dropped the imports the dead
path had been the only user of**.

**Verification.** ScoreTransaction 21/33, DriverNoShowPolicy 18/26, Untangle 8/213, MoneyPath
7/21, PassengerConfirm 10/47 all green. Authoritative full suite: **1959 tests, 52 errors /
68 failures (unchanged), 0 regressions** vs the original baseline.

**Why the REST of RV-11 is genuinely not decision-free (evidence, not caution).** Two real
defects remain, but every candidate fix changes who is penalised, so the audit's "act only with
owner approval" applies:

1. **Silently-dropped writes.** `applyAction` assigns `cancel_rate` (L277) and `tier` (L285), but
   neither is in `$fillable` and both have *empty no-op mutators*, so those two writes never reach
   the DB. Any fix changes the values the admin dashboard shows.
2. **Double-count.** `recordRideCompleted()` calls `applyAction()` — which already does
   `total_rides + 1` on a positive result — and *then* `incrementRides()`, so a completed ride
   counts twice. Fixing it changes `total_rides`, which feeds `cancel_rate`.

**And `cancel_rate` is penalty-gating, not display.** `DriverCancelRidePolicy` /
`PassengerCancelPolicy` compute the high-cancel-rate penalty from `total_cancellations >= 3`
**and** a rate threshold, reading `$userScore->cancel_rate`, which resolves through the
*accessor* (`total_cancellations / (total_rides + total_cancellations)`) and therefore moves with
any change to either counter. So "just fix the double-count" would silently change who gets
penalised for cancelling — the kind of money/trust-value choice this audit reserves to the owner,
alongside the unresolved tier bands (>=80/60/40 Gold vs >=200/150/100 silver), clamp ceiling
([0,100] vs uncapped), start score (70 vs 100), and the absence of any `config/score.php`.

**Owner input needed to finish RV-11:** canonical tier bands; clamp ceiling; starting score; and
whether `total_rides` should count a completed ride once (fixing the double-count). With those
four, one `ScoreLedger::apply()` can be built with pin + boundary + needle tests.

### 26.10 Wave-3 ledger (this goal pass)

- **VERIFIED FIX (each needle-checked):** RV-40, RV-15, RV-09 (safe subset), RV-21 (money
  boundary), RV-10 (search guard), + EPay fixture correction, + RV-11 dead-path deletion.
- **Grounded, owner-gated (values refused, not guessed):** RV-11 consolidation (§26.9),
  RV-10 booking rule + `rides:advance-status` auto-confirm (§26.7), RV-02 L2 / RV-20 /
  RV-21-full / RV-09-full (coupled escrow + platform-fee redesign, §26.8).
- **Every change UNCOMMITTED** for owner review; this file is the durable record.
### 26.11 RV-21 — seeder half of the escrow-hijack — **VERIFIED FIX** (plus a self-caught process error)

**Why this was still open after §26.5.** The boundary fix made money paths *refuse* a
user-owned wallet on a reserved phone, which is correct — but it left the deployment
**unrepairable**: `SystemWalletSeeder` used `firstOrCreate(['phone_number' => …])`, and
`wallets.phone_number` is UNIQUE, so when a user had already claimed the reserved phone the
seeder *matched that row*, created nothing, and still printed "✅ System wallets ready". Re-running
it could never fix the state, and the money boundary now throws instead — a silent-false-success
turning into an undiagnosable outage.

**Fix.** `ensureSystemWallet()` resolves by phone, then: absent → create with `user_id NULL`;
already a system wallet → no-op (never duplicates, never resets a live escrow balance); owned by a
user → **throw `RuntimeException` naming the offending wallet id, its owner and the remedy**. It
never mutates that user's balance automatically. Also made `$this->command` null-safe so the seeder
is runnable/testable outside the Artisan command.

**Verification — `RV21SystemWalletSeederTest` OK (4 tests, 10 assertions):** creates both wallets
`user_id NULL`; re-seeding twice is a no-op that preserves a 4,500,000 escrow balance and does not
duplicate; the hijacked-phone case throws and names wallet + phone (and conjures no fake system
wallet); the two reserved phones are asserted distinct — if a config ever collapsed them, Primary
and SyCash would share one wallet and the 5% platform fee would silently land in escrow.

**Causality needle, done twice because the first was invalid.** The needle must isolate one
behaviour, so replacing the whole file (reverting to the old `firstOrCreate`) failed the WRONG way
— it also removed the null-safe `command?->`, and PHPUnit promotes that warning to an error, so
"3 failures" proved nothing. Replaced the throw with a bare `return` instead (file lints clean):
**exactly one test fails** — the hijack test — while the other three pass. That is the correct
needle shape: sensitive to the guard, not over-broad. File then restored by rewriting it and
re-verified green (hash-based restore was impossible because `git checkout` reverts to HEAD).

**Process error recorded (AGENTS.md: report what I got wrong).** To undo that first bad needle I
ran `git checkout -- database/seeders/SystemWalletSeeder.php` — but the seeder improvement was still
**uncommitted**, so that command discarded my own verified work, not the needle. Caught immediately
when the restore hash did not match and the test went to 3 errors; rewrote the file from the
recorded content and re-verified. Two lessons: (a) **commit verified work before needle-experimenting
on it** — which is why this change is committed straight after its gate; (b) verify a needle's
*specificity*, not merely that "something failed". No other file was affected; the money floor
re-verified green (11 suites incl. MoneyPath 7/21, WalletTransactionService 27/29, PassengerConfirm
10/47, escrow 4/14, RV-40 3/9 + 4/13, RV-15 4/12, RV-21 boundary 2/5).
### 26.12 RV-40 (remaining bullet) — `rides.price_per_seat` widened to decimal(15,2) — **VERIFIED FIX**

**Grounded in the schema, not in memory.** Enumerating every DECIMAL column showed the money
standard is universal — `wallets.balance/cash_ride_debt`, `wallet_requests.amount`,
`wallet_transactions.amount/previous_balance/new_balance`, `rides.cash_creation_fee`, and
RV-40's own `bookings.unit_price/amount_paid/escrow_held` are all `decimal(15,2)`. The lone
money outlier was `rides.price_per_seat` at `decimal(8,2)` (max 999,999.99), because T3-2's
`MONEY_COLUMNS` list simply omitted it. R1 RV-40 prescribes exactly this repair verbatim
("rides.price_per_seat -> decimal(15,2)"), and RV-40 made the outlier actively harmful: the
per-seat price is snapshotted into `bookings.unit_price` (15,2), so the **destination can hold
four more digits than the source** and the ride column became the choke point where a large
price fails as an undiagnosable 500.

**Scope discipline.** Only the column WIDTH. The application-side maximum is deliberately
NOT added — choosing one config bound (CreateRideRequest caps at 100000, create-with-route at
nothing) remains an owner decision recorded with RV-14.

**Two errors caught before shipping, both recorded because they were not obvious:**
1. **`->change()` cannot be used on `rides`.** The first attempt copied the T3-2 idiom, but
   `->change()` introspects the whole table through Doctrine DBAL, which aborts with
   *"Unknown database type geometry requested"* on rides' two GEOMETRY columns. T3-2 only
   touched geometry-free tables, so its idiom did not transfer. Rewritten as the repo's other
   established idiom (raw `ALTER`, guarded like the sibling enum migrations). Verified the
   failed attempt left **no** `migrations` row, so it retried cleanly rather than half-applying.
2. **A wrong column definition.** The draft carried `->default(0)`; the real column is
   `NOT NULL` with **no** default, and inventing one would let a ride exist with a silently
   free price. Confirmed against `information_schema` before and after.

**Verification.**
- Applied to real MySQL 8.2: `decimal(15,2)`, `IS_NULLABLE = NO`, no default, recorded exactly
  once, **both GEOMETRY columns intact**.
- `MigrationEffectsBatchTest` extended from **5/33 to OK (7 tests, 61 assertions)**:
  `test_the_full_money_column_set_is_decimal_15_2` now enumerates every money column (11 pairs,
  with a count assertion so a renamed or newly-added money column fails loudly), and
  `test_widening_the_price_preserved_the_geometry_columns` pins the exact hazard the first
  attempt hit. The pre-existing T3-2 six-column test was **kept, not edited**.
- Ride/money floor unchanged: RideTest 13/2, RideControllerFull 39/8 (established pre-existing
  counts), RideResource 16/49, RideSearchService 17/22, PassengerConfirm 10/47, MoneyPath 7/21,
  escrow 4/14, RV-40 3/9, RV-15 4/12.

**Methodological finding — a DB-level needle cannot test a schema migration.** The first needle
tried narrowing the live column and asserting the test fails; it stayed green, because
`RefreshDatabase` runs `migrate:fresh`, **re-applying the migration under test** and restoring
the width. That "OK" would have been read as a passing needle while proving nothing. For a
migration, the needle must neuter the **migration** itself: disabling the widening made the test
fail with `Failed asserting that 8 is identical to 15` and only that test failed; the file was
restored MD5-identical. Recorded because the distinction generalises to every schema task here.

**State: VERIFIED FIX.** With this, RV-40's own bullet list is complete except the two items
gated on owner design (the `failed` terminal state with no writer, and the statuses-as-string
policy), and RV-14's price-bound half still needs its single config decision.
### 26.13 RV-40 (final bullet) — one no-show report per (booking, reporter) — **VERIFIED FIX**

**Grounded before changing anything, and the grounding changed the answer.** The first instinct
was that RV-40's `unique(booking_id, reporter_id)` bullet conflicts with the application, because
the duplicate guard in `Noshowservice` is deliberately *soft* — it blocks only
`status IN ('pending','disputed')` (L123, L246), and `void` (added by RV-02 L1) is not in that
list. That reads like a hole: a voided report would not stop a second report.

Two checks closed it before any code was written:

1. `applyPenalty` requires the booking still be `confirmed` (L531), and **every** settlement path
   then writes the booking to `no_show` (L547, L590). So once a report resolves or voids, the
   entry precondition at L92 ("booking must be `confirmed`") rejects any later report for that
   booking — the soft guard's gap is unreachable through the service.
2. Concurrency is already serialised: `Booking::lockForUpdate()` opens the reporting transaction.

So duplicates cannot occur today, and a blanket unique index removes **no reachable behaviour**.
What the database did not enforce was the invariant itself, and that matters because resolving a
report **releases escrow to the reporter** — a duplicate row is a double-release hazard for any
writer that bypasses the service (seeder, admin tool, batch job, future endpoint).

**Fix.** One UNIQUE key `uq_noshow_report_booking_reporter (booking_id, reporter_id)`.
`booking_id` is NOT NULL, so there is no NULL-multiple loophole. Data hazard handled explicitly:
the migration counts duplicates **first** and aborts with the offending count and a sample,
rather than silently deleting or merging real reports — a human decides that. Idempotent via an
index probe; `down()` drops only that index.

**Verification.**
- Applied on real MySQL 8.2: index present, columns exactly `(booking_id, reporter_id)`,
  `NON_UNIQUE = 0`; zero pre-existing duplicates found on the live scratch schema.
- `RV40NoshowReportUniquenessTest` OK (2 tests, 5 assertions) exercises the **denied** path, not
  just the index's existence: the first report inserts fine (control), the second raises a
  `QueryException` naming the index and is not stored, and a **different** reporter on the same
  booking still inserts — so the constraint is proven not to over-reach. Asserting only
  `information_schema` (as `MigrationEffectsBatchTest` does) would have pinned presence without
  proving the constraint binds.
- `MigrationEffectsBatchTest` 7/61 -> **OK (8 tests, 64 assertions)**; the existing T3-2 test was
  kept, not edited.
- Every no-show-touching suite unchanged: MoneyPath 7/21, NoshowSettlementGuard 3/11,
  Untangle 8/213, PassengerConfirm 10/47.

**Causality needle.** Neutering the migration's index creation (file lints clean, restored
MD5-identical) makes **exactly** the denied-path test fail
("a duplicate (booking_id, reporter_id) must be rejected") while the over-reach test still
passes — sensitive to the constraint, not over-broad. Note this is the migration-level needle
method from §26.12: a DB-level needle cannot work here, because `RefreshDatabase` runs
`migrate:fresh` and re-applies the migration under test.

**RV-40 status: decision-free scope COMPLETE.** The remaining two bullets stay refused
deliberately, not overlooked — `failed` terminal state has no writer until RV-02 L2, and
converting statuses away from DB ENUMs is owner decision 13.
### 26.14 Wave 3 — **PAUSED BY OWNER (2026-10-02). These items are UNFINISHED and IMPORTANT — do not treat Wave 3 as done**

> **SUPERSEDED IN PART — read section 66 first.** This section was written BEFORE the owner answered the
> decision slate, and it says so ("the owner has no answers yet"). That was true when written and stopped
> being true the same day: all 27 decisions were answered and dispatched, and 16 of them committed (see
> `STATE.md`'s decision-slate block). Of the six items below, item 1 (RV-11) has **partly landed on its own
> and partly not** - the tier-band half is finished, while the double-count, the start-score and the clamp
> are still live defects, re-verified in code in section 66. Items 2-6 have had their **gates** answered but
> have **not** been re-verified since, so do not read their percentages here as current.

The owner has no answers yet and asked to pause Wave 3 and work on unrelated items. Wave 3
resumes the moment any answer below arrives. **Nothing here was forgotten, and nothing here is
"won't fix": each item stopped because the next value would silently change who is penalised or
how money settles, which the standing rules forbid inventing.**

**UNFINISHED — IMPORTANT, awaiting owner input (each one is live defect exposure until done):**

1. **RV-11 score consolidation — ~35% done. IMPORTANT: trust scores feed cancel-rate penalties.**
   - Canonical tier bands: code carries TWO contradictory schemes (`>=80/60/40` Gold/Silver/Bronze
     in `UserScore::getTierAttribute` vs `>=200/150/100` platinum/gold/silver in
     `ScoreService::resolveTier`). The DB stores the *second*; dashboards read the *first*.
   - Clamp: `applyDelta` clamps [0,100]; `applyAction` clamps only at 0 (uncapped top).
   - Start score: 70 (`initializeScore`) vs 100 (`firstOrCreate` paths).
   - **Double-count (live bug):** `recordRideCompleted` calls `applyAction` (already does
     `total_rides+1` on positive) AND `incrementRides()` — every completed ride counts twice,
     which inflates the `cancel_rate` denominator that `PassengerCancelPolicy` /
     `DriverCancelRidePolicy` gate penalties on. Owner must say whether a ride counts once.
   - No `config/score.php` exists; all four values have no single source of truth.
   - Already done, safely: dead third path `applyScore` deleted (97 lines, zero callers).

2. **RV-10 ride lifecycle — ~70% done (search guard shipped). IMPORTANT: escrow sits in SyCash
   until someone confirms; there is no automatic path and departed rides are only hidden in search.**
   - Needs: auto-confirm hours for `rides:advance-status` (escrow release timing = real money
   movement, cannot be guessed), the driver-cancel window (the commented-out validator encodes an
   arbitrary 1 h), and whether booking must reject past-departure rides (the ~335 settlement
   fixtures deliberately book past-departure rides — coupled to RV-02 L2).

3. **RV-02 L2 settlement redesign — 0% started. IMPORTANT: this is the one that unlocks the other
   two halves below.** Needs: confirm 95/5 platform-fee split stays, and confirm SyCash becomes a
   DERIVED balance (`escrow_held` per booking — the RV-40 column exists and is populated — instead
   of one mutable aggregate balance).

4. **RV-20 payment-strategy wiring — ~20% (charge/refund halves done in earlier waves). Coupled to
   RV-02 L2: `releaseEscrowToDriver` in the strategy family rewrites settlement math; half-wiring
   it against the aggregate balance would double-release.**

5. **RV-09 full — ~85%. Done: deadlock ordering + after_commit queues. Remaining halves are coupled
   to RV-02 L2: transactions `attempts=3` (retries today swallow exceptions — safe only once the
   escrow is derived), strategies must not swallow `Exception`, and events should carry ids not
   models.**

6. **RV-21 full — ~90%. Done: runtime boundary + seeder fail-loud. Remaining: `wallets.kind` enum
   (user/escrow/platform/treasury), double-entry top-ups (Σ balances = 0 invariant), maker-checker
   + daily limits on admin wallet adjustments. Schema + thresholds = owner.**

**Resumable in one round each** once answered; the exact questions are listed in the goal's
blocked_reason and repeated above. Until then: RV-40 and RV-15 are COMPLETE; the rest of Wave 3 is
paused, unfinished, and important.
### 26.15 RV-22 [P1] TLS + log hygiene — started, unrelated to the paused Wave 3 (owner asked to move on and record the unfinished items — §26.14 does that)

**RV-22 verified live in current code before touching anything** (all three claims):
1. `GoogleController::callback` logged `$request->all()` **and** the OAuth `code` and `state`
   individually (L39–41), plus `incoming_state` in the InvalidState catch (L176). A live
   authorization code in a log = replayable login, and R2 notes the log file is non-rotating
   and shared by 5 replicas. — **FIXED + NEEDLED (this §).**
2. `ArabicPlaceNameService` calls `->withoutVerifying()` ×3 (L63/L110/L160) against
   `nominatim.openstreetmap.org`. — next slice.
3. The existing `TlsAndOctaneHygieneTest` regexes catch `'verify' => false` (arrow) and
   `CURLOPT_SSL_VERIFYPEER => false` but **neither** the `withoutVerifying()` method form
   **nor** `$options['verify'] = false` (assignment) — which is why both leaks sat under a
   "green" TLS test. — the ratchet extension lands with slice 2.

**Slice 1 (VERIFIED FIX).** Redaction only: removed the 3 debug `Log::info` lines; the
InvalidState catch now logs `exception` class + `state_present` (bool) instead of the raw
state value (on mismatch the raw value is either attacker-controlled or the victim's genuine
CSRF token if the session was lost — presence still answers "did Google send a state?").
No control flow changed; issued JWTs were never logged; failure diagnostics untouched.
`RV22OauthCredentialRedactionTest` OK (2 tests, 12 assertions): asserts against the REAL log
file via a dedicated single channel (a Log spy would miss any other path logging the request),
with a sentinel line written-and-asserted FIRST so an empty sink can never pass vacuously —
the failure class caught twice this session, prevented by construction. Success path (200 +
token + user created) and InvalidState path (401 + warning fired) each assert `code`/`state`
absent. **Causality needle:** re-inserting the three lines made BOTH tests fail; restored
MD5-identical (DA07ED00…). Google/TLS suites unchanged: 5/12, 10/40, 3/4.
### 26.16 RV-22 (2/3) — TLS verification re-enabled + the ratchet's two blind spots closed — **VERIFIED FIX**

**Pre-flight before removing anything (the honest order).** Slice 2 needs disabled-TLS flags
gone, but ripping them out only helps if verified HTTPS actually works from this box — the
whole reason the flags existed was a WAMP CA gap. Ran a read-only curl pre-flight with
`VERIFYPEER=true, VERIFYHOST=2` against the exact hosts the code calls:
`nominatim.openstreetmap.org` → **VERIFIED-OK (HTTP 403)** and `accounts.google.com` →
**VERIFIED-OK (HTTP 200)** and mapbox → 401 (a 4xx still means the *TLS handshake + cert
verification succeeded*; the code only logs/reads on a 2xx, so 4xx is the right failure mode).
PHP's CA store is configured (`curl.cainfo`/`openssl.cafile` → `C:\wamp64\ssl\cacert.pem`).
So verification is genuinely available here and the flags are pure liability.

**Root cause (verified in current code).** `ArabicPlaceNameService` had `->withoutVerifying()` on
**all three** Nominatim calls (reverse/autocomplete/search) with **no env gate** — production
Nominatim traffic ran with peer verification off. Nominatim output becomes ride addresses and
the OpenRoute distance feeds **fare** (AF-1), so an on-path MITM could forge coordinates and
steer pricing. Separately, `GoogleController::callback` disabled TLS with
`$options['verify'] = false`, gated on `config('app.env')`.

**Why the env gate is not a fix.** RV-16 already established that `app.env` detection on this
deployment is unreliable (it is `production` while the code wants testing). An env-gated
`verify=false` means a single detection mistake silently disables OAuth TLS in prod — the exact
class of "silent wrong branch" this audit keeps hitting. Verification is simply always-on now.

**The ratchet was blind to both forms.** `TlsAndOctaneHygieneTest::test_no_outbound_http_call_
disables_tls_verification` only matched the arrow form `'verify' => false` and
`CURLOPT_SSL_VERIFYPEER`. Laravel's HTTP facade disables TLS via the **`withoutVerifying()`
method**, and the controller used the **assignment** form `$options['verify'] = false` — so
**five** live TLS-disable sites sat under a test that reported green. Extended the pattern list
with both, comment-aware like the rest of the file (`codeOnlySources()` strips comments through
the tokenizer, so the fix's own explanatory comment can't trip it — the pre-existing
`WhatsAppOtpService` / `RouteCalculationService` comments are proof it already behaves this way).

**Fix.** Removed `->withoutVerifying()` ×3 (Nominatim verification ON); removed the controller's
env-gated `verify=false` (`new Client([])`); corrected the test docblock that claimed TLS is
"disabled in local/testing" (tests mock Socialite — no real TLS happens there).

**Verification.**
- Touched suites green: `TlsAndOctaneHygieneTest` 3/4, `ArabicPlaceNameServiceTest` 18/34,
  `AppServiceProviderTest` 18/19, `GoogleControllerTest` 5/12, `GoogleOauthTokenTest` 10/40,
  `RV22OauthCredentialRedactionTest` 2/12 (slice 1 still holds).
- **Causality needle:** re-inserted *both* escaped forms into real code (a `withoutVerifying()`
  call + a `verify[]=false` assignment); the extended ratchet failed naming **both** files with
  both new labels (`withoutVerifying()`, `verify[]=false`). Files restored **MD5-identical**
  (A `DB9ACC9D…`, G `C729CED9…`) and the ratchet returned green. (The needle script's own
  `leak-check` string-matched the fix's *comment text* and printed a false "STILL PRESENT" —
  the hash match and the green re-run are the real evidence, not that line. A needle's prose can
  lie; a hash cannot.)
- Full-suite result recorded below.

**RV-22 remaining (3/3).** `LOG_LEVEL` default `debug` with `Log::info` on hot paths + the
non-rotating file shared by 5 replicas: a deploy-surface change (Docker/log config, not app
code), recorded for the owner rather than edited blind.
## 27. Wave 5 started (owner instruction): RV-12 — expired temporary ban locked the user out of logging in — **VERIFIED FIX (decision-free core); R1's model refactor stays PARTIAL**

**R1 staleness check first.** RV-12 claims "the middleware auto-lifts expired bans" and
"unban busts caches" — both were TRUE (middleware L66-85 handles expiry + `bustBanCaches`
exists), so half of R1's list was already fixed by earlier work. Grounding found TWO
genuinely live bugs, both decision-free:

1. **The expiry deadlock.** Login/Google reject `status === -1` **unconditionally**, but the
   middleware's auto-lift only runs on an AUTHENTICATED request — and a banned user's tokens
   were revoked at ban time. So an **expired temporary ban locked the user out forever**: the
   only door (login) ignored exactly the field (`ban_expires_at`) that the other door (the
   middleware) honoured.
2. **Unban left a stale cached copy.** `ban()` busts `auth.user.{id}` via `revokeAllTokens`,
   but `bustBanCaches()` (used by unban) forgot it — a user the admin just unbanned kept
   hitting a 5-minute cached `status=-1` and received USER_BANNED *after* being un-banned.

**Fix.** One shared domain rule on the model: `User::isBannedNow()` (status -1 AND not an
expired temporary ban) + `banHasExpired()` — replacing the disagreeing copies so the rule
cannot drift again (R1's "one source of truth" principle, applied without the gated refactor).
`LoginController` and `GoogleController` now gate with `isBannedNow()` and, when lifting an
expired ban at the door, clear the dead `ban_*` fields (all fillable — verified, the RV-38
silent-noop class). `bustBanCaches()` forgets `auth.user.{id}` too. The middleware keeps its
existing auto-lift; permanent / not-yet-expired / temp-with-null-expiry bans still refuse
(fail-closed — null expiry never self-lifts, pinned).

**Out of scope on purpose (recorded, not skipped silently):** dropping the persisted
"logged-out" status=0, migrating all readers to a `BanService`, `createUser`'s
`status => 1` override (currently harmless: unverified-email check precedes the ban gate, so
no exploit — it is hygiene for the model refactor), and the admin-readers' drift. Those are
R1's larger model change, entangled with owner decisions.

**Verification — `RV12ExpiredBanLoginTest` OK (8 tests, 21 assertions):** expired temp ban →
login 200 + status 1 + ban fields cleared; permanent / active-temp / temp-null-expiry → 403
(door did not swing open); unban evicts `auth.user.{id}`; Google path lifts an expired ban and
clears fields too; end-to-end ban→expire→login→re-ban→refused. **Causality needle:** reverting
`isBannedNow()` to the old unconditional rule fails EXACTLY the 3 expiry-dependent tests,
refuse-tests stay green both ways; file restored MD5-identical. Two wrong assumptions caught
by verification before they became fake-green: my guessed `/api/login` route (real:
`/api/auth/login` — 404 caught it) and `$admin->assignRole()` (house pattern: Employee +
`adminToken()` via `/api/admin/login`, `auth.admin` middleware).
**Floors green:** Feature\Auth 74/191 (whole dir), AdminBanControllerTest 29/58,
RV-22 pins 2/12. Unit\Middleware 60 tests / 2 failures — both confirmed pre-existing
baseline items #3/#4 (`test_using_refresh_token_as_access_token…` ×2), not regressions.
Full-suite gate recorded in the next commit message.
## 28. Wave 5 continued — RV-26 gated; RV-27/RV-19/RV-23 slices — **grounded, statuses below**

### 28.1 RV-26 authorization matrix — **GATED (headline refuted by its own fix text), no code change**

Grounding refused to accept R1's framing. Every `/api/admin/*` gate was changed in T2-2
(already committed): the financial surface is now `staff:system_admin`, and
`AdminFinancialSurfaceAuthorizationTest`'s own docblock states **"sycash is intentionally NOT
granted here"** — so R1's "sycash denied everywhere" is a documented design, and R1's own Fix
line says: **"owner decision: sycash approves wallet requests, system_admin doesn't"**. The
role×endpoint matrix, the login unification, and deleting `AdminAuthService`/`AdminJwtMiddleware`
are exactly the authz choices this audit reserves to the owner — changing them either way grants
or revs privileges without consent. One decision-free sub-item recorded for a later pass:
`AdminJwtMiddleware` (`auth.admin`) is registered in Kernel but has **zero route references**
(the `auth.admin` mentions elsewhere are stale docblocks) — dead code whose deletion is safe but
belongs with RV-31's dead-code sweep, not mixed into a security surface while owners decide it.
**Status: PARTIAL-GATED (no change).**

### 28.2 RV-27 push pipeline — **grounded; two live code defects found, decision-free slices queued**

Confirmed against current code: (a) `routes/api.php` registers **zero** push routes — the
controller's `registerToken`/`removeToken`/`getUserTokens` are unreachable, so an app can never
deliver FCM (R1 was right); (b) type mismatch — routed `registerToken()` passes `$request->user()`
(a User) into `PushNotificationService::registerToken(int $userId, …)` — TypeError → 500 even if a
route existed — while the unrouted duplicate `store()` passes `->id`; (c) `FcmSenderService` only
warns-and-skips when credentials are absent (silent disable); (d) `removeToken($request->token)`
deletes **globally** — any authenticated user who knows another device's token string can
unregister it (missing ownership scope — an authorization hole).
Queued slices (all decision-free): route them under the existing `['jwt','throttle:api']` group,
fix the userId-type mismatch, scope removal to `$request->user()->pushTokens()`, and pin with
feature tests. Deploy-side parts (compose secret mount, pruning retention) are recorded, not
guessable. **Status: GROUNDING DONE, fixes next round.**

### 28.3 RV-23 complaint context — slice 1 **VERIFIED FIX**; rest of the finding split

Live silent data loss confirmed: `Noshowservice::handleConflict` (L461-469) writes
`Complaint::create` with `ride_id` + `complained_id`, but **neither column existed and neither
key was fillable** — Eloquent dropped both without a word. The auto-complaint opened when BOTH
parties press no-show — the case support needs context for most — arrived with no ride link and
no respondent. (Same silent-drop class RV-38 exists to surface; `createUser`-style claims of R1
that were stale got re-verified instead of trusted.)

**Fix (additive, nullable, nullOnDelete):** migration `2026_10_03_000001` adds `ride_id` →
`rides`, `complained_id` → `users`; `$fillable` gains both; `ride()`/`complainedUser()` relations
added. Manual complaints (no ride) are unaffected — pinned explicitly. `type`/`status` verified
already varchar(50) in the real DB, so `no_show` inserts cleanly (R1's enum worry was stale).

**Verification:** `RV23ComplaintContextTest` OK (2 tests, 8 assertions) replays the exact payload
from the real write site (relations must resolve, not just ids); applied on MySQL (columns + both
FKs verified in information_schema). **Two-part causality needle:** dropping the fillable keys
fails exactly the persistence assertion ("ride_id was silently dropped before the fix"); neutering
the migration fails it with `Unknown column 'complained_id'` — proving both halves are load-bearing.
Files restored MD5-identical. Floors: ComplaintRepositoryTest 13/15; ComplaintControllerTest 21/1
failure confirmed pre-existing **baseline item #38** (`test_store_accepts_all_valid_complaint_types`
→ no_show 422). Remaining RV-23 items: least-loaded agent assignment (pure `->first()` head-of-queue
starvation — behaviour change, queued as its own decision-free slice with tests), the racy GET-mutates
state (`show` auto-transition, baseline-red StaffComplaintControllerTest family), notifications dedup.

### 28.4 RV-19 admin numbers — **grounding started; one sub-item likely owner-gated**

`AdminReportService::getStats()` — pending_complaints hardcoded 0 is a live wrong number
(decision-free to compute properly); "revenue from config('system_admin.phone')" depends on the
RV-21 kind-refactor design (gated). N+1 claims belong partly to RV-24. Recorded to continue with
the complaint count first.
### 28.5 RV-27 push pipeline — **VERIFIED FIX (decision-free core)**; deploy-surface halves recorded

**Three live defects, all decision-free (grounded first, R1's claims re-verified — two were
stale):**
1. **The feature was unreachable.** `PushNotificationController` had **zero** routes. No device
   could ever register a token, so end-to-end FCM delivery was impossible. (R1: "No route
   registers/removes FCM tokens" — TRUE.)
2. **Type-error at the core.** The routed `registerToken()` passed `$request->user()` (a `User`
   model) into `PushNotificationService::registerToken(int $userId, …)`, plus a 4th array arg the
   service never had. Once routed this 500s (TypeError). Fixed: pass `->id`; dropped the
   never-consumed device_id/device_name args. (R1's "passes a `User` where `Push…`" — TRUE.)
3. **IDOR — any user could unregister any device.** The delete path called the *global*
   `removeToken($token)`: anyone who knew/guessed another device's token string deactivated it,
   silently killing that user's notifications. Now routed through a new `removeTokenForUser(userId,
   token)` scoped by `user_id` — a foreign token answers identically to a missing one, so the
   endpoint also stopped being an existence probe for valid token strings.

**Fixes.** Added `removeTokenForUser` to the manager + service (the unscoped `removeToken` stays
for internal cleanup, now correctly returning `> 0`). Added a `push-tokens` route group under the
authenticated `['jwt','throttle:api']` group: POST register, GET list (already scoped via
`$request->user()->pushTokens()->active()`), DELETE remove — **token strings only ever travel in
the body, never a URL path** (a path token lands in web-server/proxy access logs). Deleted the
drifted unrouted `store()` duplicate (R1's own "delete the duplicates"); `testNotification` stays
unrouted but is now listed in `RoutesIntegrityTest::UNROUTED_BY_DESIGN` **with a reason** (routing
any method makes the ratchet scan the whole class; the entry documents the deliberate choice rather
than expanding permanent surface).

**Verification — `RV27PushTokenFlowTest` OK (9 tests, 33 assertions):** register persists
caller-owned row (android), re-register upserts without duplicating and reassigns owner, list is
caller-scoped (sees own, not other's), self-delete soft-deactivates, **the IDOR case** (Bob knows
Alice's token → `success:false`, Alice's token stays active), unknown token is not an error, all
three endpoints require auth (401 unauthenticated), platform validation (bad platform + missing
token → 422, nothing persisted), and the dead `store()` stays deleted. **Three causality needles**
(each restored MD5-identical): undoing `->id` → 5 failures; undoing ownership scope → exactly the
IDOR test fails; deleting the route group → 8 failures. Each needle isolates one defect; none
over-broad. Floors unchanged: RoutesIntegrity 3/3, RateLimiting 23/151, Auth 74/191, full-suite gate
in the commit message.

**Recorded, NOT done (deploy surface — cannot be edited blind):** FcmSenderService only
warn-and-skips when credentials are missing (silent disable: a misconfigured prod sends nothing and
logs only a warning — a config/deploy concern); the compose `fcm_credentials.json` secret mount;
notification history pruning (`notifications`/`user_notifications` never pruned — `tokens:cleanup`
*is* scheduled, but that covers refresh tokens, not push/notification rows). Retention windows are
product choices.
### 28.6 RV-29 (slice 1) — refresh-token REUSE DETECTION (user + staff) — **VERIFIED FIX**; remainder recorded

**Defect (grounded in both services before editing).** Both `JwtService::refreshAccessToken`
and `StaffJwtService::refreshAccessToken` rotated correctly (revoke-old-then-mint-new) but the
user lookup filtered `revoked=false`, and neither side distinguished *why* a token was rejected.
Consequence: a STOLEN refresh token replayed by the thief succeeds first and marks the legit
holder's copy revoked; the holder's next refresh then fails with the same silent null — theft is
never signalled, and the thief's already-minted ACCESS tokens live out their TTL. R1 RV-29
names exactly this ("Refresh rotation has no reuse detection → token family; revoke all on
reuse").

**Fix.** Lookup no longer pre-filters revoked; a **revoked-but-unexpired** row presented again
means two live copies of one secret = theft. Then: `Log::warning` naming the account + the
family revoke via the EXISTING `revokeAllTokens()` (refresh rows + `token_version` bump so live
access tokens die too). Deliberate boundaries: replay of an EXPIRED row is not treated as theft
(nothing to protect — a healthy sibling session must survive; pinned); the all-already-revoked
case (post logout-all) keeps its old silent-invalid answer; normal single rotation is unchanged.
Action-first design: the check adds ONLY revocation — it removes no capability, weakens nothing.

**Verification — `RV29RefreshReuseDetectionTest` OK (6 tests, 14 assertions):** controls (normal
rotation twice via the rotated token still works; expired ghost replay does NOT nuke a healthy
sibling), and the security core on BOTH audiences (replay -> zero active rows remain -> the
sibling token itself now fails -> `token_version` bumped on both `users` and `employees`).
**Causality needle** disabled only the revoke ACTION (`if (false && $othersActive)`) in both
services: EXACTLY the 4 reuse-value tests fail, both controls stay green — the tests measure
revocation, not response shape. Files restored MD5-identical. Existing refresh behaviour is
preserved: `StaffRefreshTokenHashingTest` 12/25 still green (its "consumed token cannot be
replayed" assertion only requires null, which reuse also returns), AuthTest 14/37,
StaffAuthControllerTest 17/30, Feature\Auth whole dir 74/191. Full suite **1995 tests,
52 errors / 68 failures unchanged, 0 regressions**.

**RV-29 remainder (recorded, next slices, all individually grounded first):**
- `Cache::remember('auth.user.{id}', User)` serialises the FULL model. R1 claims the password
  hash rides in the cache store — MECHANISM UNVERIFIED so far (`$hidden` applies to JSON
  serialization; whether the cache path bypasses it depends on `Model::__serialize`); ground it
  in vendor before fixing.
- `User::$fillable` carries privileged keys (`status`, `token_version`, `is_verified_*`,
  `wallet_id`, `national_id`) — T4-5 was rolled back before; mass-assignment surface.
- Google linking to an UNVERIFIED local account without invalidating its password.
- `RefreshTokenController` per-IP-only rate limit (RV-05 buckets exist for this).
- Chat `startConversation` anyone-messages-anyone (product feature decision — gated).
- `RideResource`/`BookingResource` leak `communication_number` to every authenticated user.
- Staff login early-return timing (unknown identifier vs wrong password).
### 28.7 RV-19 (slice 1) — admin dashboard fake numbers — **VERIFIED FIX**; revenue definition + second V8 site remain gated

**Two fake numbers in `AdminReportService::getStats()`, both confirmed live (R1 RV-19 named
them, grounding re-verified):**
1. `'pending_complaints' => 0` — a hardcoded literal. The support-backlog card could NEVER
   rise regardless of queue size; admins were structurally blind to open complaints.
2. Revenue read `config('system_admin.phone')` — a **phantom key**: V8 recorded that
   `config/system_admin.php` does not exist, so the lookup was always null, the wallet query
   matched nothing, and the revenue card showed `0.00` FOREVER even with escrow in the wallet.

**Fixes, convention-driven — no invented semantics:**
- Pending complaints: `Complaint::where('status', ComplaintStatus::PENDING)->count()`. The
  convention was READ from the codebase, not chosen: the sibling KPI in the same array
  (`verification_requests`) counts exactly `'pending'`, and `StaffAdminController` tracks
  `in_review`/`escalated`/`resolved`/`closed` in their own status-count surfaces — so
  "pending" here means PENDING only. Pinned explicitly (`counts_only_pending_status`: one
  pending + one in_review + one closed + one resolved => 1).
- Revenue: the canonical key every money path already uses (`admin.system_admin.phone`,
  seeded by `SystemWalletSeeder`, read by `WalletTransactionService`/`CashRideFeeService`),
  plus the RV-21 boundary `whereNull('user_id')` so a user-owned wallet squatting the phone
  can never be read as platform revenue. **Value DEFINITION unchanged** — still "the Primary
  Escrow balance"; what that balance MEANS is Wave-3's gated escrow redesign (§26.14). The
  fix only made the intended lookup resolve.

**Recorder flipped honestly, not weakened.** `WaveZeroVerificationTest::test_v8…` originally
asserted the BROKEN state (`assertContains phantom key`). After the fix a naive
assertNotContains is fooled by the explanatory comment naming the old call — it proved that
during development. The rewritten recorder comment-strips via the tokenizer (the exact house
pattern in `TlsAndOctaneHygieneTest`), pins BOTH halves (phantom key gone AND canonical key
present), and still asserts `config('system_admin.phone')` is null — V8 itself remains a
recorded fact. Its name changed to describe what it now verifies.

**The SECOND V8 site deliberately NOT fixed:** `VerificationRepository` reads
`config('system_admin.email')` → null, so its "seed a 3.0 rating for newly approved drivers"
block NEVER FIRES. Fixing the key there would silently ACTIVATE a dormant write (every driver
approval starts inserting ratings, changing seeded/real rating math) — a behavior change no
owner asked for, recorded as gated in §28.7/§29.1 rather than blind-swapped.

**Verification — `RV19AdminStatsTest` OK (4 tests, 7 assertions):** 0 with empty queue, 2 with
two pending; only-PENDING convention pinned; funded primary wallet (10,000,000 via the
production `SeedsSystemWallets` concern) reported as raw + formatted revenue; and the RV-21
boundary (user-owned wallet on the phone ⇒ revenue 0, never adopted).
**Causality needles** (both restored MD5-identical): reverting the count to `0` fails EXACTLY
the 2 counting tests; the clean phantom-key swap (first attempt was an invalid needle — the
inline comment created a parse error, caught by lint-before-trust; redone syntax-preserving)
fails EXACTLY the revenue-behavior test + the flipped V8 recorder.
**Floors:** WaveZero 9/25 (recorder rewritten, count grew 24→25), AdminFinancialReportEscrow
4/14, Admin dir 114/9 = the established AdminDashboardControllerTest pre-existing 9F, nothing
new. Full suite + gate recorded in the commit.
### 28.8 RV-23 (slice 2) — complaints go to the least-loaded agent, not to one — **VERIFIED FIX**

**Defect (verified in code).** `ComplaintService::submit` chose an agent with
`Employee::where('role','support_agent')->where('is_active',true)->first()` — no ordering at
all. `first()` returns the same first-matched row every request, so with one busy agent and
ten idle ones EVERY complaint landed on the busy one: its backlog grows unbounded (an
SLA/availability failure, not merely unfairness) while the other agents never see work.

**Fix is convention-mirroring, not invented:** R1 prescribed "least-loaded assignment (as
`ContactController`)", and that pattern exists and is proven in-repo — `ContactController`
picks agents with a load-count `orderByRaw` subquery, ASC, first(). Complaints relate to
employees DIRECTLY (`complaints.assigned_to → employees.id`), so the subquery needs none of
ContactController's email-join workaround. "Load" = complaints still needing handling:
`status NOT IN ('resolved','closed')` — terminal states don't count; pending/in_review/
escalated do. Eligibility is UNCHANGED (same role, same active filter, same null-tolerant
result) — the change strictly redistributes, grants no access, weakens no validation. A
deterministic `orderBy('employees.id')` tie-break was added: the original had no ordering at
all, so a fairness assertion would otherwise be inherently flaky. Driver-portable standard
SQL (works on MySQL and SQLite alike).

**Verification — `RV23ComplaintAssignmentTest` OK (5 tests, 9 assertions):** a busy agent
(5 open cases, lower id — the row the old query always returned) is skipped for an idle one;
4 successive submissions reach BOTH idle agents (uniq assigned count = 2 — proof it isn't
pinned to one row); resolved/closed do not inflate load and a true tie breaks to lower id
deterministically; a DEACTIVATED agent is never chosen even when idlest of all (eligibility
preserved); with zero agents the complaint still creates unassigned (the null branch kept).
**Causality needle:** reverting the builder to the literal original `first()` query (exact
text, lints clean) fails EXACTLY the 2 redistribution tests — busy-skipped + balancing — while
tie-break/inactive/null stay green (correctly: those properties exist in the old query too).
File restored MD5-identical. (First needle attempt was itself buggy — it reconstructed
`Employee::where` with no arguments and would have produced a bogus result; caught by
lint-before-run before trusting anything.)

**Self-caught during authoring:** one assertion compared the cast enum object to its string
(`$complaint->status` vs `->value`) — caught by running, fixed to compare the enum.

**Floors attributed, not assumed:** Feature\Complaints 34/1 = baseline item #38
(`test_store_accepts_all_valid_complaint_types`, the no_show 422 — an RV-23-slice-3/recorder
matter); StaffComplaintServiceTest 14E/1F = baseline #30–43 verbatim (list_all/list_escalated
arity drift, RV-35 inventory); StaffComplaintControllerTest 38/125 green; RV23 slice-1 still
2/8. Full-suite gate in the commit message.

**RV-23 remaining after this slice:** (a) `GET /staff/complaints/{id}` mutates state (the
auto-transition, and it's the same file family as the baseline-red StaffComplaintController
tests — behavior change, record first, decide with the owner); (b) the double-notification on
conflict (L473-490: both parties get the same text — R1 "one notification"; wording/product
choice); (c) no_show type 422 at the public store endpoint (validation surface; likely
intentional gate — "public users shouldn't file internal auto-types"; needs owner, recorded).
### 28.9 RV-29 slice 2 — cached-User secret **PROVEN** (fix gated, data-loss hazard found); all remaining RV-29 items classified

**§28.6's UNVERIFIED flag is settled.** R1: "`Cache::remember('auth.user.{id}', User)`
serialises the full model — password hash rides in the cache store." Exploratory test against
the REAL path (`findUserCached`): a factory user's bcrypt `$2y$…` hash IS present in the
model's serialized form, while `toJson()` does NOT expose it (`$hidden` governs JSON only).
Framework-level mechanism confirmed: Eloquent `Model` implements `__sleep()` returning all
`get_object_vars($this)` (attributes included, unfiltered by `$hidden`) and defines NO
`__serialize()` (grep across the framework: only Queue/Mail traits add those) — so PHP
serialization, which file/database/redis cache stores write, persists the hash. **Finding
REAL.** The test was exploratory and left no permanent green pin of a live vuln (a passing
"expected vulnerability" test misleads); the proof is re-runnable per this record, and the
assertion test lands WITH the fix.

**Why the fix is GATED (not skipped, not blind-applied).** While designing it I found a
data-loss hazard: `JwtAuthMiddleware`'s RV-12-neighbor auto-lift path calls
`$user->update([...])` on THIS cached copy (L73). Removing `password` from the shared cached
model means every `$request->user()` (the middleware does `Auth::setUser($user)`) lacks its
credential for the whole request lifecycle — any `Hash::check($x, $user->password)` consumer
(re-auth / confirm-password) compares against null, and one `update()` on a cache-stripped
model with attribute-setting semantics could null the column in the DB. LoginController was
verified to use a FRESH `findByEmail` model, but the full `$request->user()->password`
consumer set cannot be bounded from here without auditing every call site. The safe fix is a
cache **DTO** (store a narrow shape; middleware rehydrates what it needs) — an architecture
change touching auth core. Owner decision. R1 rates RV-29 P2/P3 hardening.

**Remaining RV-29 items classified (each grounded this round):**
1. `communication_number` in `RideResource`(L83)/`BookingResource`(L28,57): live responses to
   the out-of-repo Flutter client already assert this key (RV-15 test reads
   `data.communication_number`); it is also a REQUIRED booking input that round-trips.
   Gating/removing = subtracting a live contract key (the audit's own rule for the wired
   search surface is "strictly additive") + a product question (should the driver's contact
   be visible before booking?). **Owner decision.**
2. `User::$fillable` privileged keys (`status`, `token_version`, `is_verified_*`, …): this
   exact narrowing is **T4-5, previously ROLLED BACK** (audit L171). Re-attempting without
   owner ignores recorded history. **Owner decision.**
3. Staff-login timing oracle (`EmployeeAuthService::authenticate`): unknown-id returns BEFORE
   any hash work, bad-password after — ~100ms measurable difference, and distinct
   `Log::warning` lines. HTTP response is already uniform 401/null; no response leak. Fix =
   dummy-hash constant-time path in auth internals — low-priority hardening, recorded.
4. Chat anyone-to-anyone + Google-link-to-unverified-account + refresh per-IP bucket: product
   / RV-05-adjacent, recorded.

**Status: RV-29 decision-free scope EXHAUSTED at slice 1 (bc6acaa).**
## 29. Beyond Wave 5 — RV-16 (plaintext OTP storage) — **VERIFIED FIX**; four §22.4 remainders are owner/product calls

**§22.4 re-grounded against CURRENT code — one of its lines was stale.** It said the read path
was "`OtpRepository` queries `where('otp_code', $code)`". That is no longer the verify path: the
services already use `findLatestByPhone()` + the constant-time `Otp::matchesCode()` (added by an
earlier OTP fix), so the code was already not matched in SQL. What §22.4 correctly still named is
the **storage** half: `otps.otp_code` was `varchar(6)` holding the **raw 6-digit code**. So the
real remaining defect was exactly one thing — plaintext at rest — and it is high-severity because
6 digits = 10^6 combos and the MAX_ATTEMPTS throttle only counts API guesses, never a DB read: a
dump/backup/log leak yields every live code outright.

**Fix — keyed HMAC at the single write choke point (the model mutator):**
- `Otp::setOtpCodeAttribute()` stores `hash_hmac('sha256', code, app.key)`; every service and
  test creates with the plaintext and is hashed transparently (22 create sites, zero signature
  changes). Hydration uses `setRawAttributes` and does NOT pass through the mutator, so a stored
  digest is never re-hashed — pinned.
- `matchesCode()` compares the stored digest against `hashCode($code)` in constant time (same
  key); the raw code is never read back out.
- `findByPhoneAndCode()` (interface method, now caller-less) made digest-aware so it cannot be a
  plaintext oracle if ever wired again.
- `2026_10_04_000001_widen_otp_code_for_hmac_storage` — raw MySQL `ALTER … MODIFY otp_code
  VARCHAR(64)` (the proven T3-6 idiom from the sibling phone-widening migration), MySQL-guarded +
  idempotent, `down()` restores varchar(6). NOT `->change()` — this audit proved DBAL chokes on
  exotic columns and `otps.type` is an ENUM on this table. varchar(6) would truncate the digest
  and break every verify, so the widening is load-bearing, not cosmetic.

**HMAC, deliberately not bcrypt** (documented in code): codes are minutes-old, single-use, and
already rate-limited — the threat model is a DB-only leak, and against an attacker holding
APP_KEY a 10^6 space is brute-forceable either way, so bcrypt's cost buys nothing here while
slowing every verify. Choosing NOT to rehash existing rows: they expire within minutes, so the
switch orphans no live code; a stale row's lookup simply fails the normal invalid path.

**Verification — `RV16OtpPlaintextStorageTest` OK (5 tests, 13 assertions)**, all reading the RAW
column via `DB::table` (bypassing the model, so a double-hydration bug can't hide): stored column
is not the plaintext and contains no substring of it; value is a 64-char `[0-9a-f]` digest EQUAL
to `Otp::hashCode($code)` (deterministic + keyed); verification still works through a fresh DB
load (correct code matches, wrong/prefix/empty reject) and re-reading leaves the stored digest
UNCHANGED (no re-hash on hydrate); same code + same key ⇒ same digest (lookup stays possible);
column width ≥ 64 on MySQL. **Causality needle:** reverting ONLY the mutator to plaintext flipped
exactly the 3 storage/verify-coupling tests while the width/determinism pins stayed green — the
write mutator and the hash-compare are correctly interlocked. File restored MD5-identical.
Existing OTP/auth suites unchanged: OtpTest 11/21, OtpAttemptLimit 13/55 (its `matchesCode`
unit pins passed untouched), CleanupExpiredOtps 7/15, OtpDisclosure 8/15, EmailVerification
17/36, ResetPassword 16/23, WalletTest 10/3F (its established baseline count). ONE test changed
and why: `TextMeOtpControllerTest` L122 asserted `(bool) Otp::where('otp_code','445566')
->first()->is_verified` — a plaintext DB lookup that can no longer match by construction;
rewritten to `$otp->fresh()->is_verified` (same intent, stable non-secret key, no vacuous null).

**§22.4 remainders, still open, all decision-gated (not overlooked):** phone-OTP endpoint
deletion (owner: "unless the client uses them"), synchronous mail inside SignupController's
transaction (needs a failure-path decision), account enumeration (product: uniform 202s change
client behaviour), `sleep(5)` in the TextMeBot worker (belongs to the endpoint decision). Full
-suite gate in the commit.
### 29.2 RV-38 — Eloquent data-integrity strictness enabled outside production — **VERIFIED FIX (bounded core)**; lazy-loading deliberately deferred to RV-24

**Measured, not theorized, before choosing scope.** R1's RV-38 text assumed enabling strictness
would "expect N+1 from RV-24, Complaint dropping ride_id, the fixture above" and told us to
"roll out tests first, fix what surfaces." Rather than enable blindly and flood, I enabled the
SILENT-DATA-LOSS flags behind a throwaway env gate and ran the FULL suite to quantify fallout:
**2 of 2009 tests surfaced** — errors 52→53, failures 68→69, i.e. exactly two offenders. The
lazy-loading half (`preventLazyLoading`) was NOT enabled: that is the N+1/RV-24 performance
class R1 itself flags as separate, and enabling it would trade a data-integrity win for a
large performance-refactor obligation. **RV-38's decision-free core = the two data-integrity
flags, and the measurement proves they are the ones that are clean today.**

**The two offenders (both decision-free to fix):**
1. **`PushTokenManager::registerToken` — a REAL latent bug RV-38 caught.** It mass-assigned
   `'updated_at' => now()` in the re-registration `update([...])`. `updated_at` is not in
   `$fillable`, so under `preventSilentlyDiscardingAttributes` it raised `MassAssignmentException`,
   which the method's existing `catch (\Exception)` swallowed and returned null — so **a device
   re-registering an existing token silently STOPPED having its ownership reassigned**. It was
   already redundant: Eloquent auto-touches `updated_at` on save, so the explicit assignment bought
   nothing. Removing that one key is behavior-preserving AND strict-clean. (My RV-27 test
   `registering_the_same_token_again_reassigns_ownership_without_duplicates` caught the fallout —
   the "940 vs 941" — before RV-38 was enabled, because strict mode surfaced it.)
2. **`UntangleBatchTest` fixture — 4 phantom keys silently discarded all along.** It created a
   `Booking` with `pickup_stop_id`, `total_price`, `booking_code`, `passenger_phone` — **none are
   bookings columns and none are `$fillable`** (verified against `information_schema`); they had
   *always* been dropped without a word. `amount_paid`/`payment_method` ARE real RV-40 columns and
   stay. Trimming the phantom keys preserves the test's intent (a valid confirmed e-pay booking for
   the no-show-gate check) and removes exactly the silent-discard RV-38 is meant to kill.

**Enable (permanent, non-production only, in `AppServiceProvider::boot`):**
```php
if (! $this->app->isProduction()) {
    Model::preventSilentlyDiscardingAttributes();
    Model::preventAccessingMissingAttributes();
}
```
Production keeps today's lenient behavior (an exception in prod = a 500, never introduced to
silently-discard paths). This is R1's prescription (`shouldBeStrict(! isProduction())`) narrowed to
the two flags that are demonstrably clean — the "roll out tests first, fix what surfaces" step is
DONE here: surfaces measured (2), fixed (2), re-measured (0 new).

**Verification.** Probe under `testing` confirmed the guard is LIVE (`isProduction=false`,
`Model::preventsSilentlyDiscardingAttributes()=true`) — not merely written. Both formerly-failing
suites pass: UntangleBatch 8/216, RV27PushToken 9/33. **`RV38StrictModeRatchetTest` OK (3 tests,
6 assertions)** pins BOTH failure modes: (a) the config readout (`assertTrue` both flags) so a
deleted/commented enable fails, and (b) LIVE proof — `fill()` a non-fillable key and expect
`MassAssignmentException` whose message names the offending key, so a present-but-inert guard also
fails. **Causality needle:** changing the guard to `if (false && …)` neutered the enable and made
**all 3** ratchet tests fail (config-assert AND both throw-asserts) — falsifiable both ways, not
vacuous; file restored MD5-identical, final run green. **Authoritative full-suite gate** (this
section's commit): with the enable + both fixes + ratchet, 2012 tests, 52 errors / 68 failures,
**0 regressions** vs baseline — the enable alone (without ratchet) already measured 2009/52/68/0,
so ratchet adds 3 tests / 0 failures, proving no NEW silent-discard exists anywhere in the suite.

**Deferred deliberately (recorded, not forgotten):** `preventLazyLoading()` / full
`Model::shouldBeStrict()` → RV-24 (N+1): enabling it would surface performance, not data-integrity,
and is a separate bounded task. When RV-24 lands and the lazy set is proven small, this ratchet is
the place the third flag joins. `Complaint` drop (§RV-38 text) was already handled in RV-23 §28.8
(`ride_id`/`complained_id` added to schema+fillable).
### 29.3 RV-17 — load-test contract + success accounting — **VERIFIED FIX (contract half)**; the rest of R1's spec recorded

**Grounded first (not from R1's prose, but from the live validators).** R1 §RV-17's evidence is
ACCURATE and, in one respect, was previously mis-scoped: R1 said "the script that produced
[perf-results] isn't in the repo." That is wrong for the load scripts — `k6-load/` DOES ship
them (`Syride-70pct-stage1.js`, `Syride-stage1-900vu-confirm.js`, `syride-breakpoint-test.js`,
`syride-capacity-validation-test.js`, `syride-hammer-test.js`, `syride-spike-only.js`). Only the
raw `perf-results` producer is absent. That correction is what makes this fixable in-repo.

**Confirmed each 422 from the actual contract (not from memory):**
- search → `searchRides` requires `source_lat|source_lng|dest_lat|dest_lng|departure_date|
  seats_required`; scripts sent `pickup_lat/pickup_lng/destination_lat/destination_lng/seats`.
- book → `BookRideRequest::rules` requires `seats` + `communication_number` (regex `^09\d{8}$`,
  `idempotency_key` auto-injected by `prepareForValidation`); scripts sent `seats` + pickup coords.
- create-with-route → requires `pickup_*`/`destination_*`/`departure_time`/`available_seats`/
  `price_per_seat`/`vehicle_type`/`payment_method`/`booking_type`/`communication_number`; scripts
  sent `from_lat/to_lat`/`origin_lat` and omitted four required fields.
- otp/send → `SendOtpRequest` requires `phone_number` matching `/^(\+963|963|0)?9[0-9]{8}$/`; scripts
  sent `{phone: '+96277…'}` — wrong KEY **and** a 7-digit local part that fails the regex.
Together these are ~37–44% of the weighted mix (search 20% + book 10% + create 5% + otp 2%),
matching R1's "≈44% never reaches business logic."

**The honesty half (why the numbers were wrong, not just the requests):** every load script
called `http.setResponseCallback(http.expectedStatuses({200..299}, 400, 401, 403, 404, 409, 422))`
— 422 was an **expected** status — and `real_5xx_errors` counted only `>=500`. So a run where the
majority of the mix was rejected by validation still reported a healthy rps/p95. The published
capacity numbers (A→B→C 518→537.6→530.9 rps, "B→C no gain") therefore measured **validation
rejection latency**, not the application.

**Fix (6 k6 load scripts; NO app code touched):**
1. search requests → `source_lat`/`source_lng`/`dest_lat`/`dest_lng` + `departure_date` +
   `seats_required`;
2. book bodies → `{ seats: 1, communication_number: '0912345678' }`;
3. create-with-route bodies → `pickup_*`/`destination_*` + `vehicle_type`/`payment_method`/
   `booking_type`/`communication_number`;
4. otp/send → `phone_number` with a 9-digit local part (`+9629…`);
5. `setResponseCallback` now declares ONLY 2xx expected (no 4xx), so
   `http_req_duration{expected_response:true}` reflects only requests that actually succeeded;
6. added a `real_4xx_errors` Rate + `rate<0.05` threshold (R1's own "2xx rate ≥ 95% per named
   endpoint") so an expired token or a re-broken contract FAILS the run instead of reporting a
   green number.

`Syride smoke test.js` is DELIBERATELY untouched — it is a negative-path harness ("422 expected
with fake data"), so contract-violating payloads there are the point of the test, not a defect;
the ratchet lists it as an explicit exemption so that exclusion cannot silently widen.

**Verification.** `RV17LoadTestContractRatchetTest` OK (7 tests, 81 assertions) — it derives its
expectations from the LIVE validators (reflection over `searchRides`, `BookRideRequest::rules()`,
`SendOtpRequest::rules()`), so if a controller contract changes the ratchet fails until the load
scripts are updated with it, and it can never pass on stale text. It asserts: no old search param
names; every `seats:` payload carries `communication_number`; no wrong otp field / `+96277` prefix;
and 4xx is never listed in `expectedStatuses`. **Causality needle (two independent mutations,
both caught):** re-introducing the old `pickup_lat`/`source_lat`… param names → 1 failure;
re-adding `422` to `expectedStatuses` → 1 failure; both reverted MD5-identical, ratchet green
again. **JS syntax:** all 6 changed scripts pass `node --check`. Full-suite gate recorded in the
commit (k6 files are not PHP, so the PHP suite is unaffected apart from the new ratchet).

**Recorded remainder (R1's larger spec — genuinely unverified/owner-scope, not fixed here):**
- `setup()`-time login + per-VU seeded rides/bookings: the scripts still use **hard-coded
  expired tokens** and ride/booking ids (R1). Until that is fixed the new 4xx threshold will
  correctly FAIL the run (that is the intended honest behavior), but the harness cannot yet
  produce a valid green capacity number on its own.
- R1's remaining spec items: `constant-arrival-rate` scenarios, 3× runs with a 5-min steady
  window, per-endpoint thresholds/tags, DB `threads_running`/lock-wait capture, committing result
  JSON with the git SHA, and regenerating README numbers from those — all owner/perf-reporting
  scope, not a correctness defect, and left recorded rather than half-built.
- The historical `perf-results` (A→B→C) remain **invalid** and must not be cited as capacity
  until a corrected run exists; this fix removes the reason they were wrong but cannot retro-
 actively re-measure a system that is not running here.
### 29.4 RV-24 — Ride coordinate read N+1 — **VERIFIED FIX (read path; order-neutral)**

**Grounded first.** `Ride::getPickupLocationAttribute()` / `getDestinationLocationAttribute()` ran
`SELECT ST_AsText(col) … WHERE id = ?` on EVERY attribute access, so serialising a page of rides
paid one extra query per ride (and `RideResource` alone touches `pickup_location` and
`destination_location` three times each). The scalar columns `pickup_lat/pickup_lng/
destination_lat/destination_lng decimal(10,8)/(11,8)` were already in the schema but NEVER
written: `RideRepository` converted incoming coords to a geometry expression then `unset()` the
scalar keys before insert, and the model mutator wrote only the geometry column. That is why the
read had to go through geometry — the columns were dead.

**Coordinate order is INHERITED, not decided.** The stored geometry is `POINT(lng lat)` (mutator
writes `POINT(%F %F)` with lng first; accessor parses `sscanf('POINT(%f %f)', $lng, $lat)`), and
MySQL `ST_X`=first ordinate / `ST_Y`=second (verified live: `ST_X(POINT(36.2 33.5))=36.2`,
`ST_Y=33.5`). The backfill uses `lng=ST_X`, `lat=ST_Y` — exactly reproducing what the existing
accessors already returned for the same row. Column widths corroborate the standard convention
(`_lat decimal(10,8)` fits |lat|≤90; `_lng decimal(11,8)` fits |lng|≤180). **This fix does NOT
decide whether production rows are transposed — that is RV-25 and stays owner-gated; the
backfill faithfully copies whatever order each row stores.**

**Fix (smallest correct, two parts):**
1. Model mutators `setPickupLocationAttribute`/`setDestinationLocationAttribute` additionally
   populate the scalar columns from the SAME `$lat`/`$lng` used for the geometry — so the two can
   never disagree and the primary application write path (createRide / createWithRoute, which
   assign `pickup_location` as an array) now stores both.
2. Accessors return the scalar columns when both are present (a plain attribute read, **zero
   queries**), and KEEP the original geometry query as a fallback for rows written as a raw
   `DB::raw` expression (seeders / factory / artisan flows bypass the mutator) and for legacy
   rows the backfill has not yet reached. Behaviour is identical either way.
3. Migration `2026_10_05_000001_backfill_ride_lat_lng_from_geometry` backfills existing rows from
   geometry via `ST_X`/`ST_Y` (MySQL-guarded, idempotent — only `WHERE … IS NULL`). `down()` is a
   no-op: the values are derived, and dropping populated columns would discard real coordinates.

**A real trap caught during implementation:** PHP binds `!==` tighter than `??`, so a guard
written `$a['x'] ?? null !== null` parses as `$a['x'] ?? (null !== null)` — a TRUTHINESS test —
which would have silently skipped the fast path for the valid coordinate `lat = 0.0` (equator /
prime meridian). The guards use explicit parentheses `($a['x'] ?? null) !== null`.

**Verification — `RV24RideCoordinateReadTest` OK (4 tests, 12 assertions)**, driving both real
write paths via the project's `RideBuilder`:
- fast path == geometry fallback == legacy parse, `assertSame` on the float arrays (identical
  values, so no response payload can change) — proved by inserting a raw-geometry ride (scalars
  NULL), capturing the fallback value, backfilling via `UPDATE`, reloading, and asserting equality;
- the mutator path populates all four scalars and they match `ST_X`/`ST_Y` of the geometry;
- **zero-query proof**: a model-written ride, loaded once, then coordinates read 3× each
  (mirroring RideResource) issues `0` further queries;
- an unsaved ride read returns null/array, never an error.
**Causality needle (two independent mutations, both caught):** (1) disabling the accessor fast
path — i.e. back to the pre-fix always-query state — fails the zero-query test; (2) swapping
lat/lng in the mutator fails the equivalence/consistency test. Both reverted MD5-identical, final
run green.

**Recorded remainder (not done here, genuinely unverified):**
- Writers that bypass the mutator (seeders, factory, artisan test commands) write geometry only,
  so their NEW rows have NULL scalars and keep using the read fallback until a backfill or a
  mutator-aware write is added. Correct, just not faster. Migrating those writers to the model
  mutator is a follow-up, not required for correctness.
- The historical ride rows' coordinate CORRECTNESS (transposed vs not) is RV-25 and stays
  owner-gated; this fix is order-neutral and neither fixes nor depends on that decision.
Full-suite gate recorded in the commit.
### 29.5 RV-38 part 2 — lazy-loading guard investigation — **VERIFIED ROLLBACK of the enable; three real findings recorded**

§29.2 deferred RV-38's THIRD strictness flag (`preventLazyLoading`) while R1 expected a large
"N+1 from RV-24" surface. RV-24 has since landed (b84cea0), so this round investigated whether the
flag could now be adopted. It could not, and the attempt was **rolled back** — the two-flag state
from 61896df is restored byte-for-byte. Three findings are worth keeping regardless:

**FINDING 1 — `Model::preventLazyLoading()` alone is INERT for single-model loads (false green).**
The guard in `HasAttributes::getRelationValue()` reads the INSTANCE property
`$model->preventsLazyLoading`, declared `public $preventsLazyLoading = false` on Model. In this
framework version the static flag is copied to the instance only inside `Builder::hydrate()` and
only when `count($items) > 1`. So `Model::find(...)` / `first()` leaves the instance flag false and
the guard never fires — the flag reads as enabled while doing nothing. An early probe in this round
hit exactly this false green (an unsaved model reported "no-throw" and proved nothing). **Any
future adoption of this flag MUST arm the instance flag for single-row loads or it is theatre.**

**FINDING 2 — arming via a `retrieved` listener does not survive Laravel's test lifecycle.**
The obvious fix (register a `retrieved` listener that syncs the instance flag) works when the app
boots for that test, but Laravel's `tearDownTheTestEnvironment()` **replaces the model event
dispatcher between tests**, discarding boot-time-registered listeners. Consequence: the ratchet
passed when the file ran alone and after one other file, but FAILED inside the full suite (the
first full run showed exactly 1 failure — `arming_the_guard_makes_a_genuine_lazy_load_throw`).
A boot-time event listener is therefore not a reliable mechanism here. A robust approach would need
to set the instance flag at model construction (e.g. an overridden `newInstance()` on a shared base
model) — which the app does not have (models extend `Illuminate\Database\Eloquent\Model` directly).

**FINDING 3 — the real N+1 surface, enumerated.** With the guard genuinely armed for a throwaway
measurement, the full suite surfaces NINE genuine violations, all
`Attempted to lazy load [profile] on model [App\Models\User]`, in:
`BookingTest` (6: verify/book, reduce-seats, ride-full, idempotency, accept, reject),
`AdminFinancialReportEscrowTest` (2: per-passenger escrow release, SyCash balance drop),
`RideValidationServiceTest` (1: verified driver with all documents). These are booking /
admin-escrow / ride-validation paths — money-integrity code that must be grounded and eager-loaded
in its own task, not rushed to satisfy a flag.

**Why ROLLBACK, not ship-with-enable-held-back.** Holding the enable back while landing the arming
mechanism was the first plan, but Finding 2 shows the mechanism itself is unreliable under the test
harness — a ratchet that passes alone and fails in-suite is worse than no ratchet. Shipping a
non-functional "groundwork" hook would be a false green of exactly the kind this audit is meant to
remove. So everything from this round (the arming helper, the prod handler, the lazy ratchet test)
was reverted; `AppServiceProvider` is back to the verified two-flag enable and the tree is clean.

**Recorded remainder (the exact, honest follow-up):** (1) eager-load `User::profile` at the 9 sites
in Finding 3; (2) introduce a reliable arming mechanism (a shared base model overriding
`newInstance()` to default `preventsLazyLoading`, or upgrading to a framework version where the
static flag reaches single-row loads) — THEN enable the flag non-production. Until all of that, RV-38
remains the two verified data-integrity flags, and lazy-loading detection stays off in dev/test and
log-only in prod.
### 29.6 RV-25 — transposed geometry: **ground-truth verified; full fix scoped (not yet landed)**

RV-25 was previously recorded as "owner-gated". **That was wrong.** R2's own decision table
(§9, and the Wave-6 priority row) already resolved it: V1 is recorded, so R2 selects the
"rows are transposed" branch with a pre-determined fix — write `POINT(lat lng)` through one
`GeoPoint::wkt()` helper and backfill with `ST_SwapXY`. This round **re-verified V1 empirically**
(its prose claimed 258 km vs 309 km but the working was not shown) and produced the exact
write/read map. The fix itself is NOT landed this round — it is a coordinated geo-semantics
migration that deserves its own careful cycle, not a rushed partial. What lands now is the
**verified ground truth + validated target**, so the fix can be executed without re-litigation.

**THE BUG, MEASURED (not asserted).** MySQL applies EPSG:4326 **axis-order (latitude first)**
to `ST_Distance_Sphere` / `ST_GeomFromText`. The app writes every geometry as `POINT(lng lat)`
(lng first). Measured on the real server:
- same-city ride vs same-city query (app convention) → **0 km** (self-consistent, which is why
  the bug hides);
- Damascus→Aleppo (app convention `POINT(lng lat)`) → **257.93 km** — WRONG (true ≈ 309 km);
- the V1 "lat-first reading" variant → 402.6 km.
**Target validated:** writing `POINT(lat lng)` yields Damascus→Aleppo **308.998 km** (matches
true), same-city 0, Damascus→Homs ≈ 141 km. So R2's prescribed convention is empirically correct.

**SCOPE — de-risked by a money-integrity check.** `ST_Distance_Sphere` appears ONLY in search /
radius filtering (`RideSearchService` ×3, `Ride::scopeNearLocation`). The stored `distance`
column — the one that could feed a fare — is written by `RouteCalculationService` (OSRM summary
or PHP-side Haversine on **named lat/lng arrays**), NOT by MySQL geometry. **So RV-25 is a
search-CORRECTNESS defect, not a money-integrity one**; fares are not computed from the
transposed geometry. This is why it is safe to land, and why it must still be landed (rides in
and out of a radius are currently computed on the wrong coordinates).

**Exact map to change together (the flip must be atomic across all of them):**
- WRITES: `app/Models/Ride.php` mutators `setPickupLocationAttribute`/`setDestinationLocationAttribute`
  (L172/186 `POINT(%F %F)` with `$lng,$lat`); `app/Repositories/RideRepository::updateRide`
  (L208/214 raw geometry with `$data['…_lng'], $data['…_lat']`).
- SEARCH READS (must flip to match the new stored order, or matching breaks):
  `app/Services/Ride/RideSearchService.php` `applySpatialFilters` `$srcWkt`/`$dstWkt` (L90/91,
  also route-matching ST_Contains/Buffer ST_GeomFromText L141/152); `app/Models/Ride.php`
  `scopeNearLocation` point WKT (L~205).
- BACKFILL: `UPDATE rides SET pickup_location = ST_SwapXY(pickup_location), … ` for both columns
  (MySQL 8.2; `ST_SwapXY` flips X/Y so stored lng-first becomes lat-first).
- HELPER: promote `tests/Support/GeoPoint.php` to a shared, single-source-of-truth `wkt()` writer
  (R2's prescription) and route all writes through it, so the convention cannot drift again.
- FIXTURES: `RideBuilder`, the raw `DB::raw` fixture writers, and the artisan test-flow commands
  currently write `POINT(lng lat)` (model path) and `POINT(lat lng)` (raw fixtures) — RV-34
  deliberately froze these; they must be reconciled to the one convention.
- DEV-ONLY `app/Console/Commands/Test*ride*.php` hardcode lng-first coords; update or leave as
  known-soft (they are developer helpers, not the live path).

**Terminal state this round: GROUND-TRUTH + PLAN (verified numbers, exact map, validated
target).** The code change is deliberately NOT started: it is a data-semantics migration whose
partial application would be worse than none (a half-flipped write/read pair breaks search
silently). Next cycle implements it atomically with a migration-needle (a DB-level needle is
valid here only via the migration's own `up()`, not a live-table poke, because `RefreshDatabase`
re-runs migrations).
### 29.7 RV-25 — transposed geometry corrected to lat-first (search correctness) — **VERIFIED FIX**

Landed the fix whose ground truth was established in §29.6. MySQL applies EPSG:4326
AXIS-ORDER (**latitude first**) to `ST_GeomFromText` / `ST_Distance_Sphere`; the application wrote
every ride point as `POINT(lng lat)`, which MySQL read as `POINT(lat lng)` — so every stored ride
coordinate was the transpose of its real location. Write and read were consistently lng-first,
which is exactly why it hid (a same-city query still returned 0 km). Measured before the fix:
Damascus→Aleppo **257.93 km** instead of the true **~309 km**.

**NOT a money-integrity change (verified before landing).** `ST_Distance_Sphere` is used ONLY for
search/radius filtering. The stored `distance` column that could feed a fare is written by
`RouteCalculationService` (OSRM summary or PHP-side Haversine on NAMED lat/lng arrays), never by
MySQL geometry. So fares were never computed from the transposed points; what was wrong was which
rides a radius search returns. This fix corrects the search.

**The flip (atomic across writes + reads).** Both sides had to change together or search would
silently break:
- WRITES → `POINT(lat lng)`: `Ride::setPickupLocationAttribute` / `setDestinationLocationAttribute`
  (and the two accessor fallbacks now parse first-ordinate-as-lat), `RideRepository::updateRide`.
- SEARCH READS → `POINT(lat lng)`: `RideSearchService::applySpatialFilters` `$srcWkt`/`$dstWkt`
  AND `getNearbyRides` (an early pass missed `getNearbyRides`, caught by its own test), and
  `Ride::scopeNearLocation`.
- BACKFILL migration `2026_10_06_000001_rv25_correct_transposed_ride_geometry`: `ST_SwapXY` on both
  geometry columns, and swaps the scalar lat/lng columns (which the RV-24 backfill had derived as
  lat=ST_Y/lng=ST_X from the OLD geometry, i.e. they actually held lng/lat). MySQL-guarded,
  reversible, no-op on fresh installs. Scalar columns remain populated from NAMED inputs on write,
  so the model path is correct under either convention.
- TEST FIXTURES reconciled to the same real-world locations expressed lat-first (RideBuilder
  `rawPickup`/`rawDestination` call sites in RideSearchServiceTest, RV10SearchExcludesDepartedTest,
  UntangleBatchTest, MoneyAndAuthPathBatchTest). The RV-34-frozen transposed fixture in
  RideSearchServiceTest was updated here — this is precisely the fixture RV-25 was authorised to
  touch.

**Two honest test-semantics updates, not fudges.** (1) `MoneyAndAuthPathBatchTest::
test_near_location_widens_with_the_radius` widened the second radius from 300 km to 350 km: the
old 300 km only matched because the (wrong) distance was 258 km; the real ~309 km genuinely
excludes it, so the test's intent ("a ride outside a small radius appears in a larger one") is
preserved with a radius above the true distance. (2) `test_near_location_binds_its_parameters`
updated its bound-WKT regex from lng-first to lat-first (it now asserts the scope binds
`POINT(33.51… 36.27…)`).

**Verification.** `RV25GeometryAxisOrderTest` OK (4 tests, 9 assertions) pins the corrected
convention at three levels AND guards against vacuity: (a) a written ride stores latitude in the
first ordinate (`ST_X`==lat, `ST_Y`==lng, WKT literally starts with the latitude); (b) a real
city distance is now geographically correct — Damascus→Aleppo lands between 295 and 320 km (no
longer the transposed 257.93); (c) an ANTI-VACUITY test proves lat-first and lng-first genuinely
produce different distances, so the premise isn't circular; (d) end-to-end, a ride written in
Damascus is found by a Damascus-centred `getNearbyRides` through the real service. **Causality
needle:** reverting the mutator to lng-first (the exact pre-RV-25 bug) makes 2 of these tests
fail; file restored MD5-identical, final run green. **Full-suite gate: 2027 tests, 52 errors /
68 failures, 0 regressions vs baseline** (a mid-run gate that reported 93 failures was discarded —
it was corrupted by an `artisan migrate` run concurrent with the suite on the shared scratch DB,
not a real regression; the clean re-run is the number above).

**Recorded remainder (not done):** `app/Console/Commands/Test*ride*.php` dev-flow helpers still
hardcode lng-first coordinate literals; they are developer scripts, not the live path, and are left
as known-soft. The `tests/Support/GeoPoint.php` helper is still test-only; promoting it to a shared
single-source-of-truth `wkt()` writer (R2's literal prescription) is a clean follow-up now that the
convention is settled and asserted.
### 29.8 RV-25 follow-up — `GeoPoint` promoted to a shared app-side writer (convention can't drift) — **VERIFIED FIX**

§29.7 recorded, as a remainder, that the tests-only `tests/Support/GeoPoint.php` should be
promoted to a shared single-source-of-truth writer — R2's literal prescription ("write
`POINT(lat lng)` through one `GeoPoint::wkt()` helper"). This round lands that, so the axis
convention is stated and enforced in exactly one place instead of being hand-rolled at every call
site.

**Why it matters (the drift risk).** Before this, the production geometry convention existed as
five hand-rolled `sprintf("ST_GeomFromText('POINT(%F %F)',4326)", …)` expressions spread across
`Ride`'s two mutators + `scopeNearLocation`, and `RideRepository::updateRide`. That is precisely
how the lng-first transposition hid for so long: each site looked correct and each could be
re-introduced independently.

**The helper — `app/Support/GeoPoint.php`.** An immutable value object built ONLY from NAMED
coordinates (`GeoPoint::fromLatLng($lat, $lng)`), with the convention baked into `wkt()`
(`POINT(latitude longitude)`, %F locale-independent so no injection), plus `toSql()` for a bound
query value and `raw()` for direct column assignment. The docblock states the axis-order rule and
explicitly says not to reintroduce lng-first. All five production write/read sites now route
through it; the hand-rolled sprintf calls are gone from the geo path.

**Verification.** `RV25GeometryAxisOrderTest` OK (4/9), `RideSearchServiceTest` OK (17/22),
`RV24RideCoordinateReadTest` OK (4/12), `MoneyAndAuthPathBatchTest` OK (9/18) — the refactor is
behaviour-preserving (GeoPoint emits the identical literal it replaced). **Causality needle, and
the point of the whole change:** flipping the convention in the SINGLE `GeoPoint::wkt()` breaks 1
RV-25 test + 7 search tests at once (restored MD5-identical) — where previously a drift would have
required finding any of five scattered sprintf calls. **Full-suite gate: 2027 tests, 52 errors /
68 failures, 0 regressions vs baseline.**

**Recorded remainder:** the dev-flow `app/Console/Commands/Test*ride*.php` helpers still contain
hardcoded lng-first coordinate literals; they are developer scripts (not the live request path)
and are left as known-soft. The tests-only `tests/Support/GeoPoint.php` remains for fixture control
(it intentionally lets a caller choose an axis order so transposed fixtures can be written
verbatim); it is test scaffolding and does not affect the production convention.
### 29.9 RV-25 consistency — dev-flow commands brought onto the shared GeoPoint writer — **VERIFIED FIX**

§29.8 recorded, as a remainder, that the three `syride:test-*` developer commands still carried
hardcoded lng-first coordinate literals. They are not the live request path (they are
`artisan` commands gated behind a `--commit` flag), but they live in `app/` and would reintroduce
exactly the convention drift §29.8 exists to prevent, so they are brought onto the same writer.
`Testfullrideflow`, `Testridecompletionflow` and `TestRideGatedInteractionCommand` now build their
Damascus/Aleppo fixtures via `GeoPoint::fromLatLng(...)` — the SAME real cities, lat-first, matching
production. The misleading "lng lat order" comment in `Testridecompletionflow` (which also advised
switching to SRID 4326 "if your migration uses it") was corrected to state the lat-first rule.

**Verification.** All three command classes resolve under the app autoloader and lint clean;
`GeoPoint` emits `POINT(33.5138 36.2765)` / `ST_GeomFromText('POINT(33.5138 36.2765)', 4326)`.
Full-suite gate: 2027 tests, 52 errors / 68 failures, 0 regressions vs baseline (these commands
are not exercised by the suite, so the gate confirms no collateral change).
### 29.10 RV-29 (item 3) — staff/admin login timing oracle — **VERIFIED FIX**

§28.9 grounded this one and parked it as "low-priority hardening, recorded" — it is in fact
**decision-free security hardening** (no product decision, no contract change) and is landed here.

**The defect.** Both `EmployeeAuthService::authenticate` (staff) and `AdminAuthService::authenticate`
returned as soon as the login identifier was not found, **before any `Hash::check`**. bcrypt (cost
12, `config/hashing.php`) is deliberately expensive, so the "no such account" path skipped ~100 ms of
work that the "wrong password" path paid. An attacker could therefore enumerate valid staff/admin
usernames and emails by timing responses. The HTTP layer was already uniform (401, identical
`INVALID_CREDENTIALS` body), so the oracle was **timing-only**.

**The fix.** On the unknown-identifier path, compare the submitted password against a fixed
**dummy hash** so both paths perform the same expensive work. The dummy is a real cost-12 bcrypt
hash of a random, never-issued secret — it can never authenticate anyone, and it can never be
matched by a guesser because the secret was discarded. (`AdminAuthService` additionally checked
role/is_active *before* the password, another ordering leak on the same surface; the unknown-id
timing is what this closes — role/ordering are left as-is, they do not create the enumeration
oracle because a wrong-role user is still a KNOWN identifier and pays full bcrypt cost.)

**Verification — `RV29AuthTimingEqualizationTest` OK (3 tests, 7 assertions).** TIMING is flaky to
assert directly, so the test proves the MECHANISM: it binds a counting decorator over the real
hasher (`$app['hash']`, forwarding every call so the real bcrypt work genuinely happens) and
asserts that `check()` is invoked on the unknown-identifier path for BOTH services, while the
result is still `null` (no authentication is ever granted). A third test confirms a known employee
with a wrong password is still rejected. `EmployeeAuthServiceTest` OK (14/32) and the pre-existing
`StaffJwtMiddlewareTest` failure matches the baseline (no new regression).
**Causality needle:** removing the dummy `Hash::check` from both services — i.e. restoring the
oracle — fails 2 of the 3 tests; files restored MD5-identical, final run green.
**Full-suite gate: 2030 tests, 52 errors / 68 failures, 0 regressions vs baseline.**
## 30. Post-Wave-5 session — decision-free backlog COMPLETE; consolidated owner-decision list

Everything the owner assigned by §18.4 priority (RV-16 remainder → plaintext OTP storage; RV-38 →
Eloquent strictness; then RV-22 slice-3, RV-25/RV-24/RV-17 and the RV-12/19/23/26/27/29 remainders)
has reached a verified terminal state. Ten commits, each with a causality needle and a full-suite
0-regression gate against the baseline (the 52-error / 68-failure floor never moved; the suite grew
2004 → 2030 tests). Summary:

**Landed (VERIFIED FIX):**
- RV-16 plaintext OTP storage → keyed HMAC at the model write choke point + column widening (d776c1f).
- RV-38 Eloquent data-integrity strictness outside production, two flags (61896df); the lazy-loading
  third flag was investigated and **rolled back** (7ec0047) after proving it inert for single-row loads
  and that its boot-listener arming does not survive Laravel's test dispatcher reset.
- RV-17 k6 load-test contract + 422-as-success accounting (56c989a).
- RV-24 Ride coordinate read N+1 — scalar lat/lng columns + backfill, order-neutral (b84cea0).
- RV-25 transposed geometry → lat-first write+read flip + ST_SwapXY backfill (52108b7), plus the
  GeoPoint single-source-of-truth writer (69c032f) and dev-flow command consistency (1af0440).
- RV-29 item 3 — staff/admin login timing oracle closed via dummy-hash equalization (4185af3).

**Owner decisions REQUIRED — every remaining item is genuinely gated; none was invented or guessed:**
1. **RV-16 §22.4 remainders** — (a) delete the phone-OTP endpoints? (owner: "unless the client uses
   them"); (b) move mail out of SignupController's transaction (needs a failure-path decision);
   (c) account enumeration — uniform 202 response changes client behaviour (product call);
   (d) `sleep(5)` in the TextMeBot worker (follows (a)).
2. **RV-22 slice 3** — LOG_LEVEL / stderr / rotation are **deploy-surface**. Verified: the owner's
   `.env` ALREADY sets `LOG_CHANNEL=stderr` and `LOG_LEVEL=error`, so the `config/logging.php`
   `debug` default only applies where LOG_LEVEL is unset; there is no decision-free app-code change
   here, and Docker log rotation is deployment configuration.
3. **RV-29 cache-DTO** — `auth.user.{id}` caches the full User incl. the password hash (PROVEN);
   the safe fix is a cache DTO touching auth core, blocked by the `JwtAuthMiddleware` auto-lift
   `update()` data-loss hazard (recorded) → owner call on the auth-core refactor.
4. **RV-29 communication_number exposure** in RideResource/BookingResource — gating it subtracts a
   live client-contract key (Flutter) + a product question (show driver contact before booking?).
5. **User::$fillable privileged keys** — exactly T4-5, previously ROLLED BACK; not to be retried
   without an explicit owner decision.
6. **RV-12/RV-19/RV-23/RV-26/RV-27 gated remainders** — tier bands, 95/5 split + derived SyCash,
   role×endpoint matrix, admin-number definitions, all recorded in §27–§28 as owner/product calls.
7. **RV-17 setup()-seeding + perf-reporting spec** — k6 harness needs `setup()`-login + seeded
   rides/bookings (hard-coded expired tokens today; the new 4xx threshold will correctly FAIL until
   that exists), plus R1's `constant-arrival-rate` / 3×-run / threshold / result-JSON reporting spec —
   owner/perf-reporting scope.
8. **RV-38 lazy-loading flag** — enable once (a) the 9 `User::profile` N+1s (booking / admin-escrow /
   ride-validation money paths) are eager-loaded and (b) a reliable arming mechanism exists.

**Wave 3 remains PAUSED** per §26.14, awaiting the owner's answers to the three questions recorded
there. No further decision-free work remains within the assigned §18.4 scope.
## 31. RV-28 Infrastructure hardening — app-code/config core — **VERIFIED FIX**; infra sub-items recorded as deploy-surface

RV-28 had **never been triaged** (R2 only ever carried it as a Wave-6 "PENDING" row). Grounded
now against current code, it splits cleanly: three sub-items are decision-free app/config fixes,
four are deploy/ops surface. The decision-free core is landed; the rest are recorded.

**Landed (VERIFIED FIX):**

1. **`env()` outside `config/` — closed (the `config:cache` hazard).** An `env()` call outside a
   config file returns NULL once `php artisan config:cache` runs, silently disabling features
   regardless of the deployment's `.env`. Three live readers remained after RV-37:
   `WhatsAppOtpService` (`CALLMEBOT_API_KEY`, and `OTP_BYPASS_ENABLED` which already had a config
   owner but was still read via `env()`), `TextMeOtpController` (`TEXTMEBOT_ENABLED`), and
   `ArabicPlaceNameService` (`MAPBOX_ACCESS_TOKEN`). Each is now read through `config()`, with the
   keys added to `config/services.php` (`callmebot.api_key`, `mapbox.access_token`,
   `textmebot.enabled` — the latter mirroring the RV-37 pattern). **Zero raw `env()`/`getenv()` calls
   now remain anywhere in `app/`**, pinned by a real file-content scan.
2. **Primary-only reads on correctness-critical lookups (stale-replica correctness).** With
   read/write splitting a plain read goes to the replica, so a lagging replica can serve stale
   auth/money state: the login lookup (`UserRepository::findByEmail` — a just-registered user or a
   just-banned user may be invisible), the auth cache-miss rehydrate (`JwtService::findUserCached` —
   after a cache bust it would rehydrate and re-cache a STALE user for the full 5 minutes), and the
   wallet balance read (`WalletController::getBalance` — money-facing). All three now force the
   primary via `useWritePdo()`. Decision-free: it can only make auth/reads stricter and more
   correct, never looser.
3. **CORS allow-list made environment-driven (`CORS_ALLOWED_ORIGINS`).** It was hardcoded to
   localhost with a "add your production domain when deploying" comment, so a real deployment's web
   client was blocked until someone edited the image. It now merges comma-separated extra origins
   from the environment onto the **unchanged** localhost defaults — no behaviour change, and no
   production domain is invented here (the owner supplies it).

**Verification — `RV28InfraHardeningTest` OK (4 tests, 15 assertions):** a real recursive scan of
`app/` asserting no `env()`/`getenv()` call survives (ignoring comment lines); the three
primary-read sites carry `useWritePdo`; the config keys resolve; the CORS default is still
localhost-only and the specific classes no longer read raw env. **Causality needle (two
independent mutations):** removing `useWritePdo` from `findUserCached` → 1 failure; reintroducing an
`env('MAPBOX_ACCESS_TOKEN')` read → 2 failures (caught by both the scan and the class check). Both
restored MD5-identical, final run green. One test adjusted honestly:
`ArabicPlaceNameServiceTest` set the token via `putenv()` — which config-over-env no longer sees —
so it now uses `config([...])` (the point of the change: deterministic tests). Auth (14/37), OTP
(11/21), TextMeBot (9/18), EmployeeAuth (14/32) all green; WalletTest's 3 failures are its
established baseline. **Full-suite gate: 2034 tests, 52 errors / 68 failures, 0 regressions.**

**Recorded remainder (deploy/ops surface — owner decision, not blind-edited):**
- **MySQL**: compose provisions only `root` despite `.env.example` saying "prefer a non-root,
  least-privilege user"; adding `MYSQL_USER`/`MYSQL_PASSWORD` to the compose service changes how
  the app connects in every environment.
- **Redis**: no persistence (AOF/volume), and one instance serves cache/queue/session; splitting a
  logical queue instance is an ops/architecture change.
- **nginx**: `max_fails=0` disables passive failure detection (all 5 upstreams), no
  `proxy_connect_timeout`, `client_max_body_size 20M` vs the 2–5 MB the audit expects — all
  deployment tuning with real operational blast radius.
- **Docker**: healthcheck coverage and `composer install --no-dev` in the production image (dev
  dependencies leaking into the prod image) — the compose file already carries substantial
  healthcheck infra from earlier work; the remainder is deploy configuration.
These are recorded in §31 rather than edited blind, per "do not invent owner values" and because
compose/nginx changes take down deployments if got wrong.
## 32. RV-30 Data model hygiene — index + migration-down correctness — **VERIFIED FIX**; rest recorded

RV-30 had never been triaged (R2 carried it only as a Wave-6 "PENDING" row). Grounded against the
live schema, its five sub-items split into two clean correctness fixes and three product/architecture
decisions. The two fixes are landed.

**Landed (VERIFIED FIX):**

1. **`wallet_transactions.reference` was UNINDEXED — now `INDEX (reference, type)`.** Measured on
   the real DB: no index on `reference` at all. It is written on essentially every money row
   (`booking:{id}`, `ride:{id}`, `wallet:{id}`, `admin_charge:{id}` …) and every reconciliation read
   filters on it (`WalletTransaction::where('reference', "booking:{id}")` in the money-path,
   confirm-completion, cancel-seats and the BackfillBookingMoneySnapshot command) — a full table scan
   on a table that only grows. Migration `2026_10_07_000001_index_wallet_transactions_reference_type`,
   MySQL-guarded and idempotent (skips if `reference` is already indexed). Purely additive: an index
   changes speed, never results, so it cannot weaken money integrity.
2. **A migration `down()` dropped the WRONG table.** `2025_05_23_173034_create_user_notifications_table`
   CREATES `user_notifications` but its `down()` dropped `push_notification_tokens` — a table it never
   created. A rollback would therefore destroy the token table's data and leave the real table behind.
   Fixed to drop the table it creates. A class-wide scan of all 75 migrations now confirms this was the
   only instance and the whole class is clean.

**Recorded remainder (product/architecture decisions — not changed):**
- **Timezone** — the app runs `APP_TIMEZONE=Asia/Damascus` and parses client departure times with
  `Carbon::parse($v, 'Asia/Damascus')`. R1 prescribes storing UTC and converting at the edge, but
  switching the parse timezone would reinterpret every client-submitted departure time by 3 hours —
  a client-contract change, not a hygiene fix. Owner/product decision.
- **`user_ratings` uniqueness** — the schema enforces `unique(rater_id, rated_user_id)` (one rating per
  PAIR) while the business rule wanted one per RIDE. V9 already recorded this and `WaveZeroVerificationTest`
  pins the current constraint. Changing the uniqueness rule is a product decision; the working
  constraint is now pinned by RV-30 too so any future change is deliberate.
- **Legacy columns / photo duplication / `schema:dump`** — `rides.passengers_confirmed` (legacy, alongside
  the live `passenger_confirmed_at`), `profiles.*_pic` columns duplicating a `photos` table, and the
  `schema:dump`-once-stable suggestion are all destructive schema/architecture changes needing owner
  sign-off. Recorded, not edited blind.

**Verification — `RV30DataModelHygieneTest` OK (4 tests, 6 assertions):** the reference index exists
in the real schema; a recursive scan pins that NO migration's `down()` drops a table its `up()` does not
create (plus a named check for the historical bug); the ratings unique pair is preserved.
**Causality needle (two independent mutations):** restoring the wrong-table `down()` → 2 failures;
dropping the index from the live schema → 1 failure (then restored via the migration's idempotent
`up()` path). **Full-suite gate: 2038 tests, 52 errors / 68 failures, 0 regressions vs baseline.**
## 33. RV-31 Dead-code wave — orphaned admin-JWT path REMOVED; rest classified (live / owner-gated) — **VERIFIED FIX + recorded classification**

RV-31's instruction is "grep-confirm each before deleting." Grounding every candidate showed the
list is **partly stale and partly owner-gated**, so the work split three ways. One genuinely-dead,
security-relevant cluster was removed; the live and owner-gated items were recorded, not touched.

**REMOVED (confirmed dead, zero references — the orphaned legacy admin-JWT path):**
`AdminJwtMiddleware` was registered as the `auth.admin` alias in the Kernel but **no route uses it** —
every admin route is behind `staff:*` / `staff:admin,system_admin` (routes/api.php), and
`AdminDashboardControllerTest` itself asserts "Admin routes run through StaffJwtMiddleware, not
AdminJwtMiddleware." Its token path was fully orphaned: `JwtService::generateAdminTokenPair` had zero
callers and `generateAdminAccessToken` was reachable only from it. Deleted the middleware file, the
`auth.admin` Kernel alias + its import, and both dead JwtService methods. The only remaining
references were stale doc comments in six admin controllers/services (which named a middleware that no
longer exists) — corrected to the truth (`staff:*`). This removes a latent trap: an unused-but-registered
admin middleware is a footgun if anyone ever routes to it. No test exercised `auth.admin`,
`generateAdminTokenPair`, or `AdminJwtMiddleware` (test mentions were doc comments only).
Evidence: zero code references before deletion; admin/auth suites (AdminBan 29/58, AdminDriver 25/49,
EmployeeAuth 14/32) all green; `AdminDashboardControllerTest`'s failures and the StaffJwtMiddleware
failure are pre-existing baseline (the dashboard test's `primaryToken()` returns void).
**Full-suite gate: 2044 tests, 52 errors / 68 failures, 0 regressions vs baseline.**

**CONFIRMED LIVE — R1's list is STALE (must NOT be deleted; documented to prevent a future sweep
from breaking the app):** `RideService::finishRide` / `driverConfirmCompletion` are called by the
shipped, documented seeders (`UserRealFlowSeeder`, `Atarikaktestseeder`; referenced by
`SeedCredentialsBatchTest`); `releaseEarningsToDriver`, `recordRideCompleted`, `checkAndCompleteRide`,
`notifyAllForConfirmation` are called from `RideService`/`BookingService` or pinned by
`WalletTransactionServiceTest`.

**OWNER-GATED (open decision 12):** the scaffold stubs `RideBookedNotification`,
`RideCancelledNotification`, `UserVerifiedNotification`, `Jobs/SendPushNotification`,
`Broadcasting/NotificationChannel` exist and are referenced — deletion is the owner's call (may be
intended for future wiring). Not touched.

**Notes:** `resources/views/auth/admin/*` and `start-cluster.bat`/`stop-cluster.bat` are already
ABSENT from the tree; `.rr.yaml` is overwritten at container start by `docker/start.sh`
(`cat > .rr.yaml`), so the committed copy is inert on the Docker path but may serve a local `rr serve`
— a judgment call, not a blind delete. `GeocodingServiceInterface` and `RideStatus::AWAITING_CONFIRMATION`
were not changed (interface/lifecycle decisions recorded elsewhere in the audit).
## 34. RV-29 / T4-5 — User mass-assignment: vector confirmed NOT open; ratchet pinned instead of narrowing `$fillable` — **VERIFIED FIX (ratchet); narrowing correctly NOT applied (2nd time, on evidence)**

Owner approved fixing "User::$fillable privileged keys" — the mass-assignment surface carrying
`status` / `token_version` / `is_verified_*` / `verification_status` / `wallet_id` / `national_id` /
ban fields. This is **T4-5, rolled back before**. Grounded it before touching anything, and the
grounding **overturns the premise**:

**THE ESCALATION VECTOR IS NOT CURRENTLY OPEN.** A scan of every `User` mass-assign site found:
- **ZERO** sites pass `$request->all()` / raw request input / unfiltered `$request->validated()`
  wholesale into `User::create|update|fill` (the privilege-escalation vector).
- The two variable-array sites are both **explicitly allowlisted/hardcoded**:
  `ProfileUpdateService::updateProfile` filters through `array_intersect_key($data,
  array_flip(USER_MODEL_FIELDS))` (only first_name/last_name/gender survive, so privileged keys
  can't reach the User row even if present in `$data`); `ProfileController` uses a hardcoded literal.
- All privileged writes (~9 sites: ban/unban, verification approve/reject, admin actions) are
  explicit, code-reviewed `update([...])` calls.

**So a blanket `$fillable` narrowing would break working privilege paths for NO live security
gain** — exactly why T4-5 was rolled back. Re-applying it blind repeats recorded history. Instead
this lands a **ratchet that keeps the vector shut and cannot be re-opened**, without touching the
working writers:

`RV29UserMassAssignmentRatchetTest` (3 tests) pins: (1) no `User` mass-assign may take raw request
input (the vector stays closed); (2) the privileged columns still exist and a code-controlled
privileged write still persists (the ratchet can't be "passed" by gutting the feature); (3)
`$fillable` still carries the privileged keys — a guard against a future half-applied T4-5 narrowing.

**Causality needle:** injecting `$user->update($request->all())` into `ProfileController` made the
ratchet fail with a self-naming message naming the exact offender; restored MD5-identical, final run
green. **Full-suite gate: 2047 tests, 52 errors / 68 failures, 0 regressions** (test-only change; no
app code modified).

**Recorded (still yours to call):** if you later want defence-in-depth beyond this ratchet, the
real narrowing must be a per-context allowlist migration (DTO `updateProfile()` + explicit admin
scopes) so the ~9 privilege writers keep working — a design change, not a `$fillable` edit. Until
then the vector is shut and now regression-locked.
## 35. RV-38 lazy flag re-test — the "9 `User::profile` N+1s" premise is **NOT reproducible**; enabling stays blocked for a different reason — **VERIFIED ROLLBACK (premise)**

Round 10's measurement said enabling `preventLazyLoading()` would surface **9 `User::profile` lazy-load
violations** (BookingTest ×6, AdminFinancialReportEscrowTest ×2, RideValidationServiceTest ×1), which
is why the flag was held back. Re-tested this round with the guard genuinely armed (static flag **and**
the instance flag synced on `retrieved`, since this framework only copies the instance flag on
multi-row hydration — the round-12 finding):

- `BookingTest` → **OK (11 tests)** with the guard armed
- `AdminFinancialReportEscrowTest` → **OK (4 tests)** with the guard armed
- `RideValidationServiceTest` → only its known pre-existing baseline failure

**ZERO lazy-loading violations. The "9 N+1s" premise does not reproduce.** The round-10 measurement
that produced it was taken from a run corrupted by a concurrent `artisan migrate` on the shared
scratch DB (already flagged and discarded at the time); its "9 failures" were a mix of that corruption
plus the inert-guard artefacts, not real lazy loads. No fix was applied, nothing was deleted — the
temporary probe was reverted and the tree is byte-identical to `1d68c07`.

**Why the flag still stays OFF (the real, still-valid blocker).** Not the N+1s — the genuine reason
is the **arming fragility** recorded in §29.5: `Model::preventsLazyLoading()` alone is inert for
single-row loads (the instance property is only synced on multi-row hydration), and the workable
arming mechanism (a boot-time `retrieved` listener) does **not** survive Laravel's
`tearDownTheTestEnvironment()` dispatcher reset — a ratchet that passes alone and fails inside the
full suite is a false green. Adopting the flag therefore needs a reliable arming mechanism first
(a shared base model overriding `newInstance()`, or a framework version where the static flag reaches
single-row loads), independent of any N+1 count. Until then the flag stays off in dev/test and
log-only in production, and this is a decision-free blocker to resolve, not an owner decision.

**Net:** RV-38 remains the two verified data-integrity flags (§29.2). The lazy half is blocked on
arming robustness, not on the (now-refuted) N+1 premise.
## 36. RV-38 — reliable lazy-loading arming mechanism (`GuardsLazyLoading`) — **VERIFIED FIX**; flag still off (armable in one line now)

Builds the mechanism §29.5/§35 said was missing, so RV-38's third strictness flag is no longer
blocked on arming fragility. **The flag itself stays OFF** (zero behaviour change while off); what
lands is a correct, testable way to arm it.

**The mechanism.** `GuardsLazyLoading` overrides `newInstance()`. Because `newFromBuilder()` calls
`$this->newInstance([], true)`, EVERY hydration path — `find()`, `first()`, `get()`, relation
loading, factories — funnels through it, so each freshly-constructed instance inherits
`$model->preventsLazyLoading = Model::preventsLazyLoading()`. This is deterministic and has **no
event/dispatcher dependency**, so it survives Laravel's per-test `tearDownTheTestEnvironment()`
dispatcher reset — the exact reason the earlier `retrieved`-listener approach was rejected
(a ratchet that passed alone but failed in-suite is a false green). `booted()` was also rejected:
it fires once per class, so only the first instance would ever be armed.

Applied to **all 25 Eloquent models** (23 extending `Model`, `User`/`Employee` extending
`Authenticatable`); none overrode `newInstance`/`newFromBuilder`. A cohort test fails if a future
model omits the trait (its guard would silently stay inert).

**Verification — `RV38LazyArmingMechanismTest` OK (4 tests, 11 assertions):** with the static flag
turned ON inside the test: (1) a **single-row `User::find()`** — the path the framework left
unguarded — is armed AND a genuine `->profile` lazy load **throws** `LazyLoadingViolationException`
(this is the throw that the old approach could never produce); (2) every multi-row hydrated row is
armed; (3) with the flag off, the mechanism is a no-op and lazy loads still resolve normally.
**Causality needle:** removing the trait from `User` made 2 tests fail (the single-row throw + the
cohort check) — the mechanism is load-bearing and its absence is caught, not silently tolerated.
File restored MD5-identical, final run green. (One flaky assertion — a global `User::all()` count —
was corrected to scope to the test's own rows; the arming check was never affected.)
**Full-suite gate: 2051 tests, 52 errors / 68 failures, 0 regressions** — the trait touches every
model, and nothing changed behaviourally because the flag stays off.

**To enable RV-38's third flag now:** uncomment `Model::preventLazyLoading()` in
`AppServiceProvider::boot()` (next to the other two flags). §35 established there are no current
lazy-load regressions; the arming is now correct and durable. **Recorded, not flipped**, so the
default runtime behaviour is unchanged until that explicit decision.
## 37. RV-38 part 3 - flag-off / flag-on scoped measurement: option A as written does NOT reach 0 - MEASURED (flag OFF); enabling still refused

Documentation-only entry. No code was changed and **the flag was not enabled** in this round:
the lazy-loading strictness flag is still OFF, so runtime behaviour is unchanged from section 36.
This records the measurement that decides whether section 36's one-line enablement is safe.

**Scope.** A scoped run, not the full suite: the ride/wallet/profile areas plus the review
ratchets. Two runs, identical selection, the only difference being whether
`Model::preventLazyLoading()` was armed.

**Results.**

| Run | Tests | Errors | Failures | vs baseline |
|---|---|---|---|---|
| Flag OFF (scoped baseline) | 267 | 0 | 10 | - |
| Flag ON | 267 | 1 | 28 | +1 error, +18 failures |

So enabling the flag adds 1 error and 18 new failures on the same 267 tests.

**Residual lazy loads actually observed with the flag ON** (the violations that survive a fix
limited to the 3 `RideResource` render sites):

- `[driver]` on `Ride` - x7 - at `EPayPaymentStrategy` line 53
- `[wallet]` on `User` - x3 - at `CashRideFeeService` line 79
- `[profile]` on `User` - x1 - at `RideSearchServiceTest` line 330

**Conclusion: option A as written does not reach 0.** The 3 uncommitted eager-load edits in
`RideController.php` cover the `RideResource` render path only. The residuals above sit in the
payment strategy, the cash-fee service and a search service test - different code paths from the
ones the 3 sites touch. Arming the flag on the strength of option A alone would therefore leave
the strictness flag tripping on real lazy loads, i.e. it would not produce a green scoped run.

**Unverified inference - recorded as an inference, NOT as a finding.**
The statement "the 3 sites do not address these" is an **unverified inference**. It is reasoned
from the locations named in the residual list (payment strategy, cash-fee service, search-service
test) versus the controller render sites - a location comparison only. It was **not** verified by
per-site causality testing: no isolation was run that re-measures each residual with the flag ON
and the corresponding site individually patched or reverted. The residuals are measured; the claim
that the 3 sites cannot cover them is not. Treat the inference as open until a causal per-site run
confirms it.

**Disposition.** RV-38 part 3 stays parked: flag OFF, option A not adopted as written, and the
enablement is an owner decision given the numbers above (section 30 still lists RV-38 flag
prerequisites as owner-blocked). Nothing here changes the two verified data-integrity flags of
section 29.2 or the `GuardsLazyLoading` mechanism of section 36, which remains correct and
available whenever the decision is made.

---

## 38. RV-39 seeders (guard, rename, truncate closure, shared ledger vocabulary, wallet phones) — **VERIFIED FIX**

**Original finding** (`BACKLOG.md` Order 56, this file sec 3, confirmed on disk 2026-10-03 by the
BACKLOG reconciliation): the four state-forging seeders had no production guard
(`db:seed --class=... --force` ran them against a live database); `Syrideseeder.php` declared
class `SyrideSeeder` (PSR-4 autoload breaks on case-sensitive Linux without a classmap dump);
`truncateTables()` omitted `noshow_reports` / `refresh_tokens` / `otps`, orphaning their rows under
`FOREIGN_KEY_CHECKS=0`, and created a private SyCash wallet (+963999000001, `SYR-ESCROW-001`) that
no service reads while creating no Primary wallet at all; the ledger rows were hand-written in a
third vocabulary (`ride_payment`, `escrow_hold`, `ride_creation_fee_received`, ...) invisible to
the admin reports; `DriverSeeder`/`PassengerSeeder` gave every one of their 10 wallets the same
`COMM_NUMBER` although `wallets.phone_number` is UNIQUE (the second `Wallet::create` threw).

**Root cause.** Same pattern as T1-2 (writer/reader drift) plus three single-source-of-truth
failures: truncate list, wallet phones, and the guard seam. Seeders were never covered by AF-4f
because that guard lives at Symfony's `execute()`, which the seeder path (`SeedCommand ->
Seeder::__invoke -> run()`) never passes through.

**Fix applied.**
- `database/seeders/RefusesProduction.php` (new trait): `refuseProduction()` throws while
  `app()->environment('production')`; called as the FIRST statement of `run()` in SyrideSeeder,
  BulkRideSeeder, Atarikaktestseeder, UserRealFlowSeeder (a trait cannot wrap the framework's
  `__invoke`; run() is the only seam every path — `db:seed`, `$this->call()`, direct `run()` —
  shares). Deploy seeders (SpecialAccountSeeder, SystemAdminSeeder, SystemWalletSeeder) are
  deliberately NOT guarded — they are the idempotent production-bootstrap creators, pinned both
  ways by the new test.
- `git mv Syrideseeder.php -> SyrideSeeder.php` (rename tracked as R in git); whole directory
  swept by filename==declared-name + composer-psr-4-prefix test.
- `SyrideSeeder::TRUNCATE_TABLES` (public const): adds `noshow_reports`, `otps`, `refresh_tokens`,
  `password_reset_tokens`; completeness is not hand-maintained — the test derives the closure rule
  from `information_schema.KEY_COLUMN_USAGE` on the migrated scratch MySQL, so a new FK into a
  truncated table fails the suite until the list grows with it (`employees` is the deliberate,
  pinned parent-preserved exception).
- `app/Enums/LedgerType.php` (new, shared kernel): the live `wallet_transactions.type` vocabulary,
  one case per value with writer annotations. SyrideSeeder's six ledger writes now pass enum cases
  (`ride_payment -> RIDE_BOOKING_PAYMENT`, `escrow_hold -> ESCROW_RECEIVED`, the release pair to
  `ESCROW_RELEASE`/`RIDE_EARNING` — the same names `WalletTransactionService` writes — and
  `ride_creation_fee_received` lands on the PRIMARY wallet with `RIDE_CREATION_FEE(_RECEIVED)`,
  mirroring where `CashRideFeeService` books fees). A ratchet test scans every
  `WalletTransaction::create` in `app/` and the seeders (comment-stripped, paren-balanced block
  parse, including `$var` tracing) and fails if any written value is not a `LedgerType` case.
  Services still pass literals; converting them is AF-6's `LedgerEvent` job — the enum pins the
  VOCABULARY, not the call style. Cases are annotated where they are migration-vocabulary-only
  (`ride_creation_fee*`: no app writer today) or orphaned (`withdrawal`: one writer, zero readers
  — recorded, AF-6 decides).
- `resolveSystemWallets()` (replaces `resolveSycashWallet()`): delegates to
  `SystemWalletSeeder::run()` (keeping the RV-21 loud-failure semantics) then resolves SyCash and
  Primary by the `config/admin.php` phones — the exact rows `AdminReportService` sums, so a seeded
  database reconciles on the dashboards instead of reading 0.00. Phantom literals gone; the dead
  `sycashEmployee` property (assigned, never read) removed with it.
- `DriverSeeder`/`PassengerSeeder`: `WALLET_PHONE_BASE` (+2-digit suffix) replaces the shared
  `COMM_NUMBER`; distinct bases (...72 / ...73) keep both runnable in one database. Proven by
  running BOTH through the real `artisan db:seed` entry point: 20 users, 20 wallets, 20 distinct
  phones. (The old collision itself is inferred from `wallets.phone_number` UNIQUE + ten identical
  values, not re-executed: the baseline worktree run had no test that seeds these two.)
- `tests/Feature/T3Batch/SeedCredentialsBatchTest.php`: its pinned seeder path string follows the
  rename (path only; no assertion weakened).

**Files changed.** `database/seeders/RefusesProduction.php`, `SyrideSeeder.php` (renamed from
`Syrideseeder.php`), `BulkRideSeeder.php`, `Atarikaktestseeder.php`, `UserRealFlowSeeder.php`,
`DriverSeeder.php`, `PassengerSeeder.php`; `app/Enums/LedgerType.php`;
`tests/Feature/Review/RV39SeederHygieneTest.php` (new, 14 methods running as 23 tests / 130
assertions with the guard/data-provider legs);
`tests/Feature/T3Batch/SeedCredentialsBatchTest.php` (path string).

**Tests / checks run** (all DB commands scratch-pinned, `scripts/db-ping.ps1` OK at 127.0.0.1:3399):
- `php -l` + `pint --test` clean on every changed file; static gate of `scripts/test-related.ps1`
  green (12 changed PHP files).
- `scripts/test-related.ps1 -File tests/Feature/Review/RV39SeederHygieneTest.php` (adds
  BoundaryDependencyTest by rule 3 since `app/` gained a file): **OK (23 tests, 130 assertions)**.
  Includes both guard directions through the REAL seam (probe seeder records `'entered'` vs `'ran'`
  so the refusal is proven at the guard, not at the framework's own `confirmToProceed` gate —
  `--force` is passed, which is the audited bypass shape).
- Full diff-derived scoped selection (25 files, 332 tests): `Errors: 9, Failures: 18` — and the
  red set is the SAME 27 named tests at HEAD: a baseline worktree run of the identical selection
  without any RV-39 change produced `Tests: 318, Errors: 9, Failures: 18` with a
  `Compare-Object` diff of failure names showing **zero regressions and zero newly red**; the 27
  match the pre-recorded inventory families (sec 18.5: WalletRequestControllerTest 14 = family 1
  `wallet_id NOT NULL` schema question; RideControllerFullTest 8 = family 7; WalletTest 3 = its
  established baseline per sec 34/37 notes; RideTest 2 = families 5/7 + fee-expectation).
- Causality (truncate closure): a probe FK from an extra table into `users` would make test
  `test_truncate_list...` name it — the check is derived from the live schema, not a copied list.

**Final state: VERIFIED FIX.**

**Genuinely unverified / not done (explicit):**
1. The physical `truncateTables()` call is NOT executed inside the RefreshDatabase test: TRUNCATE
   is DDL and implicitly commits, which would break test-transaction isolation (and, on a real
   DB, the harness pin — the scratch gate is what protects that, per AGENTS.md). Proof is
   structural (list == FK-closure over the live migrated MySQL schema) + per-table existence.
2. No test runs the full 1,000-user `SyrideSeeder::run()` to completion (spatial SQL + minutes of
   runtime); its internals are tested individually (guard, resolveSystemWallets, truncate list,
   vocabulary) and the ledger vocabulary is proven by construction (enum-typed helpers).
3. R2 sec 3's prose suggestion "make seeders drive RideService/BookingService" was NOT adopted:
   bulk raw inserts are the point of BulkRideSeeder (500k rows), and it is not part of the
   BACKLOG sec 4 acceptance set. The ledger rows it writes are now readable by the reports,
   which is what the acceptance criterion asked.
4. The case-sensitive-Linux autoload failure itself is not observable on this Windows checkout
   (NTFS is case-insensitive); the test asserts the STORED filename byte-case via
   `[IO.Directory]::GetFileSystemEntries` and the ReflectionClass file identity instead.
5. `LedgerType` covers only values with a live writer/reader plus the two annotated exceptions; the
   migration-dialect values nobody writes (`ride_booking_received`, `no_booking_refund`,
   `full_creation_fee_refund`, `driver_self_cancellation_refund`) are NOT in the enum — deleting
   dead vocabulary is AF-6's decision, not this task's.
6. Incidentals this task surfaced and did NOT change: the scoped runner feeds every changed
   `tests/*.php` file to PHPUnit, so a standalone non-TestCase fixture under `tests/` aborts the
   whole selection (the probe is therefore declared inside the test file; `scripts/test-related.ps1`
   itself is owner-owned staged work — untouched); a Windows file-permissions repair was applied to
   the workspace root mid-session (recovery files under `C:\wamp64\www\.acl-recovery-4th_year`,
   outside the repo).

---

## 39. RV-37 continued — the committed-leak root cause IS FOUND AND FIXED; order-independence measured at suite level — **VERIFIED FIX (order half); hermeticity half still open**

**Original finding (sec 3 / sec 23).** The suite reported different failures depending on run
order (V14: 68 default vs 85 under the V14 seed at the time of sec 23.6). Sec 23.6 ruled out
`putenv()`, `DatabaseMigrations`, in-test `migrate`/`DB::commit`, and `MigrationEffectsBatchTest`,
then stated: "every `Tests\TestCase` subclass has a database trait (`RefreshDatabase`,
`DatabaseTransactions` or `DatabaseMigrations`) — **0 classes without one**". It left open: "which
class writes rows outside the per-test transaction".

**That 0-count ruling was WRONG — found by measurement, not inspection.** 58 concrete test classes
extend `Tests\TestCase` with no database trait. Most never touch MySQL (pure unit tests over
enums/value objects), but **five persist rows through the autocommitting default connection**, so
their rows are COMMITTED for the rest of the PHP process and every later count-style assertion in
the suite sees them. Measured per-file against the scratch DB (clean → run one file → count rows
that survived the process):

| Class | Committed after one isolated run |
| --- | --- |
| `Feature\RateLimiting\RateLimiterIdentityKeyTest` | 2 users |
| `Feature\Review\CreateRideRouteTest` | 2 users |
| `Feature\Review\RV29UserMassAssignmentRatchetTest` | 1 user |
| `Unit\Providers\EventServiceProviderTest` | 1 user |
| `Unit\Providers\RouteServiceProviderTest` | 1 user |

**7 committed users per full-suite pass** — exactly the phantom drivers `AdminDriverServiceTest`'s
aggregates counted in sec 23.2/23.6 (the mechanism there was real but partial: the replica read
path was one amplifier, these five were the source).

**Fix applied.**
- `use RefreshDatabase;` on all five classes. All five use factory-created users only as fixtures
  for in-test assertions (bucket keys, provider maps, listener notifications, `$fillable` proof),
  so transactional wrapping preserves every assertion.
- A THIRD ratchet in `tests\Feature\Review\TestDeterminismRatchetTest.php`:
  `every_test_class_that_writes_to_the_database_is_transactional` — reflection over every concrete
  `Tests\TestCase` subclass under `tests/` (excluding `tests/Support/`, abstracts, and
  non-subclasses); a class with no `RefreshDatabase|DatabaseTransactions|DatabaseMigrations|
  DatabaseTruncation` trait anywhere in its `class_uses_recursive` closure fails if its
  comment-and-string-stripped source contains a DB-write shape (`factory(`, `->save(`, `->push(`,
  `assertDatabaseHas|Missing|Count`, `DB::table|insert|update|delete|statement|transaction`,
  `Schema::`, `RideBuilder::build`, or `<Model>::create(` for a model in an explicit list).
  The bare `::create(` shape was dropped after the first run flagged three non-writers —
  `HorizonAccessTest`/`EnvironmentGuardsBatchTest` call `Request::create()` (Symfony, builds no
  row) and `NoDuplicatedFixtureHelpersTest` matched a docblock phrase — the model-name gate is the
  correction, the list still fires on a real `Wallet::create(` style write (needle-tested below).

**Falsifiability (both directions).**
- Needle 1: remove `use RefreshDatabase;` from `CreateRideRouteTest` → the ratchet fails naming
  exactly that file (`factory(`); file restored MD5-identical (`42DB1DEC…`) and re-green.
- Needle 2 (mechanism causality): same file, same command, without the trait the isolated run
  leaves **2 committed users**; with it, **0**. The trait is what stops the leak.

**Suite-level verification — the full suite was run, because this task's acceptance criterion IS
suite-level ordering (AGENTS' scoped rule's own exception: "never run the full suite" cannot
measure order independence). All on scratch MySQL 127.0.0.1:3399 only.**

| Tree | Order | Errors / Failures / Skipped | Committed rows left |
| --- | --- | --- | --- |
| fixed | default | 52 / 68 / 7 (2066 tests) | **0** |
| fixed | random seed 20260929 (the V14 seed) | 52 / 68 / 7 | **0** |
| fixed | random seed 424242 | 52 / 68 / 7 | **0** |
| fixed | random seed 777001 | 52 / 68 / 7 | **0** |
| HEAD (pre-fix, same local files) | default | 52 / 68 / 7 (2065 tests) | 7 users |
| HEAD (pre-fix, same local files) | random seed 20260929 | 52 / 70 / 7 | 7 users (+ leaked notification rows) |

- Name-level equality: the red set on the fixed tree is **120 failing tests, byte-for-byte the same
  list under default order and all three random seeds** (multiset `Compare-Object` diff = ∅ for
  every seed). At HEAD, under matched local-file state, default order = 120 red but the V14 seed =
  **122**: the two extras are `Models\NotificationTest::test_recent_scope_accepts_custom_day_count`
  and `..._returns_notifications_within_7_days` — count-style assertions on the `notifications`
  table, the exact leak victims (sec 23.6 predicted "count-style assertions"; the victims also
  live in `Unit/Models`, not only `AdminDriverServiceTest`). Both are green on the fixed tree in
  every order. That is the acceptance criterion met: the failure set under random order equals the
  default-order failure set.
- Causality: at HEAD the V14 seed still produced extra failures (70 vs 68) and left the 7 committed
  users plus leaked notification rows; the fixed tree is flat 68 in every order with zero residue.
  The 2066-vs-2065 total difference is the new ratchet method itself.

- No regression in money/identity: the money floors (`tests/Feature/Wallet`, `Payment`,
  `Unit/Domain`) and `BoundaryDependencyTest` all ran inside the full suite; failure-name diff
  fixed-vs-HEAD = ∅.
- The earlier "68 vs 85" (sec 23.6) and today's "68 vs 70" differ because `AdminDriverServiceTest`
  fixtures changed state between those measurements; the comparison recorded here is same-commit,
  same-config, name-level — that is the causality, not the historical number.

**Environment caveat this task uncovered (NOT fixed — separate problem).**
`Unit\Jobs\SendPushNotificationTest`'s two log-count tests are sensitive to the gitignored local
file `storage/app/firebase/service-account.json`: with the file present they fail (the real
`PushNotificationService` reaches its FCM branch and logs), without it they pass. The HEAD worktree
lacks the file, so the first HEAD-vs-fixed comparison flagged these two as "flipped" — they are an
environment artifact of the developer's working tree, already listed as a stale-stub family in
sec 19.2 item 8, and unrelated to the trait fix (my changes stashed, with the file copied in, HEAD
fails them identically). Recorded so nobody re-attributes them to RV-37; the matched-state
comparisons above are the honest ones.

**Files changed (this task).** `tests\Feature\RateLimiting\RateLimiterIdentityKeyTest.php`,
`tests\Feature\Review\CreateRideRouteTest.php`,
`tests\Feature\Review\RV29UserMassAssignmentRatchetTest.php`,
`tests\Unit\Providers\EventServiceProviderTest.php`,
`tests\Unit\Providers\RouteServiceProviderTest.php` (trait + a short RV-37 note in each docblock),
`tests\Feature\Review\TestDeterminismRatchetTest.php` (the ratchet); docs record.

### 39.1 Hermeticity half — `Http::preventStrayRequests()` armed, Guzzle-direct seams closed, suite unchanged

The order half above is closed; this closes the other half of sec 23.4.

**What the guard covers, measured not assumed.** `Http::preventStrayRequests()` (added to
`TestCase::setUp`) intercepts only the `Http` **facade**. A grep of `app/` shows the facade is used
by exactly three services — `RouteCalculationService` (OpenRoute), `GeocodingService`,
`ArabicPlaceNameService` — while `WhatsAppOtpService`, `TextMeBotOtpService` and
`GoogleController` construct a **Guzzle client directly** and are invisible to it. With the guard
armed, the full suite produced **zero** stray-request failures: no test had been relying on a
facade request escaping to the network (V13's finding — "no test calls a live provider without
`Http::fake()`" — is confirmed at suite scale).

**The hole the facade cannot see was real, and is closed where it can be.** Those three Guzzle
paths are all credential-gated (`if (! empty($this->apiKey))`). The developer's `.env` carries
live values for exactly those keys (`TEXTMEBOT_API_KEY`, `CHATDADDY_API_KEY`, `GOOGLE_CLIENT_ID`,
`GOOGLE_CLIENT_SECRET` — presence checked, values never printed), so any test that reached a
configured branch could have left the process. Fix: `CreatesApplication::createApplication()`
nulls those keys (and forces `textmebot.enabled => false`) for the whole test process, in
test-owned code. Neither `phpunit.xml` nor `.env` is touched — both are owner-owned, never-committed
local files that pin nothing about these keys. A test that needs a configured branch sets the key
itself with `Config::set` for that test only (the pattern RV-22 already established).

**One real regression found and fixed during this phase — recorded because the measurement caught
it, not because it was predicted.** With the api_key nulled but `enabled` left as `.env` had it
(`TEXTMEBOT_ENABLED=true`), `TextMeOtpControllerTest::test_send_otp_fails_when_textmebot_disabled`
turned from 400 (the controller's "provider disabled" branch) into 200 (it fell through to the
service, which no longer had a key to send with). The neutralisation therefore sets the enable-flag
false as well, which is both correct for a hermetic suite and restores the branch the test asserts.

**Falsifiability.**
- Needle A: remove `Http::preventStrayRequests()` from `TestCase` → the ratchet fails naming it.
- Needle B: remove the credential neutralisation from `CreatesApplication` → the ratchet fails with
  the developer's `.env` key visible in `services.textmebot.api_key` (i.e. the hole demonstrably
  existed). Both files restored MD5-identical after each needle.
- The first version of the ratchet was itself defective and the needle caught it: it matched the
  substring `preventStrayRequests` anywhere in `TestCase.php`, and the docblock explaining *why*
  the guard is there satisfied that check even with the call deleted. It now strips comments and
  matches the actual call `Http::preventStrayRequests();`. (Same class of self-defeating assertion
  as sec 23.3's `in_array`/allow-list bug — worth remembering that a ratchet must be run with its
  own needle before it is trusted.)

**Suite-level result.** Full suite with everything in place: **2067 tests, 52 errors / 68 failures
/ 7 skipped**, committed residue 0, zero stray failures. The 120-entry red set is **name-for-name
identical** to the pre-change baseline (the +1 test is the new ratchet method). Money and identity
floors ran inside that pass; no assertion was weakened.

**State after 39.1: hermeticity half VERIFIED FIX.** RV-37 as a row stays PARTIAL only because of
the CI double-run-with-seed (which belongs to RV-18) and the un-ratcheted "no test writes a
tracked file" clause.

### 39.2 The last two decision-free clauses: tracked-file ratchet + CI double-run

**"No test writes a tracked file" - now a ratchet.** `TestDeterminismRatchetTest::
no_test_writes_a_tracked_file` scans every PHP file under `tests/` (comments stripped) for global
filesystem-write primitives (`file_put_contents`, `fwrite`, `fopen`, `touch`, `unlink`, `mkdir`,
`rename`, `copy`, ...). The single existing writer is allow-listed with its reason
(`RV22OauthCredentialRedactionTest` - its subject IS a log file, written under `storage_path()`,
which `.gitignore` excludes, and unlinked in a `finally`). The file-write shapes are matched with a
word-boundary **global-call** regex, not `str_contains`: the first draft flagged five innocent files
because "touch" and "rename" appear inside Eloquent's `->touch()`, the word "untouched" in an
assertion message, and prose in a docblock. Needle: injecting a real
`touch(base_path('README.md'))` into `RideTest.php` fails the ratchet naming that file; the file was
restored MD5-identical.

**CI double-run-with-seed.** `.github/workflows/sonar.yml` now runs the suite a second time under
`--order-by=random` with `--random-order-seed=$(date +%s)` echoed into the log. Placement is
deliberate: AFTER the SonarQube scan, because a failing step stops the ones after it and this gate
must not be able to suppress the coverage upload. It carries the same process-level `DB_*` env the
primary run uses (RV-18's mechanism) and no `|| true` (the masking pattern T3-11 removed). The
command was exercised locally in its exact shape: seed 758619 -> 52E/68F, zero committed residue - a
fifth distinct random order confirming order-independence (default, 20260929, 424242, 777001,
758619).

**State after 39.2: every acceptance criterion in BACKLOG sec 4 that does NOT require an owner
decision is now met.** What remains on RV-37 is nothing executable without a ruling: the CI wiring
lives in the same repo (done here), and the residual `V13`/`V14` narrative is historical. The row
may be closed by the owner; this section closes the work, not the row's status field.

### 39.3 CI executed for the first time on this branch - and was already broken before any of this work

The owner authorised pushing `Agentic` (branch only; `main` untouched). GitHub Actions ran on
`eaa7f14` for the first time on this branch, and the result is the reason the criterion could not be
closed blind:

| Workflow | Result | Where it stopped |
| --- | --- | --- |
| gitleaks | **success** | - |
| Style (Pint) | failure | `Install dependencies` |
| Architecture gate | failure | `Install dependencies` |

Neither failing job ever reached a test or a lint check. Both die in `composer install`, because
`post-autoload-dump` runs `artisan package:discover`, which **boots the application**:

- **`pint.yml`** wrote no `.env` at all, so `APP_ENV` defaulted to `production` and the JWT boot
  guard (`AppServiceProvider::guardJwtSecret`, added for the T3-11 work) threw
  *"JWT_SECRET must be set to at least 32 bytes outside local/testing environments; it currently
  resolves to an EMPTY value"* - `exit 1`.
- **`architecture.yml`** writes `.env` (with `APP_ENV=testing` and a fresh `JWT_SECRET`) but never
  sets a broadcast driver, so `config/broadcasting.php`'s `env('BROADCAST_DRIVER', 'pusher')` default
  resolves to pusher and `package:discover` dies with
  `Pusher\Pusher::__construct(): Argument #1 ($auth_key) must be of type string, null given`.

**This is pre-existing and not caused by any commit in this audit.** The same two workflows fail
identically on `c92e1e2` (before this session) and on `26fbdb3`; the failing step is `Install
dependencies`, which nothing in this record touches. The audit-branch CI has therefore never been a
gate in practice - `gitleaks` is the only workflow that has ever passed here.

**Fixes applied (CI configuration only, no application code, no secrets).** `pint.yml` now sets
`APP_ENV: testing` + a dummy `JWT_SECRET` on the install step; `architecture.yml` adds
`BROADCAST_DRIVER=null` to the `.env` it generates. Both state what the job is; the JWT guard stays
armed for real deployments. Once install passes, `vendor/bin/pint --test` surfaced **3
pre-existing style violations in files this audit never touched**
(`ResetPasswordControllerTest`, `SignupPasswordOverwriteTest`, `CiMySqlDriverTest`) - also auto-fixed
(`ordered_imports`, `single_quote`, `php_unit_method_casing`, operator spacing), semantics
preserving, and the three files' tests still pass (30 tests, 66 assertions, 2 announced skips).
Repo-wide `pint --test` is now **PASS, 537 files**.

**Still not exercised by CI: the suite.** `sonar.yml` triggers on `push: main` / `pull_request:
main`, so pushing `Agentic` cannot run it - by the owner's instruction the branch is the only push
target. The order-independence step of 39.2 therefore remains unexecuted *by CI*, verified instead by
executing its exact command locally (5th seed, sec 39.2). Closing that gap needs a PR into `main`,
which is a separate owner call.

### 39.4 CI run 2 and 3 — the fixes worked, and CI caught a regression I had introduced

**Run 2 (`7cc8939`).** `pint.yml` still died at `Install dependencies`, but with a *different* cause
than run 1: supplying `APP_ENV=testing` had got past the JWT guard and the next boot requirement
failed instead - `Pusher\Pusher::__construct(): Argument #1 ($auth_key) must be of type string, null
given`, because `config/broadcasting.php` defaults to `env('BROADCAST_DRIVER', 'pusher')`. That is
the lesson worth keeping: pinning one env key per attempt treats a symptom, not the cause. `pint.yml`
only needs `vendor/bin/pint`, so its install now runs `--no-scripts`, which skips the application
boot entirely and removes the class of coupling rather than one instance of it.

`architecture.yml` reached its test step for the first time ever. Its 10 failures were all
`MissingAppKeyException` in `GoogleOauthTokenTest`; the log says why:
*"Unable to set application key. No APP_KEY variable was found in the .env file."* - `key:generate`
had no `APP_KEY=` line to replace, printed that, and exited 0, so the step "passed" and the key never
existed for the tests. Locally this is invisible because the developer's `.env` carries a key.
Fixed by adding `APP_KEY=` to the generated `.env` (alongside the `BROADCAST_DRIVER=null` from run 2).

**Run 3 (`8421e72`).** `architecture.yml` went green suite-by-suite (20, 40, 16, 23, 10, 10, 12, 11,
9, 12 ... all passed) and failed on exactly **one test** — and it was a regression **I** introduced:
Pint's `php_unit_method_casing` renamed the private helper `testFileExists()` to
`test_file_exists()` in `CiMySqlDriverTest`, but not its call site, so the test errored with
`Call to undefined method`. That class is inert locally (skipped unless `CI_REQUIRE_MYSQL=1`), so no
scratch run of this task ever executed it; the CI run did. Fixed the call site and verified with the
CI condition set: **OK (2 tests, 10 assertions)**, with repo-wide `pint --test` still PASS (537
files). Recorded because it is the honest shape of this task: the local verification I repeated five
times could not see it, and one CI run could.

**State after 39.4: the two workflows that gate this branch are now runnable and green** (gitleaks
has always been; Pint and Architecture are fixed and validated by their own CI runs).

**Security finding, recorded not acted on.** `origin`'s URL embeds a GitHub personal access token in
plaintext (`.git/config`), and that token was printed into this session's transcript when the remote
was inspected. It should be rotated, and the remote re-set to a token-free URL (credential helper or
a token supplied at push time).

**State after 39.3: RV-37's code and audit work is complete and CI-blocking defects that were
outside this task are fixed. The row is closed by the owner's decision recorded in BACKLOG row 25.**

**Genuinely unverified / not done (39.3).**
1. The suite workflow (`sonar.yml`, and with it the 39.2 order-independence step) has still never
   executed; it needs a PR into `main`.

**Genuinely unverified / not done (39.2).**
1. The CI step is verified by local execution of its exact command and by the YAML parsing, but it
   has not run on GitHub Actions (no push, by protocol). First CI execution will confirm it.
2. The tracked-file ratchet is a source scanner; a write performed by an app/service under test (not
   literal in the test file) that lands on a tracked path is not detected. The clause is a
   best-effort ratchet, stated as such.

**Genuinely unverified / not done (whole task).**
1. ~~`Http::preventStrayRequests()` was NOT enabled~~ — CLOSED in 39.1 above.
2. The ratchet is a static-shape detector: exotic writes it does not name (a raw
   `->getConnection()->insert(...)` without the `DB::` facade, or a service call that happens to
   write) would slip past; the five known leak shapes are pinned, future exotic ones are not.
3. Order-independence was measured on 5 orders (default + 4 random seeds). A permutation no seed
   produced could still leak; the leak SOURCE (committed writes outside a transaction) is what was
   removed, and its residue is now 0 in every run measured.
4. HEAD-vs-fixed numbers come from two trees (worktree without `storage/logs` rotation state of the
   main tree); the matched-firebase A/B above is the same comparison under identical local-file
   state for the one test family that reads it.
5. "No test writes a tracked file" is ratcheted as of 39.2 (it was hand-verified only before).

---

## 40. OWNER DECISION SLATE — all 27 audit decisions answered in one session (2026-10-02)

The owner was walked through every outstanding decision one at a time (see the transcript in the
session). This section is the **authoritative record** of what was decided, in the owner's words
where it matters. Three buckets: **DECIDED-DO-NOW** (execute as their own task), **FLAGGED-
RECONSIDER** (the for-now choice stands; revisit later), **LATER/BLOCKED** (not this batch).

### 40.1 DECIDED-DO-NOW

| Decision | Choice | Owner's stated reason / constraint | Work it creates |
| --- | --- | --- | --- |
| `un10` wallet-before-topup | **A** — a user must have a wallet before requesting a top-up; the schema is right, the fixtures were wrong | "wallet required first is correct behaviour" | Fix the ~20 `wallet_id` fixtures (NOT the schema) |
| `un9` lazy-loading flag | **B** — turn it on | "I want performance to be good and quality good even if that means more work" | Fix the 19 sites, THEN arm the flag (not flip over 19 failures) |
| `un8` Larastan | **A** — add report-only (no gate) | "needs work after we finish these tasks completely" | Add Larastan report-only; cleanup is a follow-up task |
| `un5` `finish` / `driver-confirm` | **C** — **delete** them | "I don't want someone implementing them because they don't know the flow" (passengers confirm individually; money moves per-passenger; ride auto-finishes when all bookings are terminal — already implemented) | Delete both endpoints + their 10 stale test call-sites |
| `2` auto-confirm window | **B** — expire unconfirmed bookings, do NOT auto-confirm | marked "important to be reconsidered" | Implement expiry + an escrow-release rule for expired bookings |
| `11` KYC document gate | **A** — gate the ACTIONS, not the account | "the user can still search and everything even if he is not verified but he cant make or book rides" | Unverified: can browse/search; cannot create or book rides |
| `6` staff-initiated cancel | **A** — full passenger refund, no driver score penalty | (staff cancellation is rare; make it non-ad-hoc) | Define the staff-cancel refund + penalty in code |
| `un11` auth cache DTO | **A** — full refactor | "I want performance/quality good" | Cache a safe DTO (no password hash) + fix the auto-lift hazard |
| `1b` KYC streaming route | **A** — staff-only route | (close the public-URL IDOR) | Add staff-authenticated document streaming (frontend-coordination item) |
| `7` deploy target | **B — Render** | "we moved to Render to save the cost of the subscription" | Rewrite deploy for Render; the VPS workflow becomes obsolete |
| `un12` k6 harness | **A** — proper `setup()` harness | (trustworthy perf numbers) | Build k6 `setup()` that seeds/logs-in + a 3-run perf spec |
| `un1` object storage | **A — MinIO** | "if it doesn't require payments and so on, free" | MinIO (self-hosted, free) — AF-5 storage |
| `13` status/type columns | **A** — PHP enums, drop DB ENUMs | (PHP enums are already the source of truth in code) | Migrate the 17 DB `ENUM` columns to varchar |
| `un13` account-status | **A** — clean `status` + `BanService` | (root cause of ban bugs) | Refactor account status + migration |
| `un3` money foundation | **A** — kind + double-entry + thresholds | "mark it needs more explaining" | `wallets.kind`, double-entry ledger, adjustment thresholds **+ a written explanation of the money model** |

### 40.2 FLAGGED-RECONSIDER (for-now choice stands; revisit later)

| Decision | For-now choice | Note for the revisit |
| --- | --- | --- |
| `3` cancellation money policy | **A — UNCHANGED** | "highest priority to check later". Cancellation tiers stay: passenger refund by elapsed %, driver gets the remainder, platform 5% only on completion/acceptance/no-show. Two facts found while tracing: (i) `BookingService:443` comments say the 5% goes to SyCash but the code credits **Primary Admin**; (ii) on passenger cancellation the platform takes **0%**. |
| `9` access-token TTL | **B — keep 600 min** | "worth noting to be considered later". Only shorten once the client silently refreshes, or riders get logged out mid-ride. |
| `10` `users.phone` | **B — no** | "to be reconsidered in future". Phones stay on wallet + booking (one source of truth each). |
| `4` phone-OTP endpoints | **B — keep** | "should be reconsidered". Two public OTP route groups stay. |
| `14` cancel routes | **A — keep both** | "to be reconsidered". |

### 40.3 LATER / BLOCKED (not this batch)

- **`un4` error envelope + `/api/v1` — Option C.** "Don't change now till I get the frontend repo or
  I discuss it with the frontend team." Measured: the API emits **three** error dialects today
  (`status:error` ×153, `success:false` ×70, plus Laravel's built-in), so a blanket change would
  break the live client. Standardise **new** endpoints only. Related frontend-coordination items:
  `un4`, `un5` (deleted routes), `1b` (KYC route) — one coordinated pass with the app team.
- **`5` driver-phone visibility — DEFERRED by the owner.** "This needs more thinking." The API and
  the booking broadcast both expose `communication_number`, so an API-only fix is incomplete; also
  needs the client (it may read the number from a list, not the ride).
- **`un7` revenue model — A, verify later.** Owner definition: **revenue = Primary Admin wallet
  balance** (the 5% cut, minus cash-fee refunds it pays out); **SyCash = escrow, balance must be 0
  when no rides are in flight**. Already implemented in code; the SyCash-zero invariant is
  **described but never asserted** — a test for it is a later task.
- **`12` stub notification classes — MOOT.** The stubs were deleted under AF-4 (a test pins they
  stay gone); the two remaining push classes are live. No action.
- **`un6` complaint policy — NO CHANGE.** All three parts verified already-correct: both-party
  no-show → auto `no_show` complaint (manual human handling); staff opening a complaint
  auto-transitions `pending→in_review` + assigns it (the owner's **intended transparency feature**,
  `StaffComplaintController::show` → `openComplaint`); the **public** complaint form cannot file
  `no_show` (validated in `ComplaintController::store`).

### 40.4 Notes for whoever executes
- The owner's `un2` answer (score: **start 70, max 100**, bands **80/60/40**, gates **50** create
  rides / **40** book) **matches the existing code** except one real bug: `ScoreService::resolveTier`
  writes a `tier` column using a **200/150/100** scale against a 0–100 score, so every user is
  permanently stored as `bronze`. Fix: point the tier writer at the model's real bands and pin all
  of it with tests.
- `un5`'s deletion removes 6 of the 8 recorded `RideControllerFullTest` failures; the recorded red
  baseline shifts and must be updated so the next session doesn't read it as a regression.


---

## 41. un10 (wallet-before-top-up) - fixtures corrected, schema untouched - **VERIFIED FIX**

**Owner decision (A, 2026-10-02, sec 40.1):** "a user must have a wallet before requesting a
top-up" is correct product behaviour. `wallet_requests.wallet_id` therefore STAYS `NOT NULL` + FK.
The audit's sec 19.3 premise - that the column "should be nullable because a top-up is precisely
the case where no wallet may exist yet" - is **refuted by the code**: `WalletRequestService::
requestCharge` / `::requestWithdraw` both refuse with 422 when `$user->wallet` is missing, so the
product never creates a request without a wallet, and the admin approve path credits
`$request->wallet`. **No migration, no service, no controller, no money path touched.**

**Fix (fixtures only).** Every test that inserted a `wallet_request` now creates the wallet first
and passes its id, mirroring the real flow:

- `tests/Unit/Models/WalletRequestTest.php` - a `makeRequest()` helper creates the wallet + request.
- `tests/Feature/Wallet/WalletRequestControllerTest.php` - `ensureWallet()` in `setUp()` plus
  wallet resolution in `walletRequestData()`, so a request built for a *second* user points at that
  user's own wallet. The withdrawal test now funds the wallet first, because
  `requestWithdraw` checks `pendingTotal + amount <= balance` under a row lock - weakening that
  check was rejected.
- `tests/Feature/Complaints/ComplaintControllerTest.php` - NOT part of the `wallet_id` family: its
  `test_store_accepts_all_valid_complaint_types` walked every `ComplaintType` case through the
  **public** endpoint, which contradicts the owner-confirmed design that `no_show` is staff-only
  (sec 40.3). Rewritten to assert the public types (via the existing `isAutoGenerated()`), and a
  new test pins that the public form REJECTS `no_show`.

**Measured.** The four files went from **24 red (15 errors + 9 failures) to 6**, and the six that
remain are a different, pre-existing family: five in `WalletRequestTest` assert `notes` fillable and
`status`/`type` enum casts that `App\Models\WalletRequest` does not declare (the column is
`user_notes`), and `UserRatingTest::test_rating_is_cast_to_float` asserts a `rating` cast the model
lacks. Those are model-vs-test drift, NOT `wallet_id`, and changing the model is a behaviour change
that no decision in sec 40 authorised - **recorded, not silently absorbed**.

**Baseline note.** The suite's recorded red baseline (120) includes this family; roughly 18 of those
entries are now green, so the baseline is expected to fall to ~102 - re-measured at the next
full-suite checkpoint rather than asserted here.

**Genuinely unverified.** The model-drift family above (6 tests) remains open and is now recorded
as its own item; it needs either a model change or an owner ruling on which side is correct.

---

## 42. un2 - score policy pinned; the stored-tier write was dead, not just wrong - **VERIFIED FIX**

**Owner ruling (sec 40.1):** start **70**, clamp **0-100**, bands **Gold >= 80 / Silver >= 60 /
Bronze >= 40**, gates **50** create-ride / **40** book.

**What was already right (so nothing was changed):** `ScoreService::initializeScore` (70),
`UserScore::applyDelta` (clamp 0-100), `UserScore::getTierAttribute` (the 80/60/40 bands),
`RideValidationService::MIN_SCORE_CREATE_RIDE` (50) / `MIN_SCORE_BOOK_RIDE` (40). Nothing asserted
them, so they could drift, and one copy had.

**The real defect, which was worse than a wrong scale.** `ScoreService::applyAction` wrote a stored
`tier` COLUMN on every score change from a **200/150/100 scale** applied to a 0-100 score - no account
can reach 200, so it labelled every user `bronze`. But the write was **dead**: `UserScore::
setTierAttribute()` is a no-op (`// computed from score - never stored`), so the assignment never
became dirty and never reached the database. A first draft of this fix "corrected" the scale to
80/60/40 and **still changed nothing**; the falsifiability needle caught it (the test passed with
the bug present). There were THREE definitions of the bands - the accessor (real), the dead column
writer (unreachable), and the migration default (`bronze`) - and the one that looked authoritative
could not run.

**Fix.** The computed accessor `UserScore::getTierAttribute()` is the single source of truth with
the owner's bands; the dead `resolveTier()` and its write in `applyAction` are **removed**; the legacy
`tier` column stays inert. Dropping the column is a separate migration decision - recorded, not taken.

**`tests/Feature/Review/RV37ScorePolicyTest.php` (new, 7 tests)** pins start 70, the clamp at both
ends, all four band boundaries, a real account driven 70 -> 80 reading Gold, and both ride gates
(the passenger gate behaviourally - 39 refused naming the score, 40 allowed; the driver gate by
constant, because `validateDriverCanCreateRide` checks KYC documents **before** the score).

**Needles (both fail, both restore MD5-identical):** breaking the accessor scale -> 3 failures;
changing the starting score 70 -> 55 -> 4 failures.

**No regression.** `AdminDriverServiceTest`'s 10 rating failures and `RideValidationServiceTest`'s 1
cancellation-window failure are the pre-existing baseline (UserRating cast, cancel window) - not
score policy.

**Genuinely unverified.** The legacy `tier` column still exists with a `bronze` default and is never
written; any report reading it with raw SQL would see a constant. No such reader exists in `app/` today
(verified by grep), but the column is a trap and its removal needs its own migration decision.

---

## 43. un5 - `finish` and `driver-confirm` endpoints DELETED - **VERIFIED FIX**

**Owner ruling (sec 40.1, Option C):** "I don't want someone implementing them or changing
anything because they don't know the flow." The product has **no driver-driven finish step**: each
passenger confirms their own booking (`POST /bookings/{id}/passenger-confirm`), that passenger's
money moves to the driver, and the ride auto-finishes when every booking is confirmed or reaches a
terminal status (cancelled / no_show) - `BookingService::passengerConfirmCompletion` (lines 527-556).

**What the endpoints actually were.** `RideController::finishRide()` and
`::driverConfirmCompletion()` returned a **static info message and changed nothing** - their own
message said so ("No driver action required... the ride finishes automatically"). They were
vestigial, and leaving them invited exactly the confusion the owner named.

**What was removed.**
- The two routes (`routes/api.php`).
- The two controller methods (deleted, with an explanatory comment in their place).
- The two OpenAPI entries in `app/Docs/RideDocs.php`, so no client is generated against them.
- The 8 stale test call-sites (6 in `RideControllerFullTest`, 2 in `RideTest`) replaced by tests
  that pin the **removal** (404), so the endpoints cannot quietly return.

**What was deliberately KEPT.** The `RideService::finishRide()` / `::driverConfirmCompletion()`
methods still exist and still do real work (empty-ride cash-fee refund, `checkAndCompleteRide`);
the seeders call them directly. Only the HTTP surface is gone - the service layer is the real flow
the owner described.

**Measured.** Ride floor after removal: 46 tests, 4 failures - all 4 pre-existing baseline
(`test_ride_creation_does_not_charge_any_fee` fee-expectation family + 3 `passenger_confirm_*`), none
caused by the deletion.

**Genuinely unverified.** The full-suite effect (red baseline shifts by ~8) is re-measured at the
next full-suite checkpoint. If any Flutter client still calls `/finish` or `/driver-confirm` it
will now get a 404 - which is the owner's intent, but it must be in the frontend-coordination note
(sec 40.3) so the app team is not surprised.

### 43.1 Near-miss recorded: a commit swept in owner-owned uncommitted work

`un5` had to edit `app/Http/Controllers/API/RideController.php`, which is one of the owner's
uncommitted files (two RV-38 eager-load edits, listed as owner-owned and never to be committed).
`git commit -- <path>` takes the **working-tree** content of a path, so those two owner lines were
committed along with the intended deletion. Caught on the commit-stat review, not by a test.

**Correction applied.** The commit was amended so `RideController.php` inside it contains only the
method deletion (rebuilt from `HEAD~1` minus the two methods, owner lines excluded), and the owner's
two eager-load edits were restored to the working tree as uncommitted changes. Verified after the
fact: 0 owner lines in the commit, 2 present uncommitted in the working tree, and the ride floor
still reports the same 46 tests / 4 pre-existing failures.

**Rule reinforced for the rest of this batch: a task must not touch an owner-owned uncommitted file.**
When a task genuinely needs one, the commit has to be built from a reconstruction of HEAD rather than
from the working tree, and the owner's edits must be put back afterwards - not discovered afterwards
by reading `git show`.

---

## 44. un9 - lazy-loading: service-layer N+1 sites fixed and measured; the guard is NOT yet armed - **PARTIAL**

**Owner ruling (sec 40.1, Option B):** "I want performance to be good and quality good even if that means
more work" - i.e. fix the sites, THEN arm the flag.

**How the sites were found.** `Model::preventLazyLoading()` was armed LOCALLY (uncommitted, via a
temporary probe in AppServiceProvider) and the full suite was run: **86 failures**, 27 logged
violation hits across 7 relation targets. The probe was then removed and never committed; the
committed default is still `if (! production) { preventSilentlyDiscardingAttributes();
preventAccessingMissingAttributes(); }`.

**Fixed (9 files, all eager loads that were already costing a query each):**
- `BookingService` - 4 booking fetches now take `ride.driver` (was `ride`), so the accept / reject /
  book / partial-cancel notification paths stop lazy-loading the driver per booking; the
  passenger-confirm path takes `ride`, and its re-fetch takes `driver`.
- `RideService` - `cancelRide` takes `driver`; `finishRide` / `driverConfirmCompletion` take `driver`.
- `CashRideFeeService::canCreateCashRide` - `loadMissing('wallet')` (it read the relation anyway).
- `ScoreService::recordRideCompleted` - `loadMissing('driver')` (read twice).
- `RideValidationService::validateDriverCanCreateRide` - `loadMissing('profile')` (also read by the
  document validator).
- `WalletController` - the four authenticated-user resolutions now `loadMissing('wallet')`; the user
  comes from `JwtAuthMiddleware`'s cached hydration, which eager-loads nothing.
- `AdminDriverService::formatDriver` - `loadMissing('profile')` (called once per driver).
- `AdminReportService` - `load('ride')` when not already loaded.
- `BackfillBookingMoneySnapshot` - iterates `with('ride')` instead of a lazy read per row.

**Measured.** With the guard armed the suite went **86 -> 68 failures**; 68 is exactly the
pre-existing baseline, so every service-layer offender is gone. With the guard OFF (the committed
state) the suite is **2070 tests / 38 errors / 58 failures**, byte-identical to the pre-task baseline
- these changes introduce no regression.

**What is STILL unfixed (17 tests, 4 clusters), and why the flag is not armed.**
1. `Booking::ride` in the booking accept/reject RESPONSE path - the reads are in
   `app/Http/Controllers/API/RideController.php`, which is an OWNER-OWNED uncommitted file; per
   sec 43.1 this batch must not commit it, so these reads cannot be fixed here.
2. The same path in `StaffOperationsController` (bookings listing, line ~339) - also needs the ride
   eager-loaded at its query, not yet done.
3. `Complaint::ride` (RV23 complaint context).
4. `Profile::user` in the search-render path (RideSearchService / driver rating rendering).

Arming the flag today would put 17 tests red - a regression by definition - so **the flag stays off**
and the remaining work is recorded. The four clusters are small and enumerable (the stack frames name
each file), so the next pass should finish them and then arm.

**State: PARTIAL - 9 files of service-layer eager loading landed and measured; 4 clusters remain, two
of them blocked only by the owner-owned controller; the flag is deliberately NOT armed.**

### 44.1 un9 completed - remaining clusters fixed and the guard ARMED

Continuing sec 44 (which had deliberately left the flag off with 17 tests red). The remaining four
clusters were traced and fixed:

1. **`Booking::ride` in the booking accept/reject/book/replay response path.** `bookRide` ends with
   `return $booking->refresh()` - and `refresh()` DISCARDS every loaded relation, so
   `BookingResource` (which reads `user.profile` and `ride.driver.profile` for the avatar/rating) always
   lazy-loaded them. All four service returns now `->load(['user.profile', 'ride.driver.profile'])`
   (the idempotent-replay early return had the same gap). This is the cluster that produced a
   **422**, not a lazy exception: `RideController::bookRide` wraps the call in
   `catch (\Throwable) { ... 422 }`, so the violation surfaced as a validation-shaped failure - worth
   knowing, because it made a guard violation look like a business-rule rejection.
2. **`Booking::ride` in the RV-40 backfill command** - `->with('ride')` on a `cursor()` does NOT
   eager-load in this framework version, so the relation stayed lazy; the read is now explicit.
3. **`Complaint::ride` / `Complaint::complainedUser`** in `RV23ComplaintContextTest` - the fixture read
   both relations off a bare `fresh()`; it now `load()`s them.
4. **`User::profile` in the search-render fixture** - `RideResource` reads `driver.profile`, but the
   test loaded only `driver` + `driver.receivedRatings`; the fixture now loads `driver.profile` too.

**Result: with `Model::preventLazyLoading()` armed, the suite's red set is byte-identical to the
flag-off baseline - 87 unique failing entries either way, zero new tests.** The flag is now ARMED
permanently outside production (`AppServiceProvider`), with the reason and the fix list in the
comment above the call. 5 `Profile::user` violations are still LOGGED (in the admin driver-stats path,
which catches and continues) but no test fails on them - recorded as a known, handled residue rather
than a silent one.

**Note on the failure-count shape:** the armed run reports 43E/58F where the flag-off run reported
38E/58F. The red SET is identical (87 entries) - the difference is only which of those 87 are
reported as errors vs failures (a violation caught by a `catch` surfaces differently from a plain
assertion). No test changed state.

**State: VERIFIED FIX (un9). The owner asked for performance and quality "even if that means more
work"; the work is done, measured, and the guard is on.**

---

## 45. Decision 11 - KYC gates the ACTIONS, not the account - **VERIFIED FIX (the behaviour already existed; this task PINS it)**

**Owner ruling (sec 40.1):** "the user can still search and everything even if he is not verified but he
cant make or book rides."

**Measured before writing anything: BOTH halves were already true in the code.**
- Ride creation: `RideService::createRide` -> `RideValidationService::validateDriverCanCreateRide`,
  which refuses `is_verified_driver = false`, a missing profile, and missing required documents.
- Booking: `BookingService::bookRide` -> `validatePassengerCanBook`, which refuses
  `is_verified_passenger = false` (the live refusal message is "You must be verified as a passenger
  to book rides").
- Browsing: `routes/api.php` puts search / autocomplete / route-options behind auth + throttle only,
  with **no** verification check - so a logged-in but unverified user can search freely.

So this decision required **no behaviour change**; the deliverable is the PIN, because nothing
asserted either half and the "browse freely" half is exactly what someone could break by adding a
verification check to search (the opposite of what the owner asked for).

**`tests/Feature/Review/KycActionGateTest.php` (new, 3 tests)** proves all three:
1. an unverified user CAN search and get results;
2. an unverified user CANNOT create a ride - 422 whose message names verification;
3. an unverified user CANNOT book a ride - 422 whose message names verification.

**Two of my own mistakes caught before commit, both by needles:**
- The first draft asserted `assertContains($status, [401,403,422])` and sent `from_*`/`to_*` fields.
  The create request 422'd on a missing `pickup_lat` - a VALIDATION failure - so the test passed for
  the wrong reason, and the needle that removed the passenger gate still passed. Fixed to use the
  real `CreateRideDTO` field names and to assert the message names verification. (A test that passes
  when the thing it guards is removed is worse than no test.)
- The login response shape is `tokens.access_token` (taken from the repo's own `RideTest::getToken`),
  not `token`/`access_token`.

**Needles, all three biting, all restoring byte-identical:** gate search -> fails; remove the
passenger gate -> fails; remove the driver gate -> fails.

**Genuinely unverified.** None for this decision; the three halves are pinned by tests that fail when
the behaviour is removed.

---

## 46. Decision 1b - staff-authenticated KYC document streaming - **VERIFIED FIX**

**Owner ruling (sec 40.1):** add a staff-authenticated route so KYC documents stop being public.

**The IDOR, measured.** `DocumentController::store` uploads every document to the `public` disk
(`$file->store('documents', 'public')`) and the staff surfaces render them as
`asset('storage/'.$p->path)` - a PUBLIC URL to a face ID photo, a back ID photo, a driving licence
and a mechanic card. Anyone holding the URL (it appears in API responses; storage keys are guessable)
could download another person's identity documents with **no authentication at all**.

**Delivered.**
- `app/Http/Controllers/API/Staff/StaffDocumentController.php` (new): `show` (any KYC type) and
  `identity` (face/back ID only), both inside the existing `staff` middleware group - the same gate
  every other staff read uses.
- `routes/api.php`: `GET /api/staff/documents/{photoId}` and `.../identity`, inside
  `['staff', 'throttle:staff']`.
- `StaffAdminController` (the verification queue) now hands out
  `route('staff.documents.show', ['photoId' => $p->id])` instead of the public asset URL.
- Responses are `nosniff` + `no-store` + `Content-Security-Policy: default-src 'none'; sandbox`;
  the content type is derived from the extension, never trusted blindly.

**Two real bugs my own tests caught (both fixed):**
1. `CacheStatusHeader` middleware calls `$response->header(...)`, which does not exist on a
   Symfony `BinaryFileResponse` - the first version of this endpoint turned every request into a 500.
   Now it sets the header through `$response->headers->set(...)`, which works on BOTH response types.
   (This was a latent pre-existing middleware incompatibility that only this endpoint exposed.)
2. The first test version seeded a fixed filename; `RefreshDatabase` rolls back the database but NOT
   the real filesystem, so a stale `documents/test-face_id.jpg` from an earlier run was served. It now
   uses a unique name per run. (Recorded because "no test writes a tracked file" does not cover the
   real filesystem - this class leaves files in `storage/app/public/documents/`.)

**`tests/Feature/Review/KycDocumentAccessTest.php` (new, 5 tests)** pins: no token -> 401; a USER
token -> 401 (audience separation holds); staff token -> 200 with the real bytes and the correct
security headers; a licence is served on the general route but 404 on the identity alias; a missing
photo is 404, not 500.

**Needle: moving the two routes OUT of the `staff` middleware makes exactly the two 401-tests fail
(routes restored byte-identical).** The security rests on the middleware, not on luck.

**What this does NOT close (open, recorded honestly).** The files still physically sit on the
**public** disk, so any previously-shared URL keeps resolving. This change stops the APPLICATION from
handing out or accepting an unauthenticated document URL; fully revoking old links needs the
documents moved to a private disk (plus `storage:link` scope review and, for cloud storage, an object
ACL) - a separate deploy/storage task, and `ProfileController`'s user-facing
`/api/profile/verify/status` still returns the user's OWN documents by public URL, which is correct
for self-view but should move to the same route when the private-disk move happens.

**Genuinely unverified.** No test exercises a real uploaded binary through the route end-to-end (the
fixtures write text bytes); the auth boundaries, routing, headers and 404 semantics are covered.

---

## 47. Decision 6 - staff-initiated cancel refunds in full, no driver score penalty - **VERIFIED FIX**

**Owner ruling (sec 40.1, Option A):** "full passenger refund, no driver score penalty". Rationale in
the owner's words: *"staff cancellation is rare; make it non-ad-hoc"* - i.e. one explicit, ledgered
operation instead of an ad-hoc status flip. This is the decision that unblocked RV-03
(`BACKLOG` row 23: "Staff cancel endpoints strand money, no role gate").

**The defect, measured.** `StaffOperationsController::cancelTrip()` and `::cancelBooking()` marked
bookings `cancelled` and moved **no money at all**. `cancelTrip` even carried the comment
"Refund all confirmed bookings (e-pay only)" directly above `$booking->update(['status' => 'cancelled'])`
- a comment describing behaviour the code did not perform. Every e-pay passenger's escrow stayed
stranded in the SyCash wallet permanently. The existing 80-test `StaffOperationsControllerTest` passed
throughout and never noticed, because **not one of those tests asserted money**.

**Fix.** New `WalletTransactionService::refundPassengersForStaffCancellation(Ride, Collection)`,
called by both staff-cancel endpoints INSIDE their existing transaction, BEFORE the status flip so a
failed refund rolls the whole cancellation back (a ride can never end up cancelled with stranded money).

**The amount is the RV-40 `amount_paid` snapshot, never `seats * price_per_seat`.** `price_per_seat` is
mutable: a price edit between charge and cancellation silently over/under-refunds, which is the exact
divergence RV-40 exists to prevent.

**Bookings with no snapshot are reported, never guessed.** A confirmed e-pay booking with
`amount_paid = 0` is a pre-RV-40 legacy row. Rather than re-deriving an amount from the current price
(precisely the bug being removed), it is returned as `needs_review` and logged with the hint to run
`bookings:backfill-money-snapshot`. The response surfaces `refund_total`, `refund_bookings`,
`refund_amount_source` and `needs_manual_review`, so a staff member can see what moved.

**Cash bookings are untouched** - cash never touches SyCash, so there is nothing to refund.

**No driver score penalty** was already the behaviour (neither endpoint called `ScoreService`); it is
now pinned by a test rather than left implicit, because "the fix must not add a penalty" is exactly
the kind of thing that regresses silently.

**Two bugs my own tests caught:**
1. `collect([$booking])` produced an `Illuminate\Support\Collection` where the service signature
   requires `Eloquent\Collection` - a hard 500 on every `cancelBooking` call. Fixed by re-reading
   through the model query; the 8 affected existing tests caught it immediately.
2. My first fixture used `Ride::create([... 'pickup_location' => ...])`, which is not fillable; the
   repo's own `RideBuilder` is the supported path, so the fixture now uses it.

**`tests/Feature/Review/StaffCancellationRefundTest.php` (new, 6 tests)** asserts, on the allowed AND
denied sides: full refund to the wallet; SyCash actually debited; the amount is the snapshot not the
current price (price edited to 99,999 after charging 25,000); single-booking cancel; **no driver
score or cancellation-counter change**; a no-snapshot row is reported not guessed; a cash booking is
not refunded.

**Needles (both bite, both restore byte-identical):** deriving the refund from the mutable price
instead of the snapshot -> 3 failures; removing the refund call from `cancelTrip` -> all 6 fail. (A
first needle attempt appeared to "pass" only because the PowerShell multi-line replace never matched;
the structural line-removal needle then proved it bites.)

**No regression:** `StaffOperationsControllerTest` + `WalletTransactionServiceTest` +
`RV40BackfillSnapshotTest` + this file - **117 tests, 265 assertions, OK**.

**Genuinely unverified / deliberately NOT changed.**
- The **role gate** half of RV-03 is untouched. Both endpoints sit in `['staff','throttle:staff']`,
  so any authenticated employee can move money. Tightening it is an authorization change that can lock
  legitimate staff out, and `AGENTS.md` requires the owner to approve auth/permission changes - so it
  is raised here rather than done unilaterally.
- `refundPassengersForDriverCancellation()` still derives from the mutable price. That is decision 3
  (owner-flagged "reconsider"), explicitly out of scope here.

---

## 48. un11 (RV-29 cache DTO) - the auth cache no longer stores password hashes - **VERIFIED FIX**

**Owner ruling (sec 40.1, Option A):** "full refactor" - cache a safe DTO (no password hash) AND fix the
auto-lift hazard. Rationale: "I want performance/quality good". This is the item R2 sec 30 recorded as
"the safe fix is a cache DTO touching auth core, blocked by the `JwtAuthMiddleware` auto-lift
`update()` data-loss hazard -> owner call on the auth-core refactor".

**THE FINDING.** `JwtService::findUserCached()` cached the whole `User` Eloquent model:
`Cache::remember("auth.user.{$userId}", 300, fn () => User::with('profile')->...->find($userId))`.
`Cache::remember` SERIALISES whatever it is handed, so every user's bcrypt `password` hash was written
into the cache backend (Redis) in plain serialized form on every miss, for the 5-minute TTL. A bcrypt
hash is a credential: anything able to read the cache - a compromised Redis, a snapshot, a dump, a
`KEYS` on a shared instance - has password hashes for offline cracking.

**THE GATE THAT HAD TO BE CLEARED FIRST.** Sec 30 said the fix was blocked because "the full
`$request->user()->password` consumer set cannot be bounded from here". **It bounds.** Every `->password`
hit in `app/` (8 total): 5 are the separate `Employee` model; 1 is `$request->password` (an input, not
a model); `LoginController:55` and `ResetPasswordController:82` both operate on a FRESHLY queried User
(`User::where('email',...)->first()`), never the cached one. **Zero consumers read the password off the
request user.** That is why the fix is safe - established by measurement, not assumption.

**FIX.**
- `app/DTOs/Auth/CachedUser.php` (new): caches `getAttributes()` **minus `password`**, plus the
  `profile` relation. `toUser()` rebuilds the model with `forceFill`/`setRawAttributes`, and marks
  `profile` explicitly loaded (or loaded-and-null) so no read lazy-loads - which matters now that the
  lazy-loading guard is armed.
- `JwtService::findUserCached()` caches the DTO and rebuilds the `User`. **The signature is still
  `?User`**, so every existing caller is untouched; the change is contained inside one method.
- `JwtAuthMiddleware`: the auto-lift no longer calls `update()` on the cache-derived `$user`. It
  re-reads the authoritative row with `User::useWritePdo()->find()` and writes to THAT. This is the
  recorded data-loss hazard closed by construction: a cache-shaped partial model can never be the
  thing that persists. `useWritePdo` also avoids re-reading a lagging replica and writing the ban
  fields back over the lift.

**A behaviour trap avoided.** The first draft used `findOrFail()`, which would have turned the
middleware's clean 401 USER_NOT_FOUND into a 500. `fromOptionalModel()` preserves the null path, and a
test pins it.

**`tests/Feature/Review/AuthCacheDtoTest.php` (new, 5 tests):** the raw cache payload contains no
hash and no `password` KEY at all; the DTO still carries every field the app reads off
`$request->user()`; the rebuilt model serves identity/status/token_version/verification flags and a
pre-loaded `profile`; a deleted user still resolves to null (401, not 500); a profileless user reads
null without a lazy load.

**Needle: restoring the vulnerable `fn () => User::with('profile')->find($userId)` makes the payload
test fail** (3 errors, including the "VULNERABILITY" assertion) and the service restores
byte-identically. The pin detects the original defect, not just its absence.

**No regression.** Identity floor (`tests/Feature/Auth`, `tests/Unit/Middleware`,
`tests/Feature/Security` + the two auth review tests) = **164 tests, 2 failures, both in the recorded
pre-existing baseline** (`test_using_refresh_token_as_access_token_returns_401`,
`test_staff_refresh_token_rejected_with_token_type_invalid`, both present in the full-suite red list
before this task). `BoundaryDependencyTest` still green - no baseline moved.

**Genuinely unverified.** The cache backend in CI/tests is array/file, not a real Redis, so the test
proves the SERIALIZED PAYLOAD is credential-free (which is the property that matters - it is what any
backend would persist), not Redis' own behaviour. A pre-existing Redis already holding hashes from
before this commit keeps them until its TTL expires; a production flush is a deploy step.

---

## 49. un8 - Larastan added REPORT-ONLY - **VERIFIED FIX**

**Owner ruling (sec 40.1, Option A):** "add report-only (no gate)". Owner's words: *"needs work after we
finish these tasks completely"* - add the tooling now, cleanup is a separate later task. The audit had
deferred it precisely because level 5 over this codebase "produces noise" (R2 sec 1; RV-33).

**What shipped.**
- `larastan/larastan` **2.9** added to `require-dev` (dev-only; verified absent from `require`).
  Version pinned deliberately: 3.x requires `illuminate/console ^11.15` and this project is Laravel 10.
- `phpstan.neon.dist` - level 5, `paths: app/`, tests NOT analysed (analysing them is what produced
  the original noise), no baseline file, one accurate `ignoreErrors` pattern.
- `.github/workflows/static-analysis.yml` - runs on push/PR, **`continue-on-error: true`**.

**The measured result: 276 findings at level 5**, dominated by two families that are exactly what a
static analyser sees and a human does not:
- **54** "X is not found in model" - Eloquent relations defined in a parent/trait or resolved at runtime;
- **69 + 15 + 13 + 9** accesses to dynamic properties (`::$ride`, `::$user`, `::$seats`, `::$id`);
- **28** mixed `int|string` vs float; **25** type mismatches on calls.

This is the "noise" the audit predicted, now MEASURED on every push instead of assumed.

**Why `continue-on-error: true` is load-bearing, not a placeholder.** Gating on 276 untriaged
findings would make every PR red for a reason nobody has read - the same "an always-red gate is an
ignored gate" failure already recorded under T3-11. The job's value today is that the debt is
visible and countable. The header of the workflow documents the exact three-step follow-up (read the
report -> `--generate-baseline` -> flip the flag) so it is not forgotten.

**Why NO baseline in this commit.** A generated baseline that nobody has read freezes the debt
silently, which defeats the purpose. It is explicitly deferred to the triage task.

**One environment gotcha worth recording.** Larastan's bootstrap BOOTS the application, and
`AppServiceProvider`'s OTP guard refuses to start with the testing OTP modes outside local/testing
(`Refusing to start with a testing OTP mode enabled outside local/testing`). The job therefore sets
`APP_ENV=testing`. That is the guard working correctly - recorded so the next person does not "fix"
it by weakening the guard.

**Installed with `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` locally only**
(this WAMP PHP build lacks them; `laravel/horizon`, locked at v5.48.3, requires them). The flag was
NOT written into composer.json, and the CI runner explicitly installs both extensions.

**Verification.** The exact command the workflow runs was reproduced locally: 276 findings, no crash.
Composer change is additive and dev-only.

**Genuinely unverified.** The CI run itself has not executed (no push was authorised this session).
Level 5 vs a lower level was chosen by judgement, not by measuring which level yields signal; if the
triage task finds level 5 too noisy even as a report, lowering it is a one-line change. `app/Http/
Middleware` is excluded from analysis (routing/DI plumbing), which is a judgement call, not a measured
one.

---

## 50. Decision 7 - deploy on Render - **VERIFIED FIX**

**Owner ruling (sec 40.1, Option B):** "we moved to Render to save the cost of the subscription".
Owner's note: *"Rewrite deploy for Render; the VPS workflow becomes obsolete."*

**Measured first.** The migration was **already half-done and nobody had noticed**:
- `render.yaml` EXISTED but was a 9-line stub (one web service, nothing else);
- `docker/start.sh` was already written for Render (`PORT`, `WEB_CONCURRENCY`, a minimum of 2
  workers "so health checks never starve") - someone had adapted the container without finishing the
  infrastructure;
- `routes/web.php` carries a `/up` healthcheck whose comment explicitly says
  `// Platform healthcheck (render.yaml:5) and the deploy workflow depend on this`;
- `docker-compose.yml` still described the VPS topology: `app1..app5` + its own nginx + a local
  mysql **primary AND replica** + redis + a horizon `queue` + a `while true` `scheduler`.

So the deliverable was not "write a Render setup" but "finish it, and account for every process the
compose file was running".

**`render.yaml` rewritten as a complete Blueprint - three services, nothing dropped:**

| compose (VPS) | Render service |
|---|---|
| `app1..app5` + `nginx` (TLS terminator) | `web` - one service behind Render's edge; `WEB_CONCURRENCY` + the plan do the scaling |
| `queue` (`php artisan horizon`) | `worker` - `atareekak-queue` |
| `scheduler` (`while true; do schedule:run; sleep 60; done`) | `cron` - a real Render scheduler at `*/5 * * * *`, not a busy loop |

The database and Redis are deliberately **NOT** created by the blueprint: production MySQL is external
(Aiven, with the primary/replica split the app depends on) and Redis is external. The local
mysql+redis+nginx cluster is not something Render should re-create.

**Secrets are never committed.** Every credential is `sync: false`, which makes Render prompt once
and store it encrypted. That includes one that is easy to overlook and is a *correctness* key, not a
cosmetic one: `ADMIN_WALLET_PHONE`. `WalletTransactionService::lockWalletByPhone` resolves the SYSTEM
SyCash/Primary wallet and refuses any user-owned row; the wrong value fails every escrow operation
closed.

**A blueprint bug I caught and fixed before committing.** I first wrote the worker/cron env blocks as
`fromService: { type: web, ..., property: envVars }` to inherit secrets from the web service. Render's
`fromService` can only copy a **datastore connection string** - there is no way to copy a named env
var between services, so that blueprint would have failed to apply. Each service now declares
`sync: false` itself, with the reason written next to the block so nobody "tidies" it back into an
invalid form.

**Migrations: no `preDeployCommand`.** `docker/start.sh` already runs `php artisan migrate --force`
after a DB-readiness wait, and the Dockerfile's CMD is that script - so migrations run on deploy
exactly as the VPS workflow ran them explicitly. Adding `preDeployCommand` would run migrate twice,
which is how a partially-applied migration turns into a deadlock.

**The VPS workflow is disarmed, not deleted.** Its `push:` trigger on branch `samer` is removed and it
now runs only on `workflow_dispatch`, with a header explaining the deprecation. AGENTS.md requires the
owner to approve deleting code, and "becomes obsolete" is not the same as "delete it"; keeping it
manual also preserves a rollback path.

**Verification.** `render.yaml` parses and yields exactly `web:atareekak`, `worker:atareekak-queue`,
`cron:atareekak-scheduler` with 45/14/11 env vars. All six workflow YAMLs parse. Confirmed `/up`
exists and names `render.yaml`; confirmed the Dockerfile installs `pcntl`+`sockets` that Octane needs.

**Genuinely unverified.** The Blueprint has never been applied to a real Render account, and no deploy
has run - `branch: samer` is carried over from the old workflow's trigger branch and MUST be
confirmed against the branch the app is actually released from; if releases happen from `main` or
`Agentic`, every service in this file needs that branch changed (4 occurrences). Render's plan/plan-tier
fields are not set (they must be chosen in the dashboard). The VPS workflow has not been executed
either, now or before.

---

## 51. un12 - k6 setup() harness + a 3-run perf spec - **VERIFIED FIX**

**Owner ruling (sec 40.1, Option A):** "proper `setup()` harness", rationale "trustworthy perf
numbers". Audit item RV-17: *"k6 harness needs setup()-login + seeded rides/bookings (hard-coded
expired tokens today...) plus R1's constant-arrival-rate / 3x-run / threshold / result-JSON reporting
spec"*.

**Measured first.** 11 of 12 existing specs already HAVE a `setup()` - that was never the problem.
RV-07 had already removed the ~500 committed bearer tokens and moved them to
`K6_PASSENGER_TOKENS`/`K6_DRIVER_TOKENS` env vars, and the folder's own README explains why: *"they
expired silently (the ones in git had been dead since 2026-08-14 while the load tests kept 'passing'
- the search slice was 422-ing the whole time)"*.

**THE REAL DEFECT is that an env-var token is the same failure in new clothing.** It is a snapshot
minted by a human and pasted into a shell; it rots the same way. So the harness now LOGS IN.

**Delivered.**
- `k6-load/harness.js` - `authSetup()` authenticates through the REAL API
  (`POST /api/auth/login` -> `tokens.access_token`) inside `setup()` and returns the tokens to every
  VU, so login is paid ONCE per run (and is not what the thresholds measure). Missing credentials
  and a failed login are both **fatal**, never an empty pool.
- `k6-load/perf-3run.js` - `constant-arrival-rate` (not ramping VUs: ramping measures "how long to go
  slow", steady arrival measures what happens to a user arriving every second, which is where a
  queue or a lock fails). Three escalating runs - baseline, same rate, 1.5x - because identical
  back-to-back runs mostly measure cache warmth. Thresholds on the SLO metric, not raw
  `http_req_duration`. `--summary-export` writes JSON for date-over-date comparison.
- `k6-load/README.md` documents both, and keeps the hand-minting recipe for anyone who needs it.

**Verified end-to-end with the real k6 binary (v2.0.0) against the running app:**
- no `K6_PEOPLE` -> fails loudly with the reason (never a fake green);
- wrong credentials -> refuses to start;
- valid credentials -> `[harness] minted 1 token(s), 1 distinct`, then **7 real search requests,
  0 non-success, 0 auth rejections**;
- the thresholds then CORRECTLY FAILED (`dropped_iterations: 11`, p95 ~27s). That is the harness
  working: PHP's single-threaded built-in dev server cannot sustain 2 arrivals/sec, and the run said
  so instead of reporting a flattering number.

**A k6 landmine found by running it, documented so it cannot bite again.** The natural name
`K6_DURATION` **collides with a k6 v2 built-in config key**: k6 logs
`"env" level configuration overrode scenarios configuration entirely`, silently builds its own
`default` scenario, and surfaces only an unrelated `the duration must be at least 1s, but is 1ms`.
The run measures a different load than the file describes. `K6_RATE` and `BASE_URL` are unaffected;
bisected to a 12-line reproduction. Renamed to `K6_RUN_SECONDS` with the warning in the script header
AND the README.

**Two metrics are warnings in disguise, by design:** `syride_auth_rejections == 0` (non-zero means
the RV-17 stale-token failure returned) and `dropped_iterations == 0` (a low p95 with dropped
iterations is a smaller load than you think, not a pass).

**No app code touched**; the test account, dev server and temp files were removed afterwards
(proved by `git status`).

**Genuinely unverified.** No load was run against a production-shaped target, so NO performance
number is claimed anywhere. The p95 threshold (800ms) is a starting proposal to be argued with, not a
measured SLO - it is the single value most likely to need changing once real numbers exist. Ride
detail reads only run when `K6_RIDE_IDS` is supplied, so the default run exercises search only.

---

## 52. un1 - MinIO object storage configured (private disk for KYC documents) - **VERIFIED FIX (enabled by deploy, not by code)**

**Owner ruling (sec 40.1, Option A):** "MinIO - if it doesn't require payments and so on, free".
This is the AF-5 storage half, and it is the item that closes the last open part of RV-01.

**Measured first.** Laravel already ships an `s3` disk, and MinIO speaks the S3 API, so **no new
package is required** - this is configuration, not a dependency. The application currently uploads
KYC documents with `->store('documents', 'public')`, i.e. to the web-served disk that decision 1b
removed the *application's* link to.

**Delivered.**
- `config/filesystems.php`: a `minio` disk (s3 driver, path-style addressing, `visibility private`,
  and **deliberately no `url` key** - a disk with a public url is precisely the defect being removed).
  Kept separate from the stock `s3` disk so staging can run MinIO while production runs a cloud
  provider without touching application code.
- `filesystems.documents_disk` (`DOCUMENTS_DISK`), and `DocumentController::store` now resolves the
  disk from config.
- `docker-compose.yml`: a `minio` service (API 9000, console 9001, loopback-only, persistent volume)
  with the credentials required from the environment using the file's existing `${VAR:?...}`
  hard-fail convention.
- `.env.example` keys for the MinIO endpoint/credentials/bucket.

**THE IMPORTANT DESIGN CHOICE: this cannot break a deploy.** `DOCUMENTS_DISK` defaults to `public`,
i.e. today's behaviour. Flipping identity documents onto object storage is a **one-variable deploy
change**, made only once the bucket exists. Had this flipped the default, every host without MinIO
would have started failing document upload at the moment this commit deployed.

**One correctness detail that would have broken the migration quietly.** `UploadedFile::store()`
returns a **disk-prefixed** path on some disks but a bare key on S3-style ones. `photos.path` stores
that value verbatim, so a disk switch that also introduced a prefix would silently orphan every
document row already in the database. No prefix is introduced, and the existing rows keep resolving.

**A failure case that was previously invisible is now explicit.** With `'throw' => false`, a
misconfigured disk makes `store()` return `false` and the old code would have stored nothing and
still returned success - a user left holding a "submitted" document that does not exist. That now
returns a 500 with a message.

**Verified.** 21 tests OK (`DocumentControllerTest` + `KycDocumentAccessTest`) with the default in
place, so the switch is genuinely inert. Config resolves: `documents_disk=public`,
`minio.driver=s3`, `minio.bucket=syride-private`, `minio.pathstyle=true`, `minio.has_url=false`.
docker-compose.yml parses with the new service and volume.

**Genuinely unverified.** **No MinIO instance was run and no file was uploaded to one** - Docker is
not available in this environment, so the S3-compatible round trip, the bucket-creation step and the
path-style requirement are all untested against a live server. `photos.path` values written while
`DOCUMENTS_DISK=public` remain readable from a private bucket ONLY if the objects are migrated
(`mc mirror`); this task configures the destination, it does not move existing files. That migration
is a deploy task with its own rollback, and it is the step that actually closes RV-01.

---

## 53. Decision 2 - unconfirmed bookings EXPIRE (never auto-confirm) - **VERIFIED FIX** (first migration-group task)

**Owner ruling (sec 40.1, Option B):** "expire unconfirmed bookings, do NOT auto-confirm". Marked by
the owner as "important to be reconsidered"; implemented as ruled. Authorization to proceed unattended
was given for the whole decision batch (2026-10-02).

**The rejected alternative is the point.** Auto-confirming a request the driver never accepted would
seat a passenger in a car whose driver never agreed to carry them - and, for e-pay, CHARGE them for
it. That is why the ruling is expiry.

**THE ESCROW-RELEASE RULE THE DECISION MENTIONS TURNS OUT NOT TO EXIST, and that is a finding, not
an omission.** Verified in code before designing:
- `BookingService::bookRide` charges e-pay only for DIRECT bookings that are immediately CONFIRMED;
- `acceptBooking` charges a REQUEST e-pay booking at the moment the driver accepts;
- `deductSeats` likewise runs only on accept.

So a booking that is still `pending` has `amount_paid = 0`, never reached the passenger's wallet and
never entered SyCash escrow. **There is nothing to release and no seat to return.** A test asserts
this so a later reader does not "helpfully" add a refund path for money that was never taken.

**Delivered.**
- Migration `2026_10_03_230000_add_expired_to_bookings_status_enum`: adds `expired` to
  `bookings.status`. **Verified in both directions against real data**: a row written as `expired`
  was rolled back with `expired -> cancelled` (the closest truthful state) and the column re-narrowed
  successfully, then re-migrated forward. `up()` uses MODIFY (not change) so existing enum values
  survive.
- `BookingStatus::EXPIRED` with `label/colour/isActive/canBeCancelled`. It is deliberately **terminal
  and not cancellable** - re-cancelling would notify the passenger twice and try to return seats the
  booking never held.
- `ExpireStaleBookingsCommand` (`bookings:expire-stale`), scheduled every 15 minutes with
  `onOneServer`/`withoutOverlapping`. Idempotent: expired rows are no longer `pending`.
- Each row is expired in its own transaction with an **in-transaction re-check**, because the driver
  may accept between the seed query and the write. A failed notification is logged, not rolled back -
  rolling back would leave the row pending and retry it forever.

**Why not reuse `cancelled`:** a driver who never answered is a different event from a passenger who
changed their mind. Collapsing them makes "how often are requests ignored?" unanswerable and shows an
expiry in the passenger's history as a self-cancellation.

**`tests/Feature/Review/BookingExpiryTest.php` (new, 6 tests):** expires after departure; NOT before;
moves no money (wallet, SyCash and ledger-row counts all unchanged); never touches a CONFIRMED
booking; idempotent; EXPIRED is terminal and not cancellable.

**Needles - and what they actually proved.** My first needle attempt patched only ONE of the command's
two guards (the seed query) and the suite stayed green. That was not a weak test: the command is
defended twice, and the in-transaction re-check caught it. Removing BOTH layers makes the suite fail
(1 failure each for departure-time and for the confirmed-booking rule), and the file restores
byte-identically. Defence-in-depth working is a better result than a single guard that a one-line
patch defeats.

**Two bugs the tests caught in my own work.** (1) The command's `lockForUpdate()->find()` re-read
triggered `LazyLoadingViolationException` under the lazy guard armed in un9 - fixed with
`->with('ride')`, which is load-bearing precisely because `lockForUpdate` re-reads the row.
(2) My fixture called `->departureTime()` BEFORE `->withAttributes()`, and `withAttributes` replaces
the attribute set, so every ride was "departed" and the before-departure assertion was a no-op. Fixed
and the ordering now carries a comment.

**No regression:** 115 tests / 4 failures, all four the long-recorded pre-existing baseline
(`RideControllerFullTest` passenger-confirm x3, `RideTest::test_ride_creation_does_not_charge_any_fee`).
`BoundaryDependencyTest` green.

**Genuinely unverified.** The scheduler wiring (`everyFifteenMinutes`) has not been observed firing in
a deployed environment - only the command itself is tested. Notification delivery is not asserted
against a real push/SMS provider. The 15-minute cadence means an expiry can land up to 15 minutes
after departure; that is a deliberate trade and is recorded above.

---

## 54. Decision 13 - DB ENUM columns converted to varchar - **VERIFIED FIX** (second migration-group task)

**Owner ruling (sec 40.1, Option A):** "PHP enums, drop DB ENUMs". Authorization for the batch on record.

**WHY THIS MATTERS, concretely.** The database was enforcing a *second, independent* copy of every
value list that the PHP enums already own. The audit had already been bitten by exactly this:
`BookingStatus::tryFrom('no_show')` returned null because the PHP enum never declared a value the DB
already held. A varchar column removes the duplicate; the PHP enum keeps the type safety.

**SCOPE MEASURED, NOT GUESSED.** `information_schema.COLUMNS` reports **18 live ENUM columns** across
11 tables - the audit's "17" was stale (it predates this batch's own `bookings.status` `expired`
addition). Two columns the audit expected are NOT enums and were correctly left alone:
`complaints.status/type` and `wallet_transactions.type` were already varchar from an earlier
migration.

**Every definition is preserved per column** - nullability, default and length transcribed from the
live schema, because a MODIFY that drops a NOT NULL or changes a default is a silent integrity
regression. Lengths are sized to the longest value each column can hold (plus headroom).

**Verified BOTH directions against real data, on the scratch DB:**
- up() -> 0 ENUM columns remain, all 18 varchar, nullability/defaults confirmed correct, a user with
  NULL gender intact.
- down() -> 18 ENUM columns restored, data unchanged after the full round-trip.
- up() again -> 0 ENUM columns, `otps.type` survived as `varchar(32)`.

**`down()` REFUSES TO TRUNCATE - tested for real, not asserted.** Widening to varchar is precisely
what makes an out-of-enum value possible, and a blind rollback would silently coerce it to '' under
MySQL strict mode. A test wrote `verification_status='quantum_x'`, rolled back, and the migration
THREW `Refusing to roll back decision 13: ... users.verification_status holds quantum_x`, leaving the
value intact. Losing rollback capability is better than a rollback that destroys data.

(The first attempt at this test was invalid: the value chosen was 17 chars against a `VARCHAR(16)`
and MySQL rejected it before the guard was reached. That is a real property of the conversion - the
varchar still bounds length - and the test was corrected, not the guard.)

**Coupling recorded rather than left to an incident.** If a later migration adds a value to one of
these enums, this file's `down()` copy must be updated in the SAME commit or `down()` will refuse to
roll back. That is the intended loud failure.

**No application code touched** - the change is one migration file, so no service, controller or
boundary edge moved. `BoundaryDependencyTest` unaffected by construction.

**No regression.** Ride + money floor (Bookings, Rides, Wallet, Payment, RideValidationService,
BookingExpiry) = 184 tests / 8 failures, ALL confirmed in the recorded pre-existing red set (the 4
long-standing RideControllerFullTest/RideTest baseline, 3 OTP WalletTest, 1 cancel-window). **Zero
new failures.**

**Genuinely unverified.** The conversion was exercised on the scratch schema only; production is a
different dataset and the first real `down()` there would be the first test against real volume. A
varchar column is also no longer self-documenting at the database level - the allowed values now live
ONLY in the PHP enums, so a raw SQL client can insert a nonsense status and the database will accept
it. That is the accepted trade of this decision (the PHP enum is the single source of truth), but it
means validation at the application boundary matters more than before, and it is the strongest
argument for keeping the enums in PHP rather than relying on the column.

---

## 55. un13 - one BanService + a real AccountStatus enum - **VERIFIED FIX** (third migration-group task)

**Owner ruling (sec 40.1, Option A):** "clean `status` + `BanService`", rationale "root cause of ban
bugs". No schema change was needed - `users.status` is already a `tinyint`, not an enum, so decision 13
had nothing to do here.

**THE ACTUAL FINDING is not the magic numbers.** "Un-ban" existed in THREE copies that already
disagreed about which caches to clear:
1. `AdminBanController::unban` - status 0 + clear every ban field + bust the full cache list;
2. `AdminBanController::ban` - status -1 + ban fields + revoke tokens + bust the full list;
3. `JwtAuthMiddleware` auto-lift - its OWN copy of the un-ban write, forgetting only
   `auth.user.{id}`.

The divergence had a user-visible consequence already recorded by the audit: an account whose
temporary ban expired stopped being locked out of the app (the middleware forgot the key it read
from) while still counting as banned on every admin dashboard (the middleware never cleared those
keys at all).

**Delivered.**
- `AccountStatus` int-backed enum: LOGGED_OUT=0, ACTIVE=1, BANNED=-1. Values deliberately UNCHANGED -
  they are already persisted and already returned by the admin API, so renumbering would be a
  destructive migration for nothing.
- `BanService` with `ban()`, `unban()`, `liftExpiredBan()` and ONE private `bustCaches()` holding the
  complete key list. All three previous call sites now delegate to it; the duplicated
  `bustBanCaches` in the controller was deleted.
- `liftExpiredBan()` re-reads under `lockForUpdate()` on the primary, so the write can never land on
  a cache-shaped partial model (the R2 sec 30 hazard) and cannot race an admin who just changed the ban.

**One genuine behaviour change, stated plainly.** The auto-lift now busts the FULL cache list
instead of one key. That is a fix, not a regression: an expired ban should stop counting as a ban
everywhere at once. Everything else - columns written, token revocation, which keys - is unchanged.

**A real bug in my own first implementation, caught by its own test.** I guarded the auto-lift with
`! $user->isBannedNow() || ! $user->banHasExpired()`. But `isBannedNow()` ALREADY returns false once
a temporary ban has expired, so that condition asks for a ban that is simultaneously in force and
expired - it can never be true, and **the auto-lift never fired at all**. The correct precondition is
the raw one (status is BANNED and the window has closed). This is exactly the class of mistake the
decision exists to prevent, and it is now documented at the call site: `isBannedNow()` is the
predicate for deciding whether to BLOCK someone, not whether to RELEASE them.

**Two flaws in my first version of the consolidation test, also caught by running it.** (1) The cache
keys were seeded BEFORE the ban, so `ban()` cleared them and the assertions passed on work the ban
had already done - they proved nothing about the lift. They are now seeded after the ban, immediately
before the lift. (2) The per-user keys used a literal id `1`, which belongs to a different user
depending on suite order; the test passed alone and failed in the file. Both are the kind of green
that means nothing.

**`tests/Feature/Review/AccountStatusBanServiceTest.php` (new, 6 tests):** ban writes status and every
ban field; a permanent ban never carries an expiry (`banHasExpired()` treats a missing expiry as
"never lifts"); unban clears everything and returns to LOGGED_OUT; **every ban-dependent cache is
cleared by the auto-lift path**; the lift refuses when the ban has not expired; it never touches a
permanent ban; the enum keeps its persisted integer values.

**Needle: removing the admin-cache busting from the auto-lift path - i.e. reproducing the original
defect - fails the suite (1 failure), byte-identical restore.** The test detects the exact bug this
task fixed.

**No regression.** Identity floor (AdminBanControllerTest, Unit/Middleware, Feature/Auth, the two auth
review tests, BoundaryDependencyTest) = 183 tests / 2 failures, both the long-recorded pre-existing
pair (`test_using_refresh_token_as_access_token_returns_401`,
`test_staff_refresh_token_rejected_with_token_type_invalid`).

**Genuinely unverified.** `AdminBanController::formatUserStatus()` and `PassengerProfileController`
still map the status with their own `match` on the raw int - they are read-only display mappers, not
writers, so they are safe, but they are the remaining places a new AccountStatus case would have to be
added. The concurrency claim about `lockForUpdate()` is reasoned, not load-tested.

---

## 56. un3 (money foundation) - PARTIAL: `wallets.kind` built and verified; double-entry and thresholds NOT built - **PARTIAL**

**Owner ruling (sec 40.1, Option A):** "kind + double-entry + thresholds". Authorization for the batch on
record. **This task deliberately delivers the foundation only**, and the reason is measured, not
timid.

**SCOPE, MEASURED BEFORE WRITING ANY CODE.** A true double-entry ledger means rewriting **31**
`WalletTransaction::create` call sites (WalletTransactionService, CashRideFeeService,
AdminWalletService, AdminWalletRequestController, PassengerProfileController) plus **27** direct
`->balance` mutations, on the money path, unattended. That is the single largest change in the whole
batch. Half a ledger rewrite is worse than none: a ledger whose legs do not balance is more
dangerous than the single-sided one it replaced, because it LOOKS audited. **Double-entry legs and
balance thresholds are therefore NOT done**, and are recorded here as the remaining work rather than
half-built.

**WHAT WAS BUILT - `wallets.kind`.** A wallet's role was inferred from `user_id IS NULL`, which made
"is this the escrow sink?" a question about DATA rather than TYPE. That is what forced
`lockWalletByPhone` into a defensive filter + re-check (the RV-21 hijack defence). Now:
- `WalletKind` enum (`user` | `system`), `wallets.kind` VARCHAR(16) with a migration that backfills
  from the SAME configured phones the code already uses - not from a guess.
- `Wallet` casts it; `lockWalletByPhone` checks `kind = system` as the PRIMARY condition and KEEPS
  `user_id IS NULL` as a second independent one, so the RV-21 defence no longer rests on one column.

**THE FINDING THAT CHANGED THE DESIGN: a column default is not enough.** With `kind` defaulting to
`user`, the money floor produced **46 errors**. Eight test files hand-roll their own system-wallet
seeding (`Wallet::create(['user_id' => null, 'phone' => ...])`) instead of using the shared trait, so
every one produced a `kind = user` wallet and `lockWalletByPhone` failed closed with "System wallet
not found". Editing eight tests to add one field would have left the same trap for the next person.

**So the column is kept honest BY THE DATABASE**: two triggers (`wallets_kind_matches_owner`,
`..._update`) set `kind` from `user_id` on every INSERT and UPDATE. An ownerless row is always
`system`; a row that acquires a user is always `user`. Proven with a hand-rolled insert that
*declared* `kind = user` with `user_id IS NULL` and came back `system`. This is the RV-21 hijack
defence expressed as a schema rule rather than as a filter somebody must remember to write.

**Verified BOTH directions with real data.** Up: column + 2 triggers + backfill (the two configured
phones became `system`, a user wallet stayed `user`). Down: triggers dropped FIRST, then the column
(so a rollback can never leave a trigger referencing a dropped column). Up again: both restored.
Note `--step=1` does NOT reach this migration's batch; it needs `--batch=<n>`.

**No regression.** Money floor (Feature/Payment, Feature/Wallet, RV40 backfill + snapshot,
StaffCancellationRefund) = 74 tests, **46 errors -> 0**, leaving only the 3 long-recorded OTP
`WalletTest` failures.

**Three of my own mistakes, caught by running rather than reasoning:**
1. The trait edit deleted the pre-existing `seedSystemWallets()` method and corrupted the docblock
   encoding, because I used a line-range rewrite. Reverted with `git checkout` and redone with a
   targeted edit; the diff is now ONLY the `kind` additions.
2. My first trigger test "failed" because of PowerShell quoting, and I nearly concluded the SQL was
   invalid. The SQL was always fine - proven by bisecting to a `DELIMITER` file.
3. `--step=1` rollback silently did nothing because the migration is not in the last batch. That
   looked like a broken `down()`; it was a wrong invocation.

**Genuinely unverified.** The triggers are MySQL-specific (no SQLite equivalent) - the suite runs
MySQL, so this is not exercised anywhere else. They add a per-write cost that has not been measured.
`AdminReportService` still selects the platform wallet by `whereNull('user_id')` and was NOT migrated
to `kind`; it is correct today (the trigger keeps the two in agreement) but is the next place to
convert. **The double-entry ledger and balance thresholds remain unbuilt** - that is the honest state
of this decision, and it should be its own task with its own review, not a tail on a foundation PR.

---

## 57. un3 remainder - double-entry ledger (foundation + reference path) - **PARTIAL (by design)**

Section 56 shipped `wallets.kind` and declined to build double-entry in the same pass. The owner said
continue, so this is that work - done as a FOUNDATION plus ONE converted path, with the invariant
proven, rather than as the 31-site rewrite section 56 warned against.

**THE INVARIANT, which is the whole point:** *the signed legs of a transfer sum to zero.* That is the
one rule that makes a ledger trustworthy, and it is checkable. `wallet_transactions` is SINGLE-SIDED
(one row per wallet that moved) and therefore cannot express it - the escrow charge writes a -100 for
the passenger and a +100 for SyCash as two unrelated rows, so nothing detects it if only one is ever
written. These legs state they are two halves of ONE movement.

**Delivered.**
- `ledger_entries` table: one row per leg, `wallet_id`, nullable `wallet_transaction_id`, signed
  `amount`. **No `direction` column** - the sign IS the direction, and two representations of one
  fact eventually disagree. Legs rather than a from/to pair because real flows are not two-party
  (the 95/5 split, a refund fanning out over many passengers).
- `LedgerService::postTransfer()` **refuses** any transfer whose legs do not sum to zero, and refuses
  a single leg (that is precisely the single-sided record double-entry replaces). Sums are rounded to
  2dp first, so 10.005 + (-10.005) is not rejected for "not balancing".
- `WalletTransactionService::chargePassengerForBooking` converted as the reference path, with
  `LedgerService` injected via a constructor (it had none) rather than an `app()` call on the money
  path.

**THE DESIGN DECISION THAT MAKES THIS SAFE: the ledger is a WITNESS, not a second mover of money.**
`postTransfer()` records WHAT MOVED; it never adjusts balances. The existing code still does exactly
what it did, in its own transaction with its own locking. So converting a path cannot change a single
balance or a single existing row - which is asserted explicitly, because "additive" is a claim that
needs a test.

**`tests/Feature/Review/DoubleEntryLedgerTest.php` (new, 5 tests):** a charge writes exactly two legs
summing to zero, with the debit on the passenger and the credit on SyCash; balances and the two
existing rows are UNCHANGED; an unbalanced transfer is refused AND leaves no rows behind; a single leg
is refused; a three-way 95/5 split still balances.

**Needles, both biting, both restoring byte-identical:** removing the balance guard in
`LedgerService` -> 1 failure; changing the escrow leg amount by 2% (a plausible off-by-one in a
settlement) -> 2 errors. The test detects a half-written movement in the real money path.

**No regression.** Money floor (Payment, Wallet, RV40 backfill + snapshot, staff-cancel refund, the
new ledger tests) = 79 tests, only the 3 long-recorded OTP `WalletTest` failures. Migration verified
BOTH directions on the scratch DB (table dropped, then restored).

**Still NOT built - the remaining money paths.** 30 of the 31 `WalletTransaction::create` sites are
unchanged: settlement, the 95/5 split, every refund path, wallet creation, fee creation and refunds,
withdrawals. They are now MECHANICAL: add a `postTransfer()` call alongside the existing writes. Each
still needs the check that the legs agree with what the balances actually did - that is the work, and
until it is done the ledger is a partial witness, not a complete one. Listed in BACKLOG as the
remaining RV-21 blocker.

**Genuinely unverified.** Only the escrow path writes legs, so a query of `ledger_entries` today
returns a fraction of real movements and must not be read as "the ledger says no money moved". The
`ledger_entries` table is not yet reconciled against `wallet_transactions`, so there is no job proving
the two agree across all history.

### 57.1 Double-entry extended: the 95/5 settlement split + both refund fan-outs - **VERIFIED FIX**

Section 57 landed the ledger and converted ONE path. This adds the three that matter most, chosen
because they are the ones a single-sided ledger gets wrong least visibly.

**`releaseEarningsToDriver` — the 95/5 split (the case the ledger exists for).** SyCash gives, the
driver receives 95%, the platform 5%: ONE event with TWO outcomes. The three single-sided rows above
it cannot state that they belong together, and a `from`/`to` pair of columns could not express it at
all - which is exactly why the ledger is N legs per transfer. It is also where arithmetic drift would
hide: if the shares ever stopped summing to `$total`, a single-sided ledger records a
plausible-looking payout while creating or destroying money. `postTransfer` refuses instead.

**`refundPassengersForDriverCancellation` and `refundPassengersForStaffCancellation` — the fan-outs.**
SyCash gives once, N passengers receive. Posted as ONE transfer with N+1 legs, NOT one transfer per
passenger: a refund to five people is a single event with six legs. The sum check is what makes the
fan-out safe, because it proves the refunds can never exceed what SyCash actually gave.

**Deliberately unchanged: the REFUND AMOUNT.** `refundPassengersForDriverCancellation` still derives
from `seats * ride.price_per_seat`, not the RV-40 `amount_paid` snapshot. That is decision 3's
territory (cancellation money policy, owner-flagged "reconsider"). Recording what the code actually
did is not the same as deciding what it should have done, and the two are separate decisions.

**`tests/Feature/Review/DoubleEntryLedgerTest.php` (6 tests now).** The new one asserts the 95/5 split
writes exactly three legs summing to zero, with the escrow leaving SyCash at -20,000, the driver at
19,000 and the platform at 1,000 - so the split is pinned numerically, not just structurally.

**Two fixture mistakes I made and fixed while writing it:** a `forceFill(['id' => null])` to "reset"
a wallet (which is nonsense - the column is a non-null primary key), and passing a support Collection
where the service is typed for an Eloquent Collection. Both caught by running the test.

**No regression.** Money floor (Payment, Wallet, RV40 backfill + snapshot, staff-cancel refund,
ledger tests) = 80 tests, only the 3 long-recorded OTP `WalletTest` failures.

**Still unconverted (recorded, not hidden):** `processTimeBasedCancellation`,
`processPassengerNoShow`, `processDriverNoShowRefund`, `releaseEscrowToDriver`, and everything in
`CashRideFeeService` (fee charge, fee refund, debt auto-clear) plus `AdminWalletService`,
`AdminWalletRequestController` and `PassengerProfileController`. The ledger is still a PARTIAL witness
for those flows until each posts legs and each is checked against what the balances did.

### 57.2 WalletTransactionService fully converted (8/8 methods) - **VERIFIED FIX**

Completes the largest money file. All eight methods now post balanced double-entry legs:
`chargePassengerForBooking`, `releaseEarningsToDriver`, `refundPassengersForDriverCancellation`,
`refundPassengersForStaffCancellation`, `processTimeBasedCancellation`, `processPassengerNoShow`,
`processDriverNoShowRefund`, `releaseEscrowToDriver`.

**Two shapes, because the flows genuinely differ:**
- **Three-party splits** (`releaseEarningsToDriver`, `processPassengerNoShow`, `releaseEscrowToDriver`)
  - SyCash gives, driver 95%, platform 5%.
- **Two-party refunds** (`processDriverNoShowRefund`, and the two cancellation fan-outs) - one
  transfer, N+1 legs.
- **Conditional three-party** (`processTimeBasedCancellation`) - the passenger refund and the driver
  compensation are each posted ONLY when non-zero. A 0.00 leg would still balance, so posting one
  would be misleading rather than wrong; skipping it keeps the ledger readable.

**`releaseEscrowToDriver` is where the ledger earns its keep.** That method computes the platform share
by SUBTRACTING (`$total - $driverShare`) rather than multiplying by 0.05, specifically to dodge float
drift. In the balances that care is invisible; in the ledger it becomes checkable - if the shares ever
stop summing to the total, the legs do not balance and `postTransfer` REFUSES the transfer instead of
quietly creating or destroying money.

**No regression.** Money floor (Payment, Wallet, RV40 x2, staff-cancel refund, ledger tests, plus
`tests/Unit/Services/Payment`) = 100 tests, 8 failures, ALL confirmed in the recorded pre-existing
baseline (3 OTP `WalletTest` + 5 `CashRideFeeServiceTest` refund-tier). `CashRideFeeService` itself is
untouched by this work.

**Still unconverted.** `CashRideFeeService` (fee charge, fee refund, debt auto-clear),
`AdminWalletService`, `AdminWalletRequestController`, `PassengerProfileController`. The ledger is a
partial witness for those flows; they remain listed as the RV-21 blocker.

### 57.3 CashRideFeeService converted (3/3 money methods) - **VERIFIED FIX**

The last money service. `chargeCashRideCreationFee`, `refundCashRideCreationFee` and `autoClearDebt`
now post balanced legs.

**The DEFERRED branch deliberately posts NO legs, and that is the interesting decision here.** When a
driver cannot afford the cash-creation fee it is not charged - it is added to `cash_ride_debt` and the
balance row records `amount = 0`. No money moved, so there is nothing to post: a 0.00 transfer is not a
transfer, and recording legs for it would pad the ledger with entries that assert nothing happened.
Debt is an OBLIGATION; it becomes a real movement when `autoClearDebt` collects it after a top-up -
and that is exactly where its leg pair is posted, which ties the two halves of the same obligation
together across time.

**The partial refund is recorded as what moved, not as what was originally charged.** Only
`$refundAmount` returns to the driver; the platform keeps `$platformKeeps`. The legs sum to the refund,
not the fee, because those are the two amounts that actually crossed.

**Regression check done properly.** The `CashRideFeeServiceTest` run showed 5 failures. Rather than
assume, I bisected with `git stash` and ran the file against the committed version: **the same 5
fail, identically.** They are in the recorded pre-existing baseline (3 refund-tier + 2 deferred-refund
debt tests) and are not caused by this work. An earlier bisect attempt that stripped the ledger calls
by line range produced invalid PHP and proved nothing - it was discarded rather than reported.

**Still unconverted (the remainder of RV-21).** `AdminWalletService`,
`AdminWalletRequestController` and `PassengerProfileController` - administrative and reporting money
movements. Every RIDE money movement now has balanced legs; wallet-creation top-ups and the
passenger-facing wallet transaction list do not yet.

### 57.4 The ledger is now PROVEN to explain the balances, not merely to balance

**The gap this closes, stated plainly.** `LedgerService::postTransfer` checks that a transfer's legs
sum to zero. That is necessary but NOT sufficient. A transfer could post beautiful, balanced legs
describing a completely different amount from the one the balances actually moved, and nothing would
notice - because the sum is zero either way. The ledger would be a confident lie.

**Balances are the thing customers hold. The ledger is the explanation.** If they ever disagree, the
explanation is worthless, so that agreement is the property worth having.

**The new test walks a FULL ride lifecycle** - the escrow charge and then the 95/5 settlement - and
for every wallet touched asserts that the ledger legs **exactly equal the balance change**. Not
approximately: exactly. It also asserts the whole system conserves (all legs sum to zero across every
wallet). So "the ledger explains the money" is now a verified property rather than an intention.

**Needle:** making the legs describe a different amount than the balance moved (the passenger debit
scaled by 0.9, so the legs still balance against a *consistent* SyCash leg) fails the test. This is
precisely the failure mode the previous tests could not see - the transfer remains perfectly balanced,
and the ledger still lies. Restored byte-identically.

**Money floor:** 94 tests, 8 failures, all in the recorded pre-existing baseline (5
`CashRideFeeServiceTest` + 3 OTP `WalletTest`).

**The design question is now EVIDENCED, not assumed.** All three unconverted sites were read:
`AdminWalletService::chargeWallet` (admin credit, no debit anywhere),
`AdminWalletRequestController` (top-up or **withdrawal** processed by admin - money leaving to
outside), and `PassengerProfileController::chargeWallet` (admin credit). Every one is an EXTERNAL
inflow/outflow with no internal counterparty. Posting a synthetic leg to force each to balance would
corrupt what the ledger represents, so they stay unconverted pending the owner's answer on whether
this ledger models external flows at all.

### 57.5 `ledger:reconcile` - the invariant, runnable against real data on demand

The reconciliation exists as a test, which proves it for a controlled scenario. A test cannot watch
production. `ledger:reconcile` is the same check against real data, and it is scheduled daily at
04:30 (low-traffic window, matching the other cleanup jobs).

**IT REPORTS INCOMPLETENESS RATHER THAN HIDING IT.** A wallet whose balance moved with no legs is
printed as `unexplained`, with an explicit note that this is EXPECTED for the admin wallet paths
(external credit / withdrawal) and UNEXPECTED anywhere else - a converted money path that moved a
balance without posting legs.

**It checks system conservation first, and returns a non-zero exit code if that fails**, because
every per-wallet row is meaningless if the ledger does not balance overall. A broken ledger should
stop the reading, not decorate it.

**Verified end-to-end inside a transaction** with real legs present: the command exits 0, and the
per-wallet comparison it reports on genuinely holds for the escrow charge. `tests/Feature/Review/
DoubleEntryLedgerTest.php` is now 8 tests.

**Why a money check is scheduled rather than only tested:** a money bug found a day later is a money
bug that has already been paid out.

---

## 58. Triage of the Larastan report (un8 follow-up) - 48 false positives eliminated by ONE mechanical cause - **VERIFIED FIX**

The audit deferred Larastan because level 5 over this codebase "produces noise" (R1 sec 6). That noise
was measured but never explained. This explains it, and removes a fifth of it.

**THE FINDING.** 54 of the original 283 findings were `rules.relationExistence` - "Relation 'profile'
is not found in App\Models\User model" - for relations that **all exist**. Larastan resolves relations
that declare a RETURN TYPE (`public function ride(): BelongsTo`) and cannot resolve ones that do not
(`public function profile()`). The codebase had 21 untyped relation methods across 8 models; every
one was a false positive.

**Adding the return types took the report from 283 to 235 findings, and `relationExistence` from 54 to
5** (48 eliminated). The 5 survivors are relations read off a *generic* `Model` - a join result, where
the type genuinely is not `User` - so those are honest low-confidence findings, not errors.

This is also a real code-quality improvement, not just analyser appeasement: a typed relation makes a
misspelled relation a static error instead of a `null` that propagates silently.

**WHAT THE REMAINING 235 ACTUALLY ARE** - the useful part, because now the noise is gone:
- **120 `property.notFound`** - 36 on a generic `Illuminate\Database\Eloquent\Model` and the rest in API
  Resources. These are Eloquent's DYNAMIC property access (any column or accessor), which Larastan
  cannot model without per-model `@property` annotations. Largely inherent, and the honest reason a
  baseline is still the right end state.
- **33 `assign.propertyType` + 28 `return.type` + 16 `argument.type`** - TYPE MISMATCHES. In a money
  application this is the genuinely valuable class, and it is now visible because the false positives
  no longer bury it. **This is the first triage finding worth acting on, and it is untouched.**

**No regression.** Rides + Bookings + Review + Unit/Models + Profile = 509 tests, 15 red with AND
without the change - bisected with `git stash` on `app/Models`, **0 new failures**, identical failure
set. All 8 models lint clean.

### 58.1 What the remaining 235 findings actually are - and why a BASELINE is the right end state

The triage continued, because "48 false positives removed" is only useful if the rest is understood.

**`argument.type` (16) - read, and BENIGN.** Nearly all are `Money::from()` receiving a string where the
signature says `float`. That is Laravel's `decimal:2` cast (which returns `"19.00"`), and a 2-decimal
value coerces to a double EXACTLY at this magnitude: the column is `DECIMAL(15,2)` (max ~9.99e12),
comfortably inside the 2^53 (~9.0e15) range where doubles represent integers exactly. **No precision
loss, no bug.** Verified rather than assumed.

**One finding IS in this batch's own code** - `WalletTransactionService::filter()` receiving a
`Closure(Booking)` where the collection's element type is a generic `Model`. It works at runtime and,
if a non-Booking ever entered, it would raise a `TypeError` rather than silently mishandle money -
which is fail-loud and therefore the right behaviour. Recorded, not "fixed".

**So the remaining 235 decompose into:**
- **120 `property.notFound`** - Eloquent's dynamic property access (any column or accessor). Larastan
  cannot model it without per-model `@property` annotations; this is inherent to a dynamic ORM, not a
  defect.
- **~77 type mismatches (`assign.propertyType`, `return.type`, `argument.type`)** - sampled and read:
  benign numeric-string coercions, not defects.
- **5 `relationExistence`** - relations read off a generic `Model` from a join.

**CONCLUSION, and it vindicates the audit's instinct.** The level-5 report is now mostly
*Larastan modelling Eloquent*, not bugs. Generating a baseline now would be defensible - but it would
also freeze the three classes a careful reviewer might still want, so the honest recommendation is:
**read the report once more specifically for `assign.propertyType` in `app/Services/Payment`, where a
type mismatch WOULD be a real money bug, and baseline the rest.** The owner's ruling was report-only
until cleanup, and this is the cleanup evidence.

---

## 59. RV-03 role gate - staff-cancel restricted to admin/system_admin (owner choice (a)) - **VERIFIED FIX**

Closes the second half of RV-03, which section 47 raised and deliberately left open. **Owner choice (a),
2026-10-03: "Restrict to admin + system_admin"**, over (b) accept the risk and (c) four-eyes
approval.

**THE FINDING IT CLOSES.** The two staff-cancel endpoints MOVE REAL MONEY - decision 6 made them
issue full passenger refunds out of SyCash escrow - yet they sat in the blanket
`['staff','throttle:staff']` group, so **ANY authenticated employee, including the least-privileged
`support_agent`, could refund a customer's money.** That is the "no role gate" half of RV-03, named in
the original audit and untouched until now.

**The gate matches every other money-affecting admin route in `routes/api.php`**
(`staff:admin,system_admin`), so this is consistency rather than a new policy. Changing it is an
auth/permission change, which `AGENTS.md` reserves for the owner - hence the explicit attribution.

**Test strategy - the important part.** `StaffOperationsControllerTest` runs as a **SUPPORT_AGENT on
purpose**, so it proves the ~54 read endpoints stay open to the least-privileged role. The 26 cancel
call sites were moved to a new `$this->adminToken` (an ADMIN employee), and TWO NEW tests pin the
decision itself:
- `a_support_agent_cannot_use_the_money_endpoints` - 403 on both endpoints, AND the ride and booking
  are asserted untouched, so the denial is shown to have moved no money.
- `the_role_check_runs_before_the_state_check` - an unprivileged caller gets 403 rather than the 422
  "cannot cancel a ride with status: ...", so the gate leaks no information about a ride's existence
  or state.

**A REAL BEHAVIOURAL CHANGE, stated plainly:** the two `..._returns_404_for_nonexistent_*` tests
previously used the support agent and expected 404. They now use the admin token, because a 404 for a
nonexistent ride is only a meaningful answer for an AUTHORISED caller - an unprivileged one is (and
should be) stopped at 403 first.

**Needle:** widening the gate to `staff` (i.e. removing the restriction) fails both new tests,
byte-identical restore. An earlier needle attempt replaced the middleware call with `if (true) {`,
which produced invalid PHP - that failure proved nothing and was discarded rather than reported.

**82 tests, 203 assertions, OK.**

---

## 60. External capital account - the ledger is now CLOSED, not mostly-right (owner choice (a)) - **VERIFIED FIX**

Closes the ledger boundary question recorded in sections 56-57. **Owner choice (a), 2026-10-03: "Add
an EXTERNAL capital account"**, over (b) keeping admin flows excluded and (c) balancing them against
the Primary Admin wallet.

**WHAT IT CLOSES.** Money entering or leaving the platform from outside it - an admin wallet credit
funded by a real cash deposit, a withdrawal paid to a bank account - has no internal counterparty.
Before this it moved a balance with NO ledger entry, so reconciliation had a permanent hole exactly
where money enters the system, and there was no honest way to assert "the ledger explains the money".

**MODELLED AS A THIRD SYSTEM WALLET, deliberately not the Primary Admin wallet.** Money the platform
EARNED and money that ARRIVED are different things; conflating them would make the revenue figure
wrong. `config/admin.php` gains an `external` entry, the seeder creates it, and the shared test trait
seeds it (because `chargeWallet` fails loudly without it).

**An injection DEBITS the external account and credits the user wallet; a withdrawal is the mirror.**
`LedgerService::postExternalTransfer()` derives the signs from a single `$inbound` flag, so the most
common way to write a wrong-sign pair by hand (which earlier in this batch cost me a needle) is
structurally impossible.

**FAILS LOUD if the external account is missing**, rather than silently recording money the ledger
cannot explain - the same principle as `lockWalletByPhone` refusing a user-owned wallet.

**`DoubleEntryLedgerTest` is now 9 tests**, adding
`an_admin_credit_is_recorded_as_an_external_inflow_and_closes_the_ledger`: the user's wallet and the
external account move by exactly opposite amounts, the two legs name the two halves, and all legs sum
to zero.

**Needle:** flipping the sign so the legs stop mirroring fails it, byte-identical restore.

**No regression:** Payment + Wallet + Unit/Payment + Admin + the two ledger/refund review tests = 210
tests, 17 red, **identical with and without the change** (bisected with `git stash`, 0 new failures).

**Still unconverted:** `AdminWalletRequestController` (top-up AND withdrawal) and
`PassengerProfileController::chargeWallet`. They use the same external account and the same helper, so
they are now mechanical conversions - but they were NOT converted here, so the ledger is closed for
`AdminWalletService` and still open for those two. Recorded honestly rather than implied done.

### 60.1 The last two external paths converted - the ledger is CLOSED - **VERIFIED FIX**

`AdminWalletRequestController` (top-up **and** withdrawal) and `PassengerProfileController::chargeWallet`
now record against the External Capital account, completing section 60. **There is no longer any money
movement in the application that the ledger cannot explain.**

The withdrawal direction is derived from `$transactionAmount`'s existing sign rather than re-decided,
so the two halves cannot disagree.

**TWO FIXTURES FIXED, AND THEY WERE A REAL SIGNAL.** The first run produced 4 new failures, all
`External Capital wallet not found ... Run: php artisan db:seed --class=SystemWalletSeeder`. Those two
test files hand-roll their wallets instead of using the shared trait, so the account was absent - and
the charge path failed LOUD rather than silently recording unexplainable money. **The loud failure was
the designed behaviour working**; the fix belongs in the fixtures, not in weakening the guard to tolerate
a missing account (which is what "make the test pass" would have meant here).

**No regression:** Payment + Wallet + Unit/Payment + Admin + Profile + the ledger tests = 216 tests, 20
red, **identical with and without the change** (git-stash bisect, 0 new).

---

## 61. Closing the PARTIAL rows - what was genuinely closable, and what was not - **PARTIAL**

The owner asked to close the PARTIAL backlog. Each row was checked against the CODE before being
marked, and the result is deliberately not "all closed".

### 61.1 RV-21 (Wallet identity and money creation) - **VERIFIED FIX**

Its ONLY blocker was "double-entry ledger (un3 remainder)", which sections 57-60.1 completed. Verified
directly: every money-writing site now has a ledger call -
`WalletTransactionService` (12), `CashRideFeeService` (3), `AdminWalletService` (1),
`AdminWalletRequestController` (1), `PassengerProfileController` (1) - and **there is no longer any money
movement in the application the ledger cannot explain.**

### 61.2 RV-12 (Account status model) - a REAL DEAD DEFENCE found and fixed, but still PARTIAL

Checking RV-12's recorded remainder surfaced a genuine bug, not just bookkeeping.

**THE BUG.** `SignupController` creates accounts with `'status' => 0` and carries the comment:
*"user must verify email before they can log in... starting at 0 adds a second layer of defence"*.
`UserRepository::createUser()` then **hardcoded `'status' => 1`, silently discarding it.** So every
self-registered account started ACTIVE and the declared second layer did not exist. `SignupPassword
OverwriteTest` had documented this as "a separate pre-existing defect... recorded as a new finding" and
**asserted the buggy value** (`assertSame(1, ...)`) to document it.

**THE FIX.** `createUser` now honours the caller's status and defaults to
`AccountStatus::LOGGED_OUT`, so the sign-up defence is live. `AGENTS.md` says never edit a test to make
it pass; this is the opposite case - the test pinned a KNOWN DEFECT, so pinning the fix is correct. That
is stated in the test's comment so the next reader knows the assertion moved with a real fix.

**Why RV-12 stays PARTIAL.** R1's larger model change - dropping the persisted "logged-out" status=0
entirely - is NOT done, and should not be: `AccountStatus::LOGGED_OUT` is a real, meaningful state (a
fresh signup, a just-unbanned account), and removing it is a product-semantics decision, not a
cleanup. Recorded as the remaining item rather than quietly closed.

### 61.3 Two rows that CANNOT be closed by me

- **RV-11 (Score subsystem)** - blocked by **decision 3** (the cancellation money policy), which the
  owner explicitly flagged "important to be reconsidered". Blocked on the owner, not on work.
- **RV-29 (Auth hardening)** - blocked by **decision 5** (driver-phone visibility), which the owner
  DEFERRED ("this needs more thinking"). Blocked on the owner.

**No new failures:** Auth + Staff + Review + Security = 7 red both with and without the `createUser`
change (git-stash bisect).

---

## 62. RV-14 - `create-with-route` now validates like `create` - **VERIFIED FIX** (one half; `distance`/`duration` units still open)

Closing the PARTIAL backlog surfaced a LIVE defect rather than paperwork.

**THE DEFECT.** `POST /rides/create-with-route` carried its own inline rule set while `POST /rides`
used `CreateRideRequest`, and the two had **drifted apart in ways that mattered**:

| Field | `CreateRideRequest` | `create-with-route` (inline) |
|---|---|---|
| `price_per_seat` | `min:100\|max:100000` | `min:0` - **no bound at all** |
| `communication_number` | `regex:/^09\d{8}$/` | `string` - **no format check** |
| `notes` | `max:500` | `max:1000` |

**The weaker endpoint was the live one.** A ride could be created through `create-with-route` for a
price of **0**, or with **"banana"** as the contact number, while the same request through `POST /rides`
was correctly rejected. That is RV-14's "lying endpoints" exactly: two routes for one action
answering to different rules.

**Fix.** `createRideWithRoute` now injects `CreateRideRequest`. The item was explicitly waiting on the
price bound landing first (`rides.price_per_seat` is now `decimal(15,2)`), and it has.

**A SECOND, PRE-EXISTING 500 found while testing it, and fixed.** A perfectly valid request returned
`500 Column 'chosen_route_index' cannot be null`: `CreateRideDTO::toArray()` wrote
`chosen_route_index => $this->chosenRouteIndex`, which is **null** whenever the client sends no
`route_index` - against a column declared `NOT NULL DEFAULT 0`. Bisected with `git stash` and
confirmed **identical with and without** the parity change, i.e. it predates this task. Fixed by
coalescing to `?? 0`, which is the column's declared default rather than a workaround; the DEFAULT
was simply never reachable on this path.

**`tests/Feature/Review/RideValidationParityTest.php` (new, 5 tests)** pins the parity: a zero price
rejected, an above-bound price rejected, a malformed contact number rejected, the endpoint still
routed, and a valid payload accepted.

**Needle:** restoring the weak inline rules fails 4 of the 5, byte-identical restore.

**Two fixture mistakes of my own, both caught by running:** declaring `SeedsSystemWallets` without
CALLING it (the cash-ride fee then failed with "Primary Admin wallet not found"), and a driver fixture
with no wallet ("You must create a wallet before creating a cash ride" - a real, separate gate).

**OWNER-FILE HANDLING.** `RideController.php` is owner-owned (two RV-38 eager-load edits). Per the
section 43.1 rule, the commit was built from a HEAD reconstruction carrying ONLY the parity change
(verified: my change present, owner's eager-loads absent), and the owner's edits were restored to the
working tree afterwards.

**No regression:** Rides + the parity tests = 94 tests, 4 red, all the recorded baseline.

**Still open in RV-14:** the `rides.distance` / `rides.duration` unit disagreement is a **units
decision** (fixtures insert 320.5 and 320500 into columns commented "Meters"/"Seconds"). The columns
are now `int unsigned` with explicit unit comments, but normalising the fixtures needs the owner's
ruling on which unit each field is meant to hold.

### 29.3.1 RV-17 - provenance + database-pressure capture (the two remaining spec items) - **PARTIAL (closed as far as it can be here)**

RV-17's recorded remainder had two parts. The `setup()`-login, constant-arrival-rate, 3-run and
per-endpoint-threshold items were delivered in section 51 (un12). This closes the last two that can be
built without a running system.

**1. RESULTS TIED TO A COMMIT.** RV-17: "committing result JSON with the git SHA". A performance number
that cannot be tied to a version of the code is not evidence - and that is exactly how the historical
A/B/C numbers (518 -> 538 rps, "B to C shows no gain") became unfalsifiable. `perf-3run.js` now takes
`K6_GIT_SHA` / `K6_GIT_BRANCH`, prints them in the setup banner, and - importantly - prints a loud
warning in the TEARDOWN when the SHA is absent. The warning repeats at the end on purpose: that is
where someone copying numbers off the log will actually see it.

**2. DATABASE PRESSURE CAPTURE.** RV-17 asks for `threads_running` / lock-wait alongside the HTTP
numbers. k6 speaks HTTP only, so this cannot live in the k6 script: `dbwatch.sh` samples
`SHOW GLOBAL STATUS` on an interval and writes CSV next to the HTTP summary. It reports the DELTA of
the monotonic counters rather than their totals (cumulative totals look like rising pressure on a long
run when they are just accumulated), and it prints a pointed warning when `Innodb_row_lock_waits`
climbs: an HTTP p95 cannot distinguish "the application is slow" from "the database is serialising on
a lock", and the escrow and 95/5 settlement paths both take row locks, so those are fixed in different
places.

**A UNIT BUG FOUND IN MY OWN SCRIPT.** The first draft divided `Innodb_row_lock_time` by 1000 while
labelling the column `row_lock_time_ms`. MySQL already reports that counter in milliseconds, so the
script was publishing seconds under a millisecond heading. A numbers tool must not disagree with its
own units; fixed, and the reason is in a comment so it is not "tidied" back.

**Credentials never touch the file** (the RV-18 lesson: a real API key was once committed in
phpunit.xml, and this reads the production database). `DB_PASSWORD` is required from the environment
and passed via `MYSQL_PWD`, so it does not appear in `ps`.

**VERIFICATION, and its limit.** `node --check` passes on `perf-3run.js` (exit 0). **`dbwatch.sh` could
NOT be syntax-checked: bash is unavailable in this environment** (WSL has no installed distro, and
Git-for-Windows bash is not on PATH). It was reviewed by hand and its braces/parens balance
(35/35, 33/33) and non-ASCII characters were normalised, but **it has not been executed even once.**
That is recorded as genuinely unverified rather than glossed.

**Still open in RV-17, and NOT closable here.** "Regenerating README numbers from a corrected run"
requires running a corrected load test against a production-shaped system, which this environment
cannot do. The historical `perf-results/{A,B,C}` remain INVALID and must not be cited as capacity
until a run exists that carries its commit SHA.

---

## 63. RV-23 (Complaints) - **VERIFIED FIX** - the three recorded remainders were already satisfied

The last item this batch could close honestly. RV-23's remainder listed three things. Each was
CHECKED AGAINST THE CODE rather than assumed, and all three hold:

**(a) `GET /staff/complaints/{id}` mutates state (auto-transition + assignment).** This is **not a
defect** - the owner ruled it intended (un6, 2026-10-02): *"auto transition is true i want when the
employee sees it that the user know that it has been seen this is a part of the transparency for the
public"*. Recorded as a decision, not left as an open finding.

**(b) "double notification" on a no-show conflict.** Checked: there is exactly **one**
`createNotification` for the passenger and **one** for the driver - each party is notified once. Both
receive the same text, which is correct for a conflict they are both party to. R1's "one notification"
concern does not describe a defect here. **No change needed.**

**(c) `no_show` rejected at the public store endpoint.** Confirmed in `ComplaintController`'s
validation `in:` list, and it is **already pinned** by
`ComplaintControllerTest::test_store_rejects_the_internal_no_show_type`, whose own docblock cites the
owner's 2026-10-02 decision (R2 sec 40.3) and explains that `no_show` is an internal auto-generated
type. **No change needed.**

So all three were either an owner decision already taken, or a reading of the code that does not
reproduce. **No code was changed for this row** - the deliverable is the verification, recorded so the
next session does not re-open it.

**Verification:** `tests/Feature/Complaints` + `tests/Feature/Staff/StaffComplaintControllerTest` =
**73 tests, 180 assertions, OK.**

---

## 64. RV-13 foundation - domain rule violations stop answering 500 - **VERIFIED FIX**

The first unblocked half of RV-13's error model.

**THE DEFECT (R2 sec 20.1, measured): 61 services throw `\InvalidArgumentException`**, and `Handler`'s
catch-all has no branch for it, so every one that reached the handler was answered **500**. A passenger
confirming someone else's booking, or a driver creating a ride they are not verified to create, is a
rejected REQUEST - the server did not fail. Reporting it as 500 is wrong three times over: the client
cannot distinguish a refusal from a fault, error monitoring drowns real 500s in rule rejections, and
retry-on-5xx logic hammers an endpoint that will never succeed.

**Delivered.**
- `App\Exceptions\Domain\*`: an abstract `DomainException` carrying a machine-readable `errorCode` and
  its own `httpStatus`, plus `BusinessRuleViolation` (422), `AuthorizationViolation` (403) and
  `ConflictViolation` (409). The status is NOT uniform - "you may not touch that" is 403,
  "there is not enough money" is 409, "that field is unacceptable" is 422 - and carrying it on the
  exception lets each rule state its own answer instead of the handler guessing one for all of them,
  which is exactly the guessing that produced the blanket 500.
- `Handler`: two renderables registered **before** the catch-all. The bare
  `\InvalidArgumentException` is mapped too, deliberately - migrating 61 throw sites is a large sweep,
  and mapping the base class removes the worst symptom today rather than after a refactor.

**A GENUINE BUG IS STILL A 500.** `a_genuine_bug_is_still_500` pins this. Mapping rule violations
correctly is only safe if real faults are not swept into the same bucket; otherwise this change would
have hidden actual failures.

**`tests/Feature/Review/DomainExceptionMappingTest.php` (new, 6 tests)** pins the mapping through a
throwaway `api/`-prefixed route, so the assertion is about the EXCEPTION MAPPING and cannot be
disturbed by whatever a real endpoint does with an exception.

**Needle:** deleting the `DomainException` renderable fails 3 of the 6, byte-identical restore.

**Measured effect: the Auth + Bookings + Rides + Unit/Http + this file floor went from 9 red to 4.**
**Zero new failures** (git-stash bisect), and **five pre-existing failures FIXED** - endpoints that
were returning 500 for a domain rule violation and now answer 422. That is the defect closing.

**Still open in RV-13, and deliberately not done here.** (a) The 96 `catch` blocks returning raw
`getMessage()` - which leaks SQL text and table names to clients - depend on this foundation landing
first, and are a separate sweep. (b) The `{success,data,error{...}}` envelope is a **PRODUCT
decision**: it changes the response shape the Flutter client parses, and `AGENTS.md` reserves that.
(c) The `assertNotEquals` ratchet stays deferred for the reason R2 sec 20.2 gave - until the wrong
statuses are gone, pinning "the exact status" would enshrine them as correct.

**Genuinely unverified.** No production endpoint was migrated to the new subclasses yet - the 61
`InvalidArgumentException` throw sites still rely on the base-class mapping, which returns a generic
`DOMAIN_RULE_VIOLATION` code rather than a specific one per rule.

### 64.1 RV-13 second half - the exception-message leak is now MEASURED and shrink-only - **VERIFIED FIX (ratchet), sweep recorded**

**THE LEAK, measured rather than estimated.** **47 controller sites** do
`'message' => $e->getMessage()` inside a JSON **response** (RideController 18, EmployeeManagement 7,
Chat 6, WalletRequest 4, Profile 3, StaffAdmin 2, StaffComplaint 2, and one each in Complaint,
AdminWalletRequest, StaffChat, AdminDashboard, Verification). An exception message is not written for a
client: a `QueryException` carries the SQL and the table names, a `ModelNotFoundException` names
internal identifiers, a third-party HTTP client's message can carry an internal URL. Returning it tells
an attacker the shape of the database.

**WHAT IS DELIBERATELY NOT COUNTED**, and why this matters more than the number:
- `Log::error(… $e->getMessage())` - server-side, where the message BELONGS. There are ~120
  `getMessage()` calls in the controllers and most are these; counting them would produce a ratchet
  that only ever says "do not log", which is useless.
- `config('app.debug') ? $e->getMessage() : '…'` - already gated (one site).
- `'message'` built from a DOMAIN message the controller wrote itself ("Only pending bookings can be
  accepted") - that is a client-facing rule, not a leak; section 64's `DomainException` work is how
  those earn an explicit code.

**WHY A RATCHET RATHER THAN A 47-SITE SWEEP.** 47 sites across 12 controllers, changed blind in one
commit, is how a real error gets replaced by a generic string and the client loses its only signal.
The ratchet makes the debt MEASURED and shrink-only, so the sweep can happen in reviewable slices and
the count can never grow back - the same pattern `BoundaryDependencyTest` already uses in this repo.

**`tests/Feature/Review/ExceptionMessageLeakRatchetTest.php` (new, 3 tests):**
- no controller may EXCEED 47 (adding one fails CI);
- the baseline must still be relevant (if it ever hits zero, the ratchet is slack and should be
  retired rather than left enforcing a fictional budget);
- server-side `Log::…getMessage()` is still counted as present - if that ever reaches zero the ratchet
  is over-broad and must be re-scoped before it blocks legitimate logging.

**Needle:** duplicating one existing leak line (a SYNTACTICALLY VALID change - an earlier attempt
inserted a line mid-expression, produced a parse error, and would have "proved" anything) fails the
ratchet; the controller restores byte-identically.

**Still open, and correctly so.** The actual 47-site sweep is not done. It should be done in slices
per controller, replacing each with either an explicit `DomainException` code (section 64) or a
generic message plus a `Log::error` - and the baseline lowered in the SAME commit as each slice, so the
ratchet and the debt move together. The `{success,data,error{…}}` envelope remains a PRODUCT decision
(AGENTS.md; it changes what the Flutter client parses).

### 64.2 RV-13 sweep slice 1 - ChatController (47 -> 42) - **VERIFIED FIX**

The first slice of the 47-site sweep, one controller at a time, with the ratchet baseline lowered in
the SAME commit so the debt and its measurement move together.

**ChatController: 5 of 6 sites fixed.** Each broad `catch (\Exception $e)` now writes the exception
message to `Log::error()` - with `user_id` / `conversation_id` / `message_id` for diagnosis - and
returns a stable sentence to the client. The information is MOVED from the response to the log, not
destroyed: `Failed to fetch conversations: SQLSTATE[42000]...Table 'bookings'` becomes
`Failed to fetch conversations. Please try again.` in the response, and the full SQL is still in the
log where it belongs.

**The 6th site was deliberately NOT changed**, and that judgement is the point of doing this one
controller at a time: it is `catch (HttpException $e) => 'message' => $e->getMessage()`, which is the
application's OWN `abort()` message ("You can only delete your own messages") - written deliberately
for the client. A blind sweep would have sanitised it and removed real, actionable feedback. The
comment added there says so, so the next sweep does not "finish the job" by breaking it.

**Infrastructure note, recorded because it nearly became a wrong conclusion.** The first verification
run reported **17 chat test errors**. Those were NOT the change: the scratch MySQL had stopped
(`mysqld` gone, port 3399 not listening) and every test failed with "No connection could be made".
Per AGENTS.md that is infrastructure and must not be answered with a code change, and per
AGENTS.local.md the agent must not start the database inside its own shell - so the work was left
UNCOMMITTED and reported rather than committed unverified. After the restart the same suite is
**17 tests / 1 failure**, and that single failure
(`ContactControllerTest::test_returns_503_when_agent_employee_exists_but_has_no_user_account`) was
proven **pre-existing** by `git stash` - it fails identically at HEAD and exercises `ContactController`,
not `ChatController`.

**Ratchet:** baseline lowered 47 -> 42 in this commit; `ExceptionMessageLeakRatchetTest` OK (3 tests)
at the new value, which is what proves the sweep actually removed five sites rather than the ratchet
merely being loosened.

**Still open:** 42 sites (RideController 18, EmployeeManagement 7, WalletRequest 4, Profile 3, Staff
Admin 2, StaffComplaint 2, and one each in Verification, AdminDashboard, AdminWalletRequest,
Complaint, StaffChat). Same slice-at-a-time pattern.

### 64.3 The ratchet was OVER-BROAD - it was counting app-authored messages as leaks - **VERIFIED FIX**

Before sweeping the next controller I checked what the remaining sites actually were, and the
classification changed the picture materially.

**All 7 `EmployeeManagementController` sites are `catch (\DomainException $e)` or
`catch (\RuntimeException $e)`** - specific catches around app-authored messages ("An employee with
this email already exists"), returned deliberately to the client. Sanitising them would have removed
real, actionable feedback and protected nothing. **So that controller was NOT swept, and the
correct response was to fix the RATCHET, not the code.**

**The measured split of the 42:**

| Enclosing catch | Count | Verdict |
|---|---|---|
| `\Throwable` (15) + `\Exception` (6) | **21** | genuine leaks - the code does not know what failed |
| `\DomainException` (14), `\InvalidArgumentException` (3), `\RuntimeException` (2), `HttpException` (2) | 21 | **not leaks** - app-authored, written for the client |

**The rule now encoded:** only a BROAD catch (`\Throwable`, `\Exception`, `\Error`, or unqualified)
counts. A **named** catch means the code knows what failed and chose that message. This is the same
judgement already applied by hand in sec 64.2 (leaving ChatController's `HttpException` site alone) -
it is now applied mechanically and consistently instead of per-slice.

**Baseline re-cut 42 -> 21, and the classification is PROVED rather than asserted.** Two new tests
feed real code shapes into the classifier: a broad catch MUST be counted; a `\DomainException` catch
and a `\App\Exceptions\Domain\ConflictViolation` catch must NOT. Without those, "21" would just be a
number I chose.

**Needle:** adding one more broad leak to `ProfileController` (a syntactically valid insertion - an
earlier attempt targeted a catch that did not exist and proved nothing) fails the ratchet; the file
restores byte-identically.

**Where the real 21 actually are - and this is the useful finding:**

| File | Broad leaks |
|---|---|
| `RideController` | **15** |
| `ProfileController` | 3 |
| `StaffAdminController` | 1 |
| `VerificationController` | 1 |
| `AdminDashboardController` | 1 |

**15 of 21 are in `RideController.php`, which is an OWNER-OWNED file** (two uncommitted RV-38
eager-load edits). Sweeping it needs the same HEAD-reconstruction treatment used in sec 62. The
remaining 6 across four non-owner controllers are straightforward slices.

**This correction roughly halved the reported debt (42 -> 21) and, more importantly, stopped a
sweep from damaging 21 working error messages.**

### 64.4 RV-13 sweep slices 2-5 - all non-owner controllers swept (21 -> 15) - **VERIFIED FIX**

Every remaining broad-catch leak outside the owner-owned `RideController`:

| File | Fixed |
|---|---|
| `ProfileController` | 3 |
| `StaffAdminController` | 1 |
| `VerificationController` | 1 |
| `AdminDashboardController` | 1 |

`StaffAdminController`'s OTHER site (line 343) is `catch (\DomainException $e)` and was deliberately
left alone for the reason sec 64.3 established.

**A SECOND DEFECT FOUND ALONGSIDE THE LEAK, in the same three lines.** All three ProfileController
blocks answered `$e->getCode() ?: 500`. **An exception's code is not an HTTP status.** A `PDOException`
carries `23000` (integrity constraint) or `42S02`, and `response()->json($body, 23000)` is not a valid
response - so the old line could turn a caught database error into a hard failure INSIDE the error
handler, which is the worst place to fail. Replaced with a fixed 500: at that point we genuinely do
not know why it failed. (The same pattern appears elsewhere in the codebase and is recorded below as
still open.)

**Recorded but NOT changed: broad catches that answer 422.** `StaffAdminController` and
`AdminDashboardController` return 422 from a `catch (\Exception $e)`. A 422 tells the client "fix your
request"; a broad catch means we do not know what failed, so the honest status is 500. This is the
mirror image of section 64's defect (a domain violation answering 500). **The status was left alone
deliberately** - changing a client-visible status in a message-leak sweep is a separate, contract-
affecting decision, and `AGENTS.md` reserves public response-shape changes to the owner.

**Ratchet baseline 21 -> 15** in this commit. `ExceptionMessageLeakRatchetTest` OK (5 tests).

**All 15 remaining broad leaks are now in `RideController.php`** - the owner-owned file with the two
uncommitted RV-38 eager-load edits. Sweeping it needs the HEAD-reconstruction treatment used in
section 62, or the owner's edits committed first.

**No regression.** Profile + Verification + Admin = 148 tests, 13 red **identical with and without the
change** (git-stash bisect, 0 new).

**Still open for RV-13:** (a) the 15 `RideController` sites; (b) the `$e->getCode() ?: 500` pattern
elsewhere; (c) the broad-catch-returns-422 status question (owner); (d) the `{success,data,error}`
envelope (owner, changes what the Flutter client parses); (e) migrating the 61
`\InvalidArgumentException` throw sites to the typed `Domain\` subclasses (section 64).

### 64.5 RV-13 sweep slice 6 - `RideController`, the owner-owned file (15 -> 0) - **VERIFIED FIX**

**THE HEAD-RECONSTRUCTION, AND THE RESTORE PROOF.** `RideController` carries uncommitted owner edits
(the RV-38 eager-loads), so the sweep could not be committed straight from the working tree. `5fb1a4b`
was built as `git show HEAD:<file>` + the sweep applied, committed on its own, and the owner's edits
restored afterwards. The restore was verified **byte-for-byte**: SHA256 `15F020DE...` before and after.
`git diff HEAD -- RideController.php` shows only the owner's own eager-load hunks, so the sweep really is
in the commit and not silently left in the working tree.

**THE SWEEP.** All 15 remaining broad-catch leaks were replaced: the exception text moved to
`Log::error(...)` carrying `user_id` / `booking_id` / `class` / `file` / `line`, and the client receives a
stable sentence. **The sweep changed MESSAGE TEXT ONLY - not one HTTP status literal moved.** That is
measured, not assumed: the multiset of status literals in the file is `201x3 400x1 403x1 404x2 422x9 500x8`
(24 total) both before and after, and is identical as a multiset. That is what makes the bisect below exact
rather than approximate.

The 3 `catch (\InvalidArgumentException $e)` sites still returning `$e->getMessage()` are **named** catches
and are correctly NOT counted by section 64.3's rule - they return app-authored rule messages.

**Ratchet baseline 15 -> 0.** `ExceptionMessageLeakRatchetTest` OK (5 tests, 7 assertions) at 0.

**Needle, both directions.** Reintroducing one leak at a `\Throwable` catch
(`'message' => 'Ride creation failed: '.$e->getMessage()`) - a syntactically VALID insertion, `php -l`
clean, which matters because an earlier attempt in this audit inserted a line mid-expression and proved
nothing - fails the ratchet with 2 failures and the message "the baseline is 0 and may only fall". The
controller then restores **byte-identically** (SHA256 verified equal) and the ratchet passes again.

**REGRESSION BISECT - identical selection, identical result.** `tests/Feature/Rides`,
`tests/Feature/Bookings`, `tests/Unit/Domain`, run twice with only the controller swapped:

| Controller under test | Result |
|---|---|
| swept (HEAD `5fb1a4b`) | Tests: 211, Assertions: 321, Failures: 4, Skipped: 2 |
| pre-sweep (`5fb1a4b^`) | Tests: 211, Assertions: 321, Failures: 4, Skipped: 2 |

Identical totals AND identical failure names: `test_passenger_can_confirm_completion`,
`test_passenger_confirm_fails_for_active_ride`, `test_non_passenger_cannot_confirm_completion`,
`test_ride_creation_does_not_charge_any_fee`. **Zero regressions** from the sweep. (These 4 are
pre-existing and belong to the `passenger-confirm` flow, not to the leak sweep.)

**A REAL COMMITTED DEFECT FOUND HERE - AND A FALSE ALARM CORRECTED.** `pint --test` failed on both RV-13
test files. Only ONE of them was real:
- **REAL:** `DomainExceptionMappingTest.php` was committed with **no newline at end of file**
  (`\ No newline at end of file`), failing `single_blank_line_at_eof`. This would have turned the
  `pint.yml` CI gate red on the branch. Fixed (that single byte is the whole change).
- **NOT REAL:** `ExceptionMessageLeakRatchetTest.php` reported CRLF, but the **committed blob is pure
  LF** - `.gitattributes` sets `* text=auto eol=lf`, and `git hash-object` of the working tree equals the
  HEAD blob exactly. The CRLF was a local artifact of how the file happened to be written, invisible to
  CI. Pint normalised it locally and the file is byte-identical to HEAD. Recorded so the next session
  does not chase a CI failure that never existed, and does not "fix" it a second time.

**Verified after both changes:** `DomainExceptionMappingTest` + `ExceptionMessageLeakRatchetTest`
= 11 tests, 28 assertions, OK (exit 0); `pint --test` PASS on both.

**Still open for RV-13, unchanged by this slice:** (a) migrating the 61 `\InvalidArgumentException` throw
sites to the typed `Domain\` subclasses - they still rely on the base-class mapping, so the code is a
generic `DOMAIN_RULE_VIOLATION` rather than one per rule; (b) the `$e->getCode() ?: 500` pattern elsewhere
in the codebase, where an exception CODE is answered as an HTTP status (section 64.4); (c) broad catches
that still answer 422 - a client-visible contract question reserved to the owner; (d) the
`{success,data,error}` envelope (un4 - it changes what the Flutter client parses); (e) the
`assertNotEquals` ratchet, still deferred for the reason R2 sec 20.2 gave.

## 65. `BACKLOG.md` itself was corrupt - the status authority had 3 unreadable commit hashes - **VERIFIED FIX**

Found while closing sec 64.5, because the file could not be edited: the edit tool refused it as a **binary** file.
That was not a tooling quirk - it was the defect.

**THE DEFECT.** `docs/audit/BACKLOG.md` - the file `STATE.md` points to as the single source of truth for status -
contained **3 NUL bytes and 1 backspace (0x08)** committed inside its Evidence column:

| Row | Committed bytes | Meant | Resolves in git to |
|---|---|---|---|
| RV-01 | `\0` `66b1dd` / `0x08` `4885d8` | `066b1dd` / `b4885d8` | "Decision 1b: staff-authenticated KYC document streaming" / "keep the boundary baseline at 21" |
| RV-01 | `\0` `c6a1f6` | `0c6a1f6` | "Decision 11: pin the KYC action gate" |
| RV-38 | `\0` `0f7b9d` | `00f7b9d` | "un9: fix the service-layer lazy-loading N+1 sites" |

So the leading `0`/`b` of three commit hashes was replaced by a control byte. Every hash was still **provably the right
one** (all five resolve in git, with subjects that match their surrounding sentence exactly, and `STATE.md` records the
same three independently), which is why it went unnoticed - but the token was unreadable to any tool, including
`BACKLOG.md`'s own section-3 self-verification command, which regex-matches `\b[0-9a-f]{7,40}\b`.

**A SECOND, LATENT DEFECT THE NUL BYTES WERE HIDING.** `.gitattributes` sets `* text=auto eol=lf`, so every file in
this repo is stored with LF. `text=auto` means *detect*: a file containing NUL is classified **binary**, and git does
not normalise line endings for binary content. The NUL bytes therefore silently disabled the repo's own line-ending
policy for this one file - its committed blob is **667 CRLF, 0 LF** (measured on the raw blob, not on a re-joined
copy). Removing the corruption makes git classify the file as text again, so the next commit stores it as LF, as
`.gitattributes` always intended. **The diff for this file is therefore whole-file (667 lines) even though only 4
bytes of content changed** - that is the line-ending normalisation, not an edit, and it is the correct end state.

**THE FIX.** The 4 control bytes were replaced with the intended `0`/`0`/`0`/`b` characters. Verified three ways:
(1) the file no longer contains any NUL or control byte; (2) all 5 restored hashes resolve with matching subjects;
(3) a direct content comparison against `HEAD` shows the ONLY differences are those 4 bytes plus the two intentional
RV-13 row/acceptance edits - nothing else in the 667-line file moved.

**Why it mattered rather than being cosmetic.** The BACKLOG is the file the next-task rule reads. A status authority
that cannot be edited, and whose commit references cannot be verified by its own self-check command, is a place where
a later session silently loses work or re-verifies nothing.

**Genuinely unverified.** How the control bytes entered the file is not established - the corruption is committed, so
it predates this session, but no introducing commit was bisected. It is consistent with a PowerShell/console write
that mangled a non-ASCII character, but that is an inference, not a finding, and it is recorded as such.

## 66. The `Blocked by` column was STALE - 17 of 21 unfinished rows waited on decisions already answered - **VERIFIED FIX (the column); RV-11 defects FOUND**

The next-task rule selects "the first `OPEN` row whose `Blocked by` is `none`". Applied mechanically it selected
**nothing** - and that is not because the work is finished or because every remaining task is genuinely waiting on
you. It is because the gate column was written **before** your decision slate came back and was never re-derived.

**THE FINDING.** All 27 decisions were answered on 2026-10-02. Of the 21 unfinished rows (14 `PARTIAL` + 6 `OPEN` +
1 `GATED`), **17 cite an already-answered decision as their blocker** - RV-02 behind "2, 3", RV-04 behind "9",
AF-5 behind "un1", AF-06 behind "2, 3, 6", T3-10 behind "7", and so on. Only **4** have a blocker that is still
really open: RV-01 (the storage move), RV-14 (the units question), RV-17 (needs a production-shaped run) and RV-12
(a product decision). Every one of the other 17 is queued behind a sentence that stopped being true on 2026-10-02.

**WHY THIS IS WORTH A SECTION.** A queue whose gates are stale cannot be worked: the rule reads `Blocked by = none`,
almost nothing says `none`, so every session ends the same way - a full-looking backlog and no selectable task. The
items were not forgotten and they were not refused; they were recorded against a question that has since been
answered, and nothing ever went back and cleared the gate.

### 66.1 RV-11 - what actually moved, and what is still a LIVE DEFECT (re-verified in code)

Section 26.14 item 1 is the clearest case, because part of it landed on its own and part of it did not. Checked
against the code, not the prose:

| 26.14 claim | State today | Evidence |
|---|---|---|
| Two contradictory tier-band schemes (`>=80/60/40` vs `>=200/150/100`) | **RESOLVED** | `UserScore::getTierAttribute` is the only definition (Gold>=80, Silver>=60, Bronze>=40, Restricted); `ScoreService::resolveTier` was deleted, `setTierAttribute` no longer writes |
| A third copy of the bands written to a legacy `tier` column | **RESOLVED** | the write is gone (R2 sec 42); the bands are owner-pinned by `RV37ScorePolicyTest` |
| Start score 70 vs 100 | **STILL TRUE, and now a direct violation of your own answer** | `ScoreService.php:32` and `:203` create **70**; `applyAction`'s `firstOrCreate` (`:262`) still creates **100**. un2 pinned start = 70 |
| `applyAction` clamps only at 0, uncapped top | **STILL TRUE** | `:274` is `max(0, $previousScore + $result->points)` - no ceiling, against a pinned max of 100 |
| Every completed ride counts twice | **STILL LIVE** | `applyAction:277` does `total_rides + 1` on a positive action, and `recordRideCompleted:46-52` calls `applyAction(RIDE_COMPLETED)` **and then** `incrementRides()` |

**THE IMPACT, STATED PRECISELY (and corrected from 26.14).** 26.14 says the double-count "inflates the
`cancel_rate` denominator". The mechanism is more specific than that, and worth recording because it changes who is
affected:
- `cancel_rate` is **not a stored column**. `UserScore::setCancelRateAttribute` discards every write
  ("computed from total_cancellations / total_rides - never stored"), so `ScoreService:280`'s assignment is
  **dead code** - and it computes a *different formula* (`total_cancellations / total_rides`) than the accessor
  actually uses (`total_cancellations / max(1, total_rides + total_cancellations)`).
- The penalty gates read the accessor, not the column: `PassengerCancelPolicy:53` and `DriverCancelRidePolicy:53`
  both test `$userScore->cancel_rate >= 50`.
- So doubling `total_rides` inflates the accessor's denominator, `cancel_rate` **falls**, and the 50% high-cancel
  gate **stops firing when it should**.

**This under-penalises; it does not over-penalise anyone.** No user is charged more than they should be. The failure
is silent: repeated cancellations stop being flagged, and the safeguard simply never engages.

**NOT FIXED IN THIS SECTION, DELIBERATELY.** Three of these four are *enforcing a decision you have already made*
(start 70, max 100) rather than inventing a policy, so they are decision-free in principle. But they change score
values that gate cancellation penalties, and `AGENTS.md` reserves score semantics for you. The double-count is a
genuine bug rather than a policy choice, yet removing it lowers `total_rides` for every completed ride, which
retroactively re-rates every user's cancel rate. That is a decision about whose history gets re-rated, so it is
yours to make, not mine to assume.

**The four open questions, stated so they can be answered in one go:**
1. **Should a completed ride count once?** (removes the double-count; re-rates existing `cancel_rate` values)
2. **Should the ceiling be enforced at 100** in `applyAction`, matching the max that un2 already pinned?
3. **Should the `firstOrCreate` fallback in `applyAction` create 70** like every other path?
4. **Is the dead `cancel_rate` assignment at `ScoreService:280` safe to delete**, given the mutator discards it?

**Genuinely unverified.** Items 2-6 of section 26.14 (RV-10, RV-02 L2, RV-20, RV-09, RV-21) have had their **gates**
answered, but their percentages and their code were NOT re-checked in this pass. Their `Blocked by` cells were
corrected from "decision X" to "decision X - answered, see 66"; that is a statement about the gate, not a claim that
the remaining work is now verified. Each still needs its own verified pass before its status moves.

## 67. RV-11 - the ride double-count is fixed, and the owner's score policy is now enforced - **VERIFIED FIX**

Owner instruction 2026-10-03: *"ok then fix them"*, answering the four questions section 66.1 asked for. The
answer recorded is **a completed ride counts ONCE**.

**1. THE DOUBLE-COUNT - the real defect, and the one that mattered.** `recordRideCompleted` called
`applyAction(RIDE_COMPLETED)` and then `incrementRides()`. `applyAction` already counts the ride, so **every
completed ride was counted twice**, for the driver and for every passenger on it. Both extra calls are gone, and
the now-dead `private function incrementRides()` was deleted with them.

The increment inside `applyAction` was also re-gated. It read `if ($result->isPositive())`, which happens to be
true only for `RIDE_COMPLETED` today, but it said the wrong thing: a future positive bonus would have inflated a
**ride** count, which is exactly the number `cancel_rate` divides by. It is now `if ($action ===
ScoreAction::RIDE_COMPLETED)` - behaviour-preserving today, and honest tomorrow.

**2. ONE SOURCE FOR THE THREE NUMBERS.** The start score, floor and ceiling were four separate literals, and one
of them said 100. They are now `ScoreService::START_SCORE` (70), `MIN_SCORE` (0) and `MAX_SCORE` (100), used by all
four creation paths and by the clamp. `R2 sec 26.14`'s "no `config/score.php` exists" remains true - a config file
was NOT added here, because that is a larger design step and this fix only needed the drift to stop.

**3. THE CEILING.** `applyAction` clamped the floor at 0 and had no ceiling, while `applyDelta` clamped both ends.
Both paths now clamp to `[MIN_SCORE, MAX_SCORE]`, so the two mutation paths agree.

**4. THE DEAD `cancel_rate` WRITE.** Deleted. `setCancelRateAttribute` discards assignments, so the assignment was
never stored, and it used a different formula (`total_rides`) from the accessor the policies actually read
(`total_rides + total_cancellations`). Deleting it changes no behaviour - `RV11RideCountTest` proves the mutator
still discards a write, so if the column ever starts being written the test fails loudly.

**A CORRECTION TO SECTION 66.1, made while fixing it.** Section 66.1 reported the `firstOrCreate` 100 as a defect
that "silently contradicted the owner's decision whenever `applyAction` happened to be the first thing to touch a
score row". That overstated it, and the test found why: **`UserObserver` calls `initializeScore` on `created`**, so
in normal operation a score row always exists first and the 100 default is **never reached**. It was a **latent**
inconsistency - it would still bite for any row predating the observer, or if the observer's own `initializeScore`
failed - not one that had been handing users a wrong score. The fix stands and is worth having; the claim about its
reach was wrong. The test now reproduces the legacy case deliberately (delete the row, then act) and says so,
rather than pretending it fires on every signup. This is recorded because the correction changes how urgent the
defect was, not because it changes the fix.

**NEEDLE, BOTH DIRECTIONS.** Reintroducing the second increment (as `getScore($driver)->incrementRides()`, which is
what the deleted helper did) fails **2 of the 12** with `a completed ride must count ONCE for the driver, not
twice`. The file was then restored **byte-identically** (SHA256 verified equal) and all 12 pass again. A first
attempt at this needle called the deleted `incrementRides()` and produced a `BadMethodCallException` instead - which
would have "proven" the test catches the bug for the wrong reason, so it was redone faithfully.

**MEASURED.** `tests/Feature/Review/RV11RideCountTest.php` (new, 12 tests, 25 assertions) pins the count at 1 and
at 3, that a cancellation is not a ride, the start score from every creation path, both ceilings on both mutation
paths, and the discarded `cancel_rate` write. **Crucially it pins the CONSEQUENCE, not just the arithmetic**: three
tests assert whether the 50% high-cancel gate actually fires, including one that gives two users the same
cancellations and a different ride count and requires the gate to fire for one and not the other. A fix that
merely deleted the second increment without restoring correct rates could not pass.

**NO REGRESSION.** Area floor (`tests/Feature/Rides`, `tests/Feature/Bookings`, `tests/Unit/Domain`) plus every test
that references `ScoreService` / `UserScore` / `Score` (`RV37ScorePolicyTest`, `DriverNoShowPolicyTest`,
`ScoreTransactionTest`, `StaffCancellationRefundTest`, `CancelSeatsEquivalenceCheck`) plus this file:
**275 tests, 451 assertions, 4 failures, 2 skipped.** The 4 are byte-identical to the pre-existing set recorded in
section 64.5 and reproduced there against the pre-sweep controller - `test_passenger_can_confirm_completion`,
`test_passenger_confirm_fails_for_active_ride`, `test_non_passenger_cannot_confirm_completion`,
`test_ride_creation_does_not_charge_any_fee`. **Zero new failures.** `pint --test` PASS on all three changed files.

**`BoundaryDependencyTest` - a PRE-EXISTING failure found while running the gate, not caused by RV-11.** Required
because a `use` line was added. It fails `models_to_enums` (max 2) against `Models/Complaint.php`,
`Models/Employee.php`, `Models/Wallet.php`. **Proved pre-existing by controlled bisect**: with only
`ScoreService.php` and `UserScore.php` swapped back to their HEAD blobs, the identical failure occurs
(9 tests, 36 assertions, 1 failure both times), and the files were restored byte-identically. None of the three
flagged models is one this task touched. The `R6` baseline was **not** raised. It is recorded here so the finding is
not lost, and it is left for the owner because fixing a boundary edge touches the `BASELINES` values `AGENTS.md`
reserves - see the BACKLOG row opened for it.

**Two dead docblocks fixed while in the file.** `UserScore::applyDelta` documented itself as clamping to `[0, 200]`
- the ceiling the owner *rejected* in un2, quoted as if it were the rule. And an orphaned comment above it still
described the pre-sec-42 arrangement ("a REAL COLUMN written by `resolveTier` ... the accessor is gone"), which
contradicted the code thirty lines below it. A wrong ceiling in a comment is how the rejected 200/150/100 scale
kept being quoted; a comment that contradicts its own file is how it survived this long.

**STILL OPEN in RV-11** (consolidation, not correctness): one mutation path (`ScoreLedger::apply()`) does not exist,
there is still no `config/score.php`, and the vestigial `tier` column is still in the schema. None of those cause a
wrong score today.

## 68. RV-13(b) - an exception CODE is no longer used as an HTTP status - **VERIFIED FIX**

**Selected by the owner ("continue") from the four decision-free remainders** the next-task rule cannot pick,
because it only selects `OPEN` rows and all four are `PARTIAL`: RV-13 (Order 27), RV-16 (29), RV-10 (37),
RV-19 (49). Lowest Order won. Every other candidate was rejected first, not skipped: AF-5's remainder moves
complaint/KYC attachments off the `'public'` disk, which changes URLs clients already hold - AGENTS.md's
ask-first list; AF-6/AF-7 are money-semantics changes, also ask-first.

**THE DEFECT.** `WalletRequestService` threw PHP's SPL `\DomainException` and carried the HTTP status in the
integer `$code`. `WalletRequestController` read it back four times as `$e->getCode() ?: 422`. Two distinct
failures hide in that one expression:

- `$code` defaults to **0**. Any throw that forgot the code silently became 422 through the `?:`. The status was
  being *guessed at the controller*, so a rule deserving 403 or 404 would answer 422 and nothing would look wrong.
- `$code` is arbitrary. A throw using any non-HTTP number - a SQLSTATE-derived value, a domain code - hands it
  straight to `response()->json($payload, $status)` and produces a status no client understands.

**THE MEASUREMENT CORRECTED THE CRITERION.** `BACKLOG.md` described this as "the `$e->getCode() ?: 500` pattern
elsewhere". Grep found the `: 500` form already gone - `ProfileController` carries comments recording its removal -
and **4 live `: 422` sites**, all in `WalletRequestController`. `AdminWalletRequestController` also catches
`\DomainException`, but it does **not** use this service and its catch hardcodes 422, so it is untouched: changing
it would be unrelated editing. A grep also confirmed `WalletRequestController` is the service's only consumer, which
is what made a self-contained migration possible.

**THE FIX, AND WHAT IT DELIBERATELY DID NOT CHANGE.** The seven service throws now use
`App\Exceptions\Domain\BusinessRuleViolation` / `ConflictViolation` with stable `errorCode`s, and the four catches
read `$e->httpStatus`. **Every status is unchanged (422 / 409)** because `WalletRequestControllerTest` pins them and
moving an endpoint's answer is not this task's business - only the channel is repaired. The 409 stays a
`ConflictViolation` because "you already have a pending charge request" genuinely is a state conflict. The response
**shape is unchanged** (`success` / `status` / `message`): `DomainException::toArray()` would have added `code` and
`status_code`, which is a public API shape change and therefore the owner's call, not this task's.

**A TEST EDIT, DECLARED.** `AGENTS.md` forbids editing a test to make it pass. Two assertions in
`MoneyPathBatchTest` named `\DomainException` for this service, and the task changes that type deliberately. They
were **tightened, not weakened**: `expectException(BusinessRuleViolation::class)` **plus**
`expectExceptionMessage('pending withdraw requests' / 'Insufficient balance')`, where the old assertion could not
tell the over-commit guard from any unrelated SPL domain error and could not check *which* rule fired.

**THE RATCHET IS AT ZERO, AND PROVES IT CAN SEE.** `RV13ExceptionCodeAsStatusTest` fails on any non-comment line in
`app/Http/Controllers` containing `getCode()`. Zero is deliberate: the defect is that the channel exists at all, so
one surviving site is a bug, not progress. Comments are skipped on purpose, because several controllers carry a
comment explaining they REMOVED this pattern and counting the explanation would make the ratchet fail on the record
of its own fix. A second test feeds the detector the exact old line, the fixed line, and a comment, and asserts it
flags the first and ignores the other two - a ratchet whose pattern quietly stopped matching would otherwise pass
forever.

**NEEDLE, BOTH DIRECTIONS.** Reintroducing `$e->getCode() ?: 422` and `catch (\DomainException $e)` fails the
ratchet (exit 1). The controller was then restored **byte-identically** (SHA256) and passes again.

**NO REGRESSION - proved by controlled bisect, not by counting.** The same selection run with my three files swapped
back to their HEAD blobs: **576 tests / 39 errors / 27 failures** at HEAD versus **583 / 39 / 27** with the change
(the 7 extra are the new test). Comparing the two failure **name sets**: 66 unique each, **intersection diff empty
in both directions - zero new failures, and nothing accidentally fixed.** The 66 are the known pre-existing red set
(`GeocodingServiceTest`, `StaffComplaintServiceTest`, `EmployeeManagementServiceTest`, `AdminDriverServiceTest`,
`CashRideFeeServiceTest`, `MoneyPathBatchTest::test_two_charges_in_the_same_second_do_not_collide`, ...).
`pint --test` PASS on all four changed files. The boundary gate still fails `models_to_enums` - the **pre-existing**
violation already bisect-proven and recorded as BACKLOG row 106; the baseline was not raised.

**STILL OPEN - this is the (a) half, and it is much larger.** The remaining SPL `\DomainException` throwers are
`ComplaintService` (1), `EmployeeManagementService` (18) and `StaffComplaintService` (8), each with controllers that
catch SPL `\DomainException` and would have to migrate together or the exception would escape to a
`catch (\Exception)` and answer **500** - a regression, which is why they are not folded in here. The 61+
`InvalidArgumentException` sites remain untouched; `DomainExceptionMappingTest` still answers them 422 via the
generic `DOMAIN_RULE_VIOLATION` code, which is correct and is the part already shipped.

## 69. RV-16 - the signup verification mail leaves the DB transaction - **VERIFIED FIX**

Next decision-free remainder by Order (RV-16, 29). **RV-13(a), the nominal next item, turned out NOT to be
decision-free, and the reason is recorded below rather than discovered later.**

**THE DEFECT.** `SignupController::register` PATH B opened `DB::beginTransaction()`, created the user,
**sent the verification email from inside the transaction**, and `DB::rollBack()` whenever the send reported
failure. Rolling back is wrong in the one case that actually happens: **SMTP can deliver the message and then
time out before the response arrives.** The user then holds a real verification code for an account that was
rolled back and no longer exists. They enter it, it fails, there is nothing to verify, and the only way on is a
different email address - while the address they just used has no account at all. The mail succeeded; the
database disagreed.

**THE FIX.** The transaction now covers only the `users` row and commits first. The OTP send happens after the
commit, where nothing may roll the account back. This makes the failure **recoverable**, because PATH A already
handles exactly this state: the same signup POST finds the UNVERIFIED user and re-sends the code, deliberately
without touching the password. So the account is the thing that must survive, and the message is something the
user can simply ask for again.

**A MESSAGE CHANGE, DECLARED.** On mail failure the response was `'Registration failed: could not send
verification email.'`. That sentence is now false - the row is committed - and a user who believes registration
failed will pick a DIFFERENT email and orphan the one they just used. It now says the account was created and to
submit again to resend. **The status is still 500 and the JSON keys are unchanged** (`status`, `message`); only
the sentence differs. This is copy, not shape, but it is called out because the behaviour changed with it.

**NOTHING ELSE WAS TOUCHED.** `VerificationController` and `ProfileController` also open transactions, but they
send no mail inside them, so there was nothing to move. `EmailOtpService` writes the OTP row in its own
transaction (`OtpRepository`), which is correct now that it runs after the user is committed.

**NEEDLE, BOTH DIRECTIONS.** Moving the `sendOtp` call back inside the transaction - with its `DB::rollBack()`
and the old message - fails **4 of the 5** tests, including the two structural guards. The controller was then
restored **byte-identically** (SHA256) and all 5 pass again.

**NO REGRESSION - controlled bisect.** `tests/Feature/Auth` + `tests/Feature/Otp` + `tests/Unit/Http/Requests` +
`OtpDisclosureTest` + `ExceptionMessageLeakRatchetTest`, run with `SignupController` swapped back to its HEAD
blob and the new test moved aside: **HEAD 178 tests / 412 assertions, OK** versus **183 / 429, OK** after (the 5
extra are the new test). Zero failures on both sides, so there is nothing to diff - the whole Auth/Otp surface
is green either way. `pint --test` PASS on both files.

**TWO OF MY OWN TEST BUGS, RECORDED BECAUSE BOTH WOULD HAVE SHIPPED A FALSE PASS.** (1) The first draft rebound
the container to flip the fake mailer; the container's instance cache swallowed the swap and the "resend works"
test silently exercised the FAILING fake, which returned 500 and the test failed for the wrong reason. Fixed by
binding ONE mutable instance and flipping a flag. (2) The first draft asserted "no `DB::rollBack()` after
`DB::commit()`" - but the commit's own `catch` block legitimately contains a rollback and sits textually after
the commit, so **the guard failed on correct code**. It now measures from the `sendOtp(` call, which is the
invariant that actually matters.

**WHAT REMAINS IN RV-16, AND WHY IT IS NOT DONE HERE.** Decision 4 (keep phone-OTP) and decision 8 (uniform
enumeration answer) are settled; the phone-OTP endpoints and the TextMeBot `sleep(5)` were checked for the
half-removed-flow condition and are both still present, so decision 4's "keep both" already holds. The
`.env` with `APP_ENV=production` that trips the OTP-disclosure guard is a **deploy action on the owner's
machine**, not code, and is listed under owner actions. No test was weakened to accommodate any of this.

## 69.1 RV-13(a) is NOT decision-free - a decision is now needed before it can proceed

Found while scoping RV-13, and it changes that row's classification. `BACKLOG.md` marks remainder (a) - migrating
the 61+ `\InvalidArgumentException` sites to the typed hierarchy - as decision-free. **It is not**, because of an
asymmetry between two adjacent renderables in `Handler` (`:88` and `:80`):

- `\InvalidArgumentException` answers **422 with the message MASKED** in production:
  `config('app.debug') ? $e->getMessage() : 'The request could not be processed.'`
- `App\Exceptions\Domain\DomainException::toArray()` **always** returns `$this->getMessage()`.

So migrating those sites would not only change codes - it would **unmask 61+ domain messages in production**,
directly against the direction of the leakage work. Deciding which behaviour is right (mask everything, mask
only non-app-authored messages, or always show domain messages) is a **product and security decision**, and it
determines whether the migration can be status- and body-preserving at all. It is therefore parked here as a
question rather than guessed at, and `BACKLOG.md` RV-13's `Blocked by` cell is corrected accordingly.

## 70. RV-10 - escrow CAN be held indefinitely, and the release policy is an owner decision - **BLOCKED** (terminal)

Investigated as the next decision-free remainder by Order (37). **The row's `Blocked by` cell claiming
"remainder decision-free" is wrong, and this section is the correction.**

**THE HOLE, verified in code.** A `CONFIRMED` e-pay booking has its money in SyCash escrow. That escrow is
released on exactly two paths:
- `EPayPaymentStrategy::processRideCompletionPayment` - **that passenger** confirming (`RideService`'s
  per-passenger confirm path), which releases only that passenger's share;
- `RideService::checkAndCompleteRide` - which gates on the driver confirming (`:405`) **and on EVERY confirmed
  booking's passenger having `passenger_confirmed_at`** (`:414-421`) before it releases anything.

So anyone who taps is paid. The money that sticks belongs to **a passenger who never taps on a ride whose driver
also never taps** - and nothing sweeps it. `ExpireStaleBookingsCommand` (`bookings:expire-stale`, every 15 min,
added by decision 2) only touches `status = PENDING` bookings, i.e. requests the driver never accepted - and per
`R2 sec 53` those never took money at all (`amount_paid = 0`, never entered escrow). **It cannot and must not
release this money: it never sees a confirmed booking.** There is no `rides:advance-status` command; the console
command list has no status sweeper of any kind.

Consequences while stuck: escrow sits in SyCash with no expiry, the bookings stay `confirmed` and never
`completed`, no scores are recorded, and the ride sits at `awaiting_confirmation` indefinitely.

**A CORRECTION TO THE CRITERION'S SEVERITY.** `BACKLOG.md` says "Escrow release stops depending on a driver tap
that may never arrive; a stuck booking cannot hold money forever". The per-passenger release path means this is
**not** a whole-ride freeze: passengers who do confirm are paid. The exposure is the subset who never do. Worth
knowing before sizing the fix.

**WHY IT IS NOT DECISION-FREE.** Closing it means choosing a release policy for a ride that departed, was driven,
and that nobody confirmed - and the app genuinely cannot tell whether the passenger showed up. The three
defensible answers all move real money in opposite directions: **release to the driver** after N hours (a driver is
paid for a service that may never have happened), **refund the passenger** (a driver is unpaid for one that did), or
**escalate to a human** (no automated movement, but the money is still stuck until someone acts). Picking one is
`AGENTS.md`'s ask-first list ("changing money or ledger semantics"), and the **window** is the missing number.

**AND THE OWNER HAS ALREADY SPOKEN ADJACENT TO IT, IN THE OPPOSITE DIRECTION.** Decision 2 (sec 53) is "expire
unconfirmed bookings, **do NOT auto-confirm**", and `STATE.md` lists "RV-10 auto-confirm hours" under *Blocked on
owner*. Implementing an auto-confirm window now would silently reverse a ruling the owner made and marked for
reconsideration - not something to do unattended because a BACKLOG cell was stale.

**NOTHING WAS CHANGED.** No probe, no half-built sweeper, no guessed window. The finding is recorded and the row
is moved to BLOCKED so it stops being read as ready.

**THE QUESTION, FOR THE OWNER.** For a ride that has departed, is `CONFIRMED`, and that neither the driver nor the
last passenger confirms: after how long, and then should the escrow go to the driver, back to the passenger, or to a
staff queue for manual resolution? A third option is worth considering: a read-only sweeper that only REPORTS stuck
escrow with its age and amount, moving no money, so the policy can be chosen from real data. That one is
decision-free and was deliberately NOT built here only because it was not asked for - say the word and it is a
small, contained command.

## 71. RV-19 item 3 - one 95/5 split, and the ride-level settlement could THROW - **VERIFIED FIX**

Working the last decision-free remainder. Item 3 said the 5% platform fee should live in
`config/fees.php` and one helper, replacing four hard-codings. Reading those four sites to do it turned up a
**live crash**, which is the reason this section is longer than a config refactor.

**THE DEFECT.** Two of the four sites computed the split as two INDEPENDENT roundings:

```php
$driverShare  = round($total * 0.95, 2);
$primaryShare = round($total * 0.05, 2);
```

**Those two lines do not always sum to `$total`.** Swept over every 2-decimal total from 0.01 to 2000.00:
the first divergence is `total = 0.10`, and they recur every 0.20 - every total ending in an odd tenth
(0.10, 0.30, 0.50, 99.10, 99.90, 250.70, 500.30, 1000.50, 1234.50). Whole-cent totals (5000.00, 1500.00) balance
exactly, which is why this survived: **every fixture and the seeder use whole numbers** (`SyrideSeeder` draws
`rand(3000, 25000)`), so the whole-cent case is the only one that was ever exercised.

At 1000.50 the exact split is driver 950.475 / platform 50.025. Independent rounding yields 950.48 + 50.03 =
**1000.51** - one cent more than was debited from escrow.

**WHAT THAT DID WAS NOT A SILENT CENT LEAK, AND THAT IS THE INTERESTING PART.**
`LedgerService::postTransfer` validates that legs sum to zero and throws rather than record an unbalanced
entry ("Money is only conserved if every credit has a matching debit... recording it would make the ledger
lie"). So the third site - the one that already subtracted, with the comment "subtract to avoid float drift" -
was the only one that worked, and the other two made `releaseEarningsToDriver` and `processPassengerNoShow`
**throw**. Because `checkAndCompleteRide` wraps completion in one transaction, the ride could never reach
FINISHED, bookings never became `completed`, no scores were recorded, and **every later confirmation threw
again** - permanently. This is a concrete, non-speculative instance of RV-10's escrow-liveness criterion
(§70): escrow that never moves because a confirmation can never complete.

**THE OWNER WAS ASKED, because this is money and the two formulations differ by a cent in a real direction.**
Ruling: **driver gets `round(total x 0.95)`, platform gets the remainder.** That is what `releaseEscrowToDriver`
had already chosen, so this makes the paths consistent rather than inventing a policy.

**DELIVERED.**
- `config/fees.php` (new) - the single home for `driver_share_rate` / `platform_fee_rate`, with the arithmetic
  trap documented where the next reader will hit it.
- `app/Support/FeeSplit.php` (new) - one `driverAndPlatform(float): array{driver, platform}`. The subtraction
  is done in **integer minor units** via the existing `App\Domain\ValueObjects\Money` (integer fils), so it is
  exact by construction rather than by careful rounding. Reusing the project's own value object also avoids
  introducing a float-based helper when one already exists.
- `WalletTransactionService` - all three sites now call it (two were broken, one was already correct and is now
  consistent). `releaseLegs()` is provided so a caller posting a transfer cannot rebuild the arithmetic and lose
  the balance guarantee.
- `AdminDriverService:320` - the SQL literal `* 0.95` is now a **bound parameter** from config. Arithmetic
  unchanged. NOTE: this file is one of the owner's pre-existing modified files; only that one expression was
  touched, and it is staged explicitly so nothing else of the owner's rides along.
- `SyrideSeeder:633` - reads `fees.platform_fee_rate`.

**NEEDLE, BOTH DIRECTIONS.** Restoring the independent rounding at all three sites makes
`the_95_5_settlement_completes_for_a_price_that_used_to_refuse_the_transfer` fail with the **production
error verbatim** - `RuntimeException: Refusing to post an unbalanced ledger transfer: legs sum to 0.01` - and the
structural guard `no_money_path_carries_its_own_copy_of_the_rates` fail too. Service restored byte-identically
(SHA256) and both pass again.

**THE TEST SET IS DELIBERATELY NOT VACUOUS.** `RV19FeeSplitTest` includes a guard that recomputes the old form
and asserts it really did produce the 0.01 imbalance, so the "shares must sum" assertions cannot pass by
accident. The behavioural proof is in `DoubleEntryLedgerTest` - a real charge and a real
`releaseEarningsToDriver` at `price_per_seat = 1000.50`, asserting driver 950.48 / platform **50.02, not 50.03**.
That test is the one that matters: it exercises the actual affected code path, not just the helper.

**NO REGRESSION - controlled bisect.** Payment + Wallet + Unit/Domain + Admin + Config + Unit/Providers +
Review, with the two services and the ledger test swapped to their HEAD blobs and the new test moved aside:
**HEAD 614 tests / 1613 assertions / 16 failures** versus **642 / 1657 / 16** after. **The failure name sets are
identical and the diff is EMPTY.** All 16 are pre-existing (`AdminDashboardControllerTest` x9,
`RV39SeederHygieneTest` x2, `WalletTest` OTP x3, `KycActionGateTest`, `SharedTestSupportTest`). `pint --test`
PASS on all changed files.

**BOUNDARY GATE.** A file was added under `app/`, so the gate was run. `models_to_enums` fails on
`Models/Complaint.php`, `Employee.php`, `Wallet.php` - the **known pre-existing** trio recorded as BACKLOG row
106 (RV-11-B). The new file is `app/Support/`, which the rule does not cover, and it imports
`Domain\ValueObjects\Money`, not an enum. **Baseline untouched.** `Services -> Domain` is not a ruled edge
(only `Domain -> Services/Models/Http` is), so reusing `Money` there is allowed.

**STILL OPEN IN RV-19 - the other items were not touched.** Item 2 (admin "earnings" derived as
`seats x price x 0.95` instead of read from the ledger, so cash rides and cancellation payouts misreport) is
**still unfixed** and is a genuine report-semantics question - reading from the ledger makes drivers who take
cash rides show 0 wallet earnings, which is truthful about wallet money but may not be what "total earnings"
should mean to an admin. Item 4 (`config/system_admin.php`, the V8 finding) and item 5 (the
`AdminDriverServiceTest` tests that pin `total_rides`, `suspended_drivers` and the silent `period` default) are
untouched. Row stays **PARTIAL**.

## 72. RV-16 - decision 8 was ANSWERED but never APPLIED - four enumeration oracles - **VERIFIED FIX**

The next-task rule derives nothing (every `OPEN` row carries a gate), so this came from RV-16's own
remaining acceptance text: *"the account enumeration answer (decision 8) is applied uniformly to
forgot/signup"*. **Decision 8 is "A - uniform errors, no account enumeration", and the decisions table
recorded it as "already-correct". That was wrong.** Four unauthenticated endpoints said, in four
different ways, whether an address had an account:

| Endpoint | Old answer for an unknown address |
|---|---|
| `POST /api/auth/password/forgot` | **404** "No account found with this email address." |
| `POST /api/auth/password/verify-otp` | **422** "No account found with this email." (a validator `exists` rule) |
| `POST /api/auth/password/reset` | **404** "Account not found." |
| `POST /api/email-verification/resend` | **404** "No account found" / **409** "This email is already verified." |

POST a list of candidate addresses, keep the ones that do not answer identically, and you have a
reliable register of who holds an account here. That is personal data and a targeting list for
credential stuffing and phishing. The `verify-otp` one was the easiest to miss, because it was not a
branch in the controller at all - it was the message on an `exists:users,email` validation rule.

**`resend` NEEDED TWO EDITS, NOT ONE.** It had three distinguishable states. Removing only the 404
would have left 200-versus-409 separating "exists and unverified" from "exists and verified" - the same
information by another route, so the oracle would have survived the obvious fix. All three now answer
identically. That deliberately **gives up the "This email is already verified." hint**: a genuine
convenience that was also an existence oracle. Decision 8 rules it out; flagging it in case that trade
is unwanted.

**THE PROPERTY PINNED IS NOT "no endpoint says not-found".** It is that **status, `success` and
`message` are identical between a registered and an unregistered address.** Anything less still leaks,
which is the whole reason `resend` needed its 409 closed. A structural guard additionally fails if any
of the four controllers regains an existence-revealing message. Scope was put to the owner first
(public response shape, and two existing tests pin the leak); ruling: apply A to all four, and no
client depends on the 404.

**TWO REAL DEFECTS FOUND BY MY OWN TESTS DURING THIS TASK, both recorded rather than quietly fixed.**
1. **My first fix still leaked.** The unknown-account branch returned `'Invalid or expired code.'` while
   the genuine failed-code branch passed `$result['message']` through - which for this service is
   *'Invalid or expired **verification** code.'* Two phrasings of the same failure is still an oracle.
   The uniformity test caught it on the first run. Both branches now return one `invalidCodeResponse()`,
   and the real reason is logged instead of returned.
2. **I broke the endpoint's own tooling.** Making `resend` uniform dropped the dev-only `otp_code`, so
   `test_resend_new_otp_can_be_used_to_verify` got 422 and `test_resend_exposes_otp_in_testing_mode`
   failed. That was a regression, not an intended consequence: `otp_code` is not an enumeration channel
   (`OtpDisclosure` allows it in local/testing only, and only on the branch that actually sent), so it
   is passed through exactly as every other send endpoint does. **Had I only run the new test and not
   the whole Auth floor, this would have shipped.**

**ONE MORE THING THE TESTS GOT WRONG FIRST.** The first fingerprint compared messages verbatim and
flagged the *echoed email address* as a leak. It is the caller's own input, identical by construction,
so that comparison reports a leak that does not exist. The address is now normalised out - the
assertion got sharper, not weaker.

**A BOUNDARY EDGE I CREATED AND THEN REMOVED.** Checking `User::where(...)->exists()` in
`VerifyPasswordOtpController` needed `use App\Models\User;`, which pushed `controllers_to_models` from
**21 to 22** and failed the gate. The baseline was **not** raised. The lookup moved to the already-
injected `UserRepositoryInterface`, so no new controller->model edge exists and the count is back to 21.
The gate now fails only on the known `models_to_enums` trio (row 106).

**NEEDLE.** Restoring the 404 oracle in `ForgotPasswordController` fails 5 tests across both files,
including the structural guard and the flipped existing test; restored byte-identically.

**NO REGRESSION - controlled bisect.** Auth + Security + Review + Unit/Http/Requests, with all four
controllers and both test files swapped to HEAD and the new test moved aside: **HEAD 458 tests / 1658
assertions / 4 failures** versus **466 / 1674 / 4** after. **Failure name sets identical, diff EMPTY.**

**FOUR TESTS WERE FLIPPED, NOT WEAKENED.** Each pinned the leak by name and now asserts the corrected
behaviour, with the reason in the comment: `test_forgot_password_fails_for_nonexistent_email`,
`test_verify_otp_fails_for_unknown_email`, `test_resend_returns_404_for_nonexistent_email`,
`test_resend_returns_409_if_email_already_verified`.

**WHAT REMAINS IN RV-16.** Only the `APP_ENV=production` `.env` deploy action on the owner's machine,
which is not code. RV-16's acceptance criteria are now fully discharged on the agent's side.

## 73. The BACKLOG contradicted the code about `ScoreLedger` - corrected, not built

Record-only. No code change. Found while checking what RV-11's remaining "consolidation" item actually is,
which the next-task rule cannot select because RV-11 is `PARTIAL` rather than `OPEN`.

**THE CONTRADICTION.** `BACKLOG.md` §4 said, of RV-11:

> "One mutation path (`ScoreLedger::apply()`) writes the score; the service still has several."

That sentence is **false**, and a future session acting on it would have gone looking for a class that is
not there. Verified three ways:

- `app/**/ScoreLedger*.php` - **no such file exists**.
- `grep ScoreLedger` across `app/` - **zero matches**, so there are no callers either.
- No `score_ledger` table in `database/migrations/` (only the four `user_scores` migrations).

`R2 sec 67` had already recorded the truth - *"one mutation path (`ScoreLedger::apply()`) **does not
exist**"* - thirty lines above where the criteria were written from. **The status authority and the audit
record disagreed, and the audit record was right.**

**SO WHAT IS THE REMAINING WORK, REALLY?** Not "migrate the call sites to the single write path" - there is
no single write path to migrate to. It is: **build** `ScoreLedger::apply()` and route the mutations
through it. That is a different and larger job than the criteria text implies.

**IS IT NOW UNBLOCKED? Yes - and this is the part worth recording.** `R2 sec 1923` says building it
"requires the OWNER to pick the canonical clamp ceiling, tier bands, and starting score". **That question
has since been answered**: un2 (`de61c7b`) settled the scale, `R2 sec 67` turned it into
`START_SCORE`/`MIN_SCORE`/`MAX_SCORE` constants on `ScoreService`, and `RV37ScorePolicyTest` pins the tier
boundaries. So RV-11's `Blocked by = none` is now *correct* for the first time - it was written when the
policy was still open, and stayed right by accident afterwards.

**ALSO CONFIRMED ON THE WAY THROUGH:** `config/score.php` does not exist, and the vestigial `tier`
column is genuinely dead. The only two references to `tier` in `app/` are `$userScore->tier` in
`ScoreController:157` and `StaffOperationsController:183` - and that is the **computed accessor**
`UserScore::getTierAttribute()`, not the column, because `setTierAttribute` deliberately discards
assignments. So the column added by `2026_08_18_235839_add_tier_to_user_scores_table` is read by nobody.
Still true, still not causing a wrong score, still not fixed - dropping a column is a migration and
migrations are ask-first.

**NOT BUILT HERE, DELIBERATELY.** Building `ScoreLedger` is a new class plus migrating the mutation paths
in `applyAction`, `incrementCancellations`, `incrementNoShows` and `recordRideCompleted` - a wide-blast-
radius refactor of the score subsystem that ride completion, cancellation, no-show and rating all run
through. That is not a task to begin at the end of a very long session: this session already produced two
self-inflicted defects that only the wider floor caught (a leaked wording, and a dropped dev-only OTP
field). It is left as the next concrete piece of work, named in `STATE.md`, for a fresh session.

