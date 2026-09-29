# SyRide — Principal Review of `APP_FUTURE_AUDIT.md` + Addendum Backlog

Review date: 2026-09-29. Scope: the uploaded snapshot (app/, config/, routes/, partial database/migrations, docker/, CI workflows, k6-load/, perf-results/, README, AGENTS.md).
**Not seen** (so some items are marked VERIFY): `tests/`, `SYRIDE_COMPREHENSIVE_AUDIT.md`, `ARCHITECTURE_MAP.md`, later migrations (e.g. `ride_id` on ratings/comments), `config/system_admin.php`, Blade `layouts/*`, the k6 script behind `perf-results/*`, real `.env`.

---

## 0. How the agent must use this file

- Follow `AGENTS.md`: one task at a time → explain → smallest correct fix → verify → `VERIFIED FIX` or `VERIFIED ROLLBACK`. Log each task in the existing audit record under `docs/audit/`.
- Task IDs are `RV-nn`. Priorities: **P0** security / money loss / data exposure; **P1** real-flow correctness or ops-blocking; **P2** architecture / performance; **P3** hygiene.
- `V-nn` items are read-only checks. Run them first and record the result in the audit record; the result decides the fix. Do not code a `VERIFY` task before its check is recorded.
- New tests go in `tests/Feature/Review/`. Never edit an existing test to make it pass.
- Never put secrets or tokens in the audit record.
- Where a task says "product decision", stop and ask the owner (list in §6).

---

## 1. Verdict on the audit

**Direction is right and should be kept:** modular monolith (not microservices), money module before features, ratchet + CI gates, Strategy/Policy work in `Domain/`, DI discipline, no import cycles, the AF-1/AF-2′/AF-4 fixes.

**Where it falls short:**
1. Sections A–I are a pre-remediation snapshot; several claims are stale (§2). Readers (and the agent) will act on outdated statements unless the doc is corrected.
2. It is strong on structure/size metrics and weak on the highest-risk categories: **authorization and data exposure, money-state races, infrastructure configuration, error handling.** §4 adds 30 tasks in those areas, several of them P0.
3. It overstates a few things (Strategy pattern "wired", push "surface already there", "2 spatial indexes", performance "wins").
4. Its own P0.1 fix (`FILESYSTEM_DISK=s3`) would not work as written: the code hard-codes the `public` disk.

Reviewed code showed **no SQL injection** (bindings everywhere), good use of `hash_equals`, `random_int`, hashed refresh tokens, OTP attempt caps, and a sound rate-limiter design. Those are strengths to preserve.

---

## 2. Audit accuracy scorecard

| Audit statement | Verdict | Correct statement / action |
|---|---|---|
| A1: monolith correct, microservices harmful | **Confirmed** | Add: the real bottlenecks are the single SyCash row lock (RV-09), geo scans (RV-25), N+1 (RV-24) — not deployment topology. Do not physically move folders yet; enforce boundaries via the ratchet. |
| A3.1: uploads on per-replica disk; fix `FILESYSTEM_DISK=s3` | **Confirmed but fix is wrong** | Code calls `storeAs(..., 'public')` in ≥8 places; the default disk is irrelevant. Also a **security** problem (RV-01). AF-5 must be re-scoped. |
| A3.2: `FlushUploadedFiles` commented out | **Stale (fixed, AF-1)** | OK. |
| A3.3 / AF-1: TLS verification disabled | **Partially fixed** | `ArabicPlaceNameService` still uses `->withoutVerifying()` ×3; AF-1's scanner only looks for `verify=>false`. RV-22. |
| §0: "`rides` … 2 spatial indexes" | **Likely wrong** | Migration `2025_05_20_143208_fix_ride_spatial_columns` drops both columns (dropping their indexes) and recreates them as plain `geometry` without an index; no shown migration re-adds one. V2. |
| §C: Strategy "genuinely wired and effective — best pattern work" | **Overstated** | Only `processRideCompletionPayment` goes through the strategy. Booking, cancel, refund, no-show branch on `payment_method` in ≥8 places and call `WalletTransactionService` directly. RV-20. |
| §D3: "three auth systems (JWT, staff-JWT, Sanctum)" | **Stale + incomplete** | Sanctum is gone. Current: hand-rolled HS256 user JWT; `firebase/php-jwt` for staff (**not in composer.json**, transitive via kreait); `php-open-source-saver/jwt-auth` installed but unused; one shared secret, no audience separation. RV-04. |
| §D4: orphan `pickup_lat/lng` columns → "delete-or-wire" | **Wire them** | They eliminate 6 spatial queries per ride (RV-24). Also: the `unset()` is in `updateRide`, not "before insert". |
| §E1: "no cron advances ride status" | **Confirmed, understated** | Also: booking accepts departed rides, search returns departed rides, escrow is never auto-released. RV-10. |
| §F.13: "`push_notification_tokens` surface is already there" | **Wrong** | No route registers FCM tokens; `PushNotificationController` is unrouted and calls the service with the wrong signature. RV-27. |
| §D1/D2: float money, two ledger dialects | **Confirmed** | Add the missing invariant work: per-booking escrow counter + idempotent postings (RV-02/RV-09). |
| §A3.5: replica read-your-writes "verify per screen" | **Vague** | Concrete primary-only read list in RV-28. |
| §J AF-2′: CI "against real MySQL" | **Probably not true** | `phpunit.xml` forces sqlite. V6 / RV-18. |
| README: Redis caching of ride search; "multi-gateway wallet"; "900+ users validated"; C_full = "indexes + cache + LB" wins | **False / unsupported** | Search is not cached; no gateway exists; load tests mostly hit 422s; `C_full` is not better than `B_indexes`. RV-17, RV-32. |

---

## 3. Verify-first checks (read-only)

| ID | Check | Pass/fail criterion | Feeds |
|---|---|---|---|
| V1 | Axis order: in MySQL run `SELECT ST_Distance_Sphere(ST_GeomFromText('POINT(36.2765 33.5138)',4326), ST_GeomFromText('POINT(37.1343 36.2021)',4326))` (Damascus→Aleppo) | Correct ≈ **309 km**. If ≈ **258 km**, coordinates are transposed (MySQL 8 expects lat,lng for SRID 4326 unless `'axis-order=long-lat'`). Also compare with `Location::distanceTo`. | RV-24/25 |
| V2 | `SHOW INDEX FROM rides WHERE Index_type='SPATIAL';` | Expect none (audit claims two). | RV-25 |
| V3 | Route-buffer unit: create a ride whose route is a line; search with a point ~2 km off the line using `RideSearchService` strategy B | If no match, `ST_Buffer(...,0.05)` is being read as metres (0.05 m), not "5 km". | RV-25 |
| V4 | Behind nginx, log `$request->ip()` for two clients; send `X-Forwarded-For: 203.0.113.9` | If both requests show the nginx container IP → RV-05 confirmed. If the spoofed XFF is honoured → trust is too wide. | RV-05 |
| V5 | Feature test: `POST /api/wallet/request-charge` with `amount=0` | Expect `errors.amount` in body. If body is only `"An unexpected error occurred."` → `Handler` strips validation errors (RV-13). | RV-13 |
| V6 | In CI print `DB::getDriverName()` and count skipped tests in Money/Geo/NoShow suites | Driver must be `mysql`; skipped must be 0. | RV-18 |
| V7 | `SHOW REPLICA STATUS\G` on replica | IO/SQL threads running; `Seconds_Behind_Source` recorded under load. | RV-28 |
| V8 | `grep -rn "config('system_admin" app/` and check `config/system_admin.php` exists | If file missing, dashboard revenue is always 0 and base-rating seeding for approved drivers is skipped. | RV-19 |
| V9 | `SHOW CREATE TABLE user_ratings` and `profile_comments` | Confirm whether `unique(rater_id, rated_user_id)` still exists (would block "one rating per ride"). | RV-30 |
| V10 | `curl -X POST /api/rides` with a valid JWT | Expect 500 `BadMethodCallException` (route targets non-existent `createRide`). Also `php artisan route:cache` passes with closure routes. | RV-14, RV-08 |

---

## 4. Backlog (addendum tasks)

### P0 — fix before anything else

#### RV-01 [P0] KYC documents exposed (IDOR + public storage + unsafe filenames)
**Evidence**
- `ProfileController::formatProfileData()` always returns `documents` (`face_id_pic`, `back_id_pic`, `license_pic`, `mechanic_card_pic` URLs). The `$isOwner` parameter is never used → `GET /api/profile/{anyUserId}` returns any user's ID/licence URLs to any authenticated user.
- `VerificationController::status($userId)` (`GET /api/profile/verify/status/{userId}`) has no ownership check and returns documents + vehicle.
- KYC files live on the `public` disk (served at `/storage/...`) with names `{userId}_{time()}.{clientExt}` (`VerificationController`, `FileUploadService::uploadVerificationDocument`); chat images/complaint files also public. `VerificationController` uses `getClientOriginalExtension()` unvalidated in the stored name (an `.html` file with GIF magic passes `image|mimes` and is served as HTML).
- `users.national_id` is plaintext.

**Fix**
1. Authorization: documents only for owner or staff roles (`admin`, `system_admin`); public profile returns none. `status()` → owner or staff, else 403.
2. New private disk `kyc` (S3-compatible, private visibility; never under `public/`). Staff read via `Storage::temporaryUrl()` or an authenticated streaming endpoint (`GET /api/staff/verifications/{userId}/documents/{type}`). Never `asset('storage/...')` for KYC.
3. Filenames: `Str::uuid().'.'.$file->guessExtension()` (content-derived) everywhere (`FileUploadService`, `VerificationController`, `ProfileUpdateService`, `ComplaintService`, `DocumentController`).
4. `public` disk only for avatars/car photos.
5. `national_id`: `encrypted` cast + `national_id_hash` (HMAC-SHA256 with app key, unique) for duplicate detection; migrate existing rows.
6. Data migration: move existing objects to `kyc`, rewrite `photos.path`, delete public copies.

**Verify**: user A cannot see user B's `documents` or `verify/status`; staff can; `/storage/verifications/...` returns 404; uploading `evil.html` (GIF bytes) is stored with a `.gif` name.
**Depends**: none. Supersedes AF-5's scope (AF-5 becomes infra-only).

#### RV-02 [P0] No-show settlement can pay twice / mispay
**Evidence**: `Noshowservice::applyPenalty()` (via `resolveExpiredReports()`) loads the booking and applies the penalty **without re-checking that the booking is still `confirmed`**.
- A. Driver files passenger-no-show → passenger rides and confirms (`BookingService::passengerConfirmCompletion`; its exclusion check only looks at reports filed *by the passenger*) → escrow released 95/5 → 2 h later the scheduler overwrites status to `no_show` and `processPassengerNoShow` moves the same fare again out of SyCash (other users' escrow) to driver/primary.
- B. Same with `cancelBooking`, driver `cancelRide`, or staff cancel while a report is pending (double refund / double payout).

**Fix**
- L1 (immediate): in `applyPenalty`, lock the booking `FOR UPDATE` and require `status === confirmed`; otherwise mark the report `void` (new enum value) and log. Confirm / cancel / ride-cancel / staff-cancel flows void pending reports for that booking inside the same transaction.
- L2 (structural, lands with RV-09): `bookings.escrow_held decimal(15,2)`; every escrow movement does `UPDATE bookings SET escrow_held = escrow_held - :x WHERE id=:id AND escrow_held >= :x` and aborts unless 1 row affected. Add `wallet_transactions.posting_key` (nullable, unique) set on once-only postings (e.g. `escrow_release:booking:{id}`); partial cancels use a distinct key per step.
- Invariant for `ledger:reconcile`: `SyCash.balance == SUM(bookings.escrow_held)`.

**Verify**: tests for A and B assert SyCash delta = 0 on the second settlement and one ledger row per posting; add a two-process concurrency test if feasible.

#### RV-03 [P0] Staff cancel endpoints strand money and have no role gate
**Evidence**: `StaffOperationsController::cancelTrip/cancelBooking` sit in the `staff` group with no role list (any `support_agent`). They flip statuses directly — a comment says "Refund all confirmed bookings (e-pay only)" but no wallet/score/cash-fee service is called; seats restored by hand; `launched` rides rejected. E-pay passengers keep paying into SyCash with a cancelled booking → escrow drift.
**Fix**: route both through `RideService::cancelRide` / `BookingService::cancelBooking` with an explicit actor/context (`staff`, reason, refund policy = full, score policy per owner decision). Add `staff_actions` audit table (employee_id, action, subject, reason, before/after). Restrict to `staff:admin,system_admin` unless owner says otherwise.
**Verify**: e-pay ride with 2 confirmed bookings → passengers refunded, SyCash reduced, ledger rows, notifications; `support_agent` → 403 (if restricted).

#### RV-04 [P0] JWT integrity
**Evidence**
- `JwtService::generateSignature()` uses `config('jwt.secret')` with no emptiness check; `.env.example` ships `JWT_SECRET=` blank. PHP 8.2 coerces `null` to `''` for `hash_hmac` (deprecation only) → tokens signed with an empty key are forgeable. (`StaffJwtService::secret()` does throw.)
- User and staff tokens share one secret; `JwtAuthMiddleware` only checks `type === 'access'`. Staff payload also has `type=access`, `sub` = employee id, `ver` = employee `token_version` → a staff token is accepted as **user #`sub`** when versions match (users default 1; staff seeded 0 or 1).
- Three implementations (see §2).

**Fix**
1. Boot guard in `AppServiceProvider` (outside local/testing): throw if `jwt.secret` is empty or < 32 bytes.
2. One `TokenCodec` on `firebase/php-jwt` (declare it in `composer.json`) with mandatory `iss`, `aud` (`user`|`staff`), `exp`, `iat`, `jti`, `typ`; each middleware rejects the wrong `aud`. Optionally use separate secrets (`JWT_SECRET` / `STAFF_JWT_SECRET`; README already mentions the latter but code ignores it).
3. Remove `php-open-source-saver/jwt-auth`, the hand-rolled encoder, unused `generateAdminTokenPair/generateAdminAccessToken`, `User::getJWTIdentifier/getJWTCustomClaims`.
4. Owner decision: lower access TTL from 600 min to 15–60 min (refresh already exists).

**Verify**: staff token on `/api/user` → 401; user token on staff route → 401; production boot fails with empty secret; `alg:none` and tampered-signature tokens rejected.

#### RV-05 [P0, VERIFY V4] Client IP is wrong behind nginx
**Evidence**: `TrustProxies::$proxies` is `null`; nginx forwards `X-Forwarded-For` but Laravel ignores it; RoadRunner's remote address is the nginx container. If confirmed: every `ip:` rate-limit bucket in `RouteServiceProvider` (20/min/IP on auth endpoints at multiplier 4; the `auth` limiter key is shared by signup, login, OTP, forgot, refresh) collapses into **one bucket for all users**; `GateDocumentation` allow-list never matches; logs show the nginx IP.
**Fix**: `TRUSTED_PROXIES` env (nginx network CIDR) in `TrustProxies`; in nginx use `real_ip` for the outer TLS terminator (`set_real_ip_from <CIDR>; real_ip_header X-Forwarded-For; real_ip_recursive on;`) and `proxy_set_header X-Forwarded-For $remote_addr;` so clients cannot inject. Never `'*'` unless nginx overwrites XFF.
**Verify**: V4 + a test that two clients get separate auth buckets.

#### RV-06 [P0] Horizon dashboard is unauthenticated; nginx leaks upstream address
**Evidence**: `HorizonServiceProvider::gate()` returns `true` (comment: "port 8080 only exposed to localhost" — false for `render.yaml`, and for any reverse proxy that forwards paths). Horizon shows job payloads and can retry/delete jobs. `nginx-docker.conf` adds `X-Upstream-Addr $upstream_addr` to every response (internal container IPs) — same class of leak the audit removed under T2-6.
**Fix**: gate to `local`/`testing`, or require a staff `system_admin` token via custom middleware / IP allow-list from config; set `HORIZON_PATH` to something non-default in production; remove `X-Upstream-Addr`.
**Verify**: unauthenticated `GET /horizon` → 403/404 with `APP_ENV=production`.

#### RV-07 [P0] Committed secrets and stale credentials
- `phpunit.xml` contains what looks like a real OpenRouteService key (`OPENROUTE_API_KEY`, `[redacted]`). Rotate at the provider; use a dummy value; ensure tests use `Http::fake`.
- Every `k6-load/*.js` embeds ~70 signed JWTs (`iss` = `https://api.onwayride.me` ⇒ minted with the production `APP_URL`; `sub` 1–2 look like admin-level accounts). They are expired but must go: delete, git-ignore, generate at `setup()` via login. Confirm dev/staging `JWT_SECRET` ≠ production; if not, rotate production.
- `start-syride.ps1` prints `primary@admin.com / admin`; `start-cluster.bat` is obsolete → delete both.
- Add gitleaks (CI + pre-commit). Repo history is public: rotation matters more than history scrubbing.

---

### P1 — correctness and ops-blocking

#### RV-08 [P1] Deploy pipeline is broken
- `deploy-to-vps.yml` runs `docker compose exec -T app php artisan migrate --force` and `... optimize`; compose has services `app1..app5`, `queue`, `scheduler` — **no `app`** — so both steps fail after containers restart and migrations never run on deploy.
- Compose overrides `CMD` (`octane:start`), bypassing `docker/start.sh` (which migrates, caches, links storage); Render uses `start.sh` → two divergent boot paths.
- Workflow triggers on branch `samer`; README says `main`.
- `git remote set-url origin https://x-access-token:${GITHUB_TOKEN}@…` persists the token in `.git/config` on the VPS.
- `optimize` runs `route:cache`; `routes/*.php` contain closure routes — VERIFY (V10) it passes.

**Fix**: run migrations through a one-off (`docker compose run --rm app1 php artisan migrate --force`) and fail the deploy on error; do caches at image build/entrypoint; tag images by git SHA; post-deploy smoke test through nginx (`/up`, `/api/ping`) with automatic rollback to the previous tag; use a deploy key or a one-shot `http.extraHeader` instead of persisting the token; **choose one deployment target** and delete the other's files (`render.yaml`+`start.sh` or compose+nginx).
**Verify**: run the workflow against staging.

#### RV-09 [P1] Money concurrency and side-effect ordering
**Evidence**
- Lock order is inconsistent: `chargePassengerForBooking` locks passenger wallet → SyCash; `releaseEscrowToDriver`/`releaseEarningsToDriver` lock SyCash → primary → driver; refunds lock SyCash → passengers. A user who is both a passenger (booking) and a driver (being paid) can deadlock. Ride/booking order also differs (`cancelRide` locks ride then bookings; `passengerConfirmCompletion` locks booking then ride; `acceptBooking` locks only the booking then decrements seats).
- SyCash is one row locked by every e-pay booking, release, refund and no-show → global serialization; the lock is held while notifications/broadcasts run inside the transaction (`bookRide` steps 10–11).
- `EPayPaymentStrategy` catches `\Exception` and returns failure, hiding deadlocks from Laravel's retry; money transactions use `attempts=1`.
- Events/jobs dispatched inside transactions (`broadcast(new RideBooked($ride,$booking,$passenger))` with `SerializesModels`; `queue.php` redis `after_commit=false`): a worker can run before commit → `ModelNotFoundException`, event lost; models are re-read from the replica.

**Fix**
1. Lock hierarchy `ride → bookings (id ASC) → wallets (id ASC, system wallets included)`; `WalletLocker::lockMany()` used everywhere; lock the ride in `acceptBooking`.
2. `DB::transaction(fn, 3)` for money paths; strategies must not swallow exceptions.
3. `after_commit => true` for redis + `public bool $afterCommit = true` on events; events carry ids/primitives, not models; notifications/broadcasts via `DB::afterCommit()`.
4. Design (wave 3): replace the single mutable SyCash balance with per-booking escrow (`bookings.escrow_held`, see RV-02); SyCash balance is derived/reconciled. If too large, at minimum validate first, lock wallets last, commit fast.

**Verify**: concurrent bookings + confirmations on valid data; zero MySQL 1213 deadlocks; record throughput.

#### RV-10 [P1] Ride lifecycle and escrow liveness
**Evidence**: `BookingService::assertBookingRules` has no `departure_time` check; `RideSearchService` uses `whereDate('departure_time','=',date)` without `>= now()` (today's search shows departed rides); nothing advances `active → launched/finished/expired`; scheduler only has token/OTP/no-show jobs. Consequences: e-pay escrow for a ride nobody confirms or reports stays in SyCash forever; colluding accounts can book after departure and instantly confirm (+10 score each). `validateCanCancelRide()` is commented out (driver can cancel after departure).
**Fix**: booking requires `departure_time > now() + config(min_minutes)`; search filters `departure_time >= now()` with a range (not `whereDate`); command `rides:advance-status` (every 5 min): empty rides past departure → `finished` (+ cash-fee refund per policy); rides with confirmed bookings and no passenger action after `rides.auto_confirm_hours` and no pending/disputed no-show → auto-complete (release escrow 95/5, score events). Block driver cancel after departure or after any booking completed. **Product decision**: auto-confirm hours.
**Verify**: `Carbon::setTestNow` tests for each transition.

#### RV-11 [P1] Score subsystem is internally inconsistent
**Evidence**: `UserScore::applyDelta` clamps to `[0,100]` (docblock says 200) and is used by no-show recording; `ScoreService::applyAction/applyScore` do not clamp above 100 → a user at 160 who no-shows is set to 100 (−60 instead of −15). Starting score is 70 (`initializeScore`, `getScore`) vs 100 (`applyAction`/`applyScore` `firstOrCreate`). Tier: model accessor (Gold ≥ 80 / Silver ≥ 60 / Bronze ≥ 40 / Restricted) vs `resolveTier` (platinum ≥ 200 …); `tier`/`cancel_rate` are not fillable and have no-op mutators, so writes are silently dropped. Three near-duplicate mutation paths.
**Fix**: one `ScoreLedger::apply()` (transaction, `user_scores` row lock, one clamp policy from config, one tier function — computed; drop the two columns); route every caller through it; delete `applyScore`, `applyDelta`, legacy `recordRideCompleted`.
**Verify**: table-driven test per `ScoreAction` incl. boundaries 0/99/100/160.

#### RV-12 [P1] Account status model
**Evidence**: `users.status` overloads −1 banned / 0 "logged out" / 1 active.
- `LoginController` (and Google callback) reject `status === -1` without checking `ban_expires_at`. Auto-lift only exists in `JwtAuthMiddleware`, which requires a still-valid access token; bans revoke tokens and TTL is 10 h → **temporary bans are effectively permanent** unless an admin unbans.
- Logout = `revokeAllTokens` (all devices) + persisted `status=0`.
- `AdminUserService` (`suspended_users`, `resolveUserStatus`), `AdminDriverService::resolveDriverStatus`, `StaffOperationsController::userProfile` treat `status==0` (logged out) as *suspended* and ignore banned (−1).
- `auth.user.{id}` cache (5 min) is not busted by `LoginController`/`updateUserStatus`/`unban` → stale `USER_INACTIVE`/`USER_BANNED` for up to 5 min.
- `UserRepository::createUser` ignores the incoming `status`, so SignupController's "status 0" is a no-op.

**Fix**: keep `banned_at/ban_expires_at/ban_reason`; drop persisted "logged out"; one `BanService::isBanned()` (handles expiry and lifts) used by login, Google callback, middleware; logout revokes only the presented refresh token (`jti`/token id) with a separate logout-all; `UserObserver::saved` busts `auth.user.{id}` when status/token_version/ban fields change; fix dashboard labels; backfill migration.
**Verify**: temp ban expires → login works with no old token; login right after unban works; dashboard counts correct.

#### RV-13 [P1] Error model
**Evidence**: (1) `Handler::register()` has a catch-all renderable typed `Throwable` that runs before Laravel's default `ValidationException` rendering, so for `api/*` it returns `{"message":"An unexpected error occurred.","code":422}` **without the `errors` bag** for every FormRequest / `$request->validate()` endpoint (BookRideRequest, OTP requests, Wallet*Request, `searchRides`, `cancelPartialSeats`, `bulkAction`…) — VERIFY V5. (2) 40+ controller methods `catch (\Throwable)` and return `$e->getMessage()` (SQL text, table names) with 422/500; domain rule violations (`InvalidArgumentException`) return 500 (e.g. `passengerConfirmCompletion`), polluting alerts. (3) `ProfileController::comment/rateUser/update` pass `$e->getCode()` (SQLSTATE strings such as `'23000'`) as the HTTP status → a second exception. (4) Mixed envelopes (`success` vs `status`; `WalletRequestController` returns both).
**Fix**: `App\Exceptions\Domain\*` (code + http status: NotFound, Forbidden, Conflict, InsufficientFunds…); `Handler` maps ValidationException → 422 + errors, Authentication → 401, Authorization → 403, Domain → own status/code, everything else → generic 500 + request id; controllers stop catching `Throwable`; one envelope `{success,data,error:{code,message,details}}` (introduce `/api/v1` when the shape changes; coordinate with the Flutter client).
**Verify**: per-exception tests; grep shows no `getMessage()` in controller JSON.

#### RV-14 [P1] Route/controller mismatches and lying endpoints
- `POST /api/rides` → `RideController@createRide`, which does not exist (`create` does) → `BadMethodCallException` → 500. The validated `CreateRideRequest` path is unreachable; the live `createRideWithRoute` uses weaker inline rules (`price_per_seat min:0`, no max → overflows `decimal(8,2)`; `communication_number` free string).
- `POST /rides/{id}/finish` and `/driver-confirm` return fabricated `ride_status:'active', driver_confirmed:false` regardless of state.
- `RideController::cancel` is an unrouted duplicate of `cancelRide`.

**Fix**: route `POST /rides` → `create` using `CreateRideRequest` (add route/lat/lng rules; reverse-geocode fill); make `create-with-route` reuse it, then deprecate; price bounds from config; delete `finish`/`driver-confirm` routes and docs (or return 410); delete `cancel`. Add a **routes-integrity test**: every route action resolves to an existing public method (reflection over `Route::getRoutes()`), and every public controller method is routed or explicitly annotated.

#### RV-15 [P1] Booking idempotency
**Evidence**: `BookingService::bookRide` uses Redis key `booking:idem:{key}` checked outside the transaction, **not scoped by user** (another user replaying a key gets that booking, incl. phone numbers), lost on Redis flush; `BookRideRequest::prepareForValidation` auto-generates a UUID when missing, defeating idempotency.
**Fix**: `bookings.idempotency_key` + `unique(user_id, idempotency_key)`; insert-or-return inside the transaction; require the key (v1) — legacy clients keep the auto-key but flagged non-idempotent; same pattern for other money POSTs.
**Verify**: parallel same-key requests → one booking, same id; different user + same key → separate.

#### RV-16 [P1] OTP and mail flows
- `TextMeBotOtpService::sendOtp` returns `otp_code` when the API key is unset **or** when sending fails, in any environment; `sendViaTextMeBot` does `sleep(5)` inside a worker (16 per node); `/api/otp/*` and `/api/textme-otp/*` are consumed by nothing (wallet creation uses email OTP) but remain public send endpoints.
- `EmailOtpService` returns `otp_code` when `EMAIL_OTP_MODE=testing` (echoed by `ForgotPasswordController`, `WalletController`) — one misconfigured env = account takeover. Mail is sent synchronously inside `SignupController`'s DB transaction.
- OTP codes are stored in plaintext. Enumeration: signup 409, forgot 404, `exists:users,email`.

**Fix**: delete phone-OTP controllers/services/routes unless the client uses them (**owner to confirm**); boot guard: `EMAIL_OTP_MODE=testing`, `WALLET_OTP_MODE=testing`, `OTP_BYPASS_ENABLED=true` in production ⇒ refuse to boot; never return `otp_code` unless `app()->environment('local','testing')`; `Mail::queue` after commit; store `hash_hmac('sha256',code,key)`; uniform 202 responses for forgot/signup (**product decision**).

#### RV-17 [P1] Load-test validity
**Evidence**
- Requests do not match the API contract: `GET /rides/search` sends `pickup_lat/pickup_lng/destination_lat/destination_lng/seats`, endpoint requires `source_lat|source_lng|dest_lat|dest_lng|departure_date|seats_required` → 422; `POST /rides/{id}/book` lacks `communication_number` → 422; `create-with-route` sends `from_lat/to_lat` (hammer) or `origin_lat` (breakpoint) → 422; `/otp/send` sends `phone` (needs `phone_number`) → 422. `setResponseCallback` treats 422 as success and `real_5xx_errors` counts only ≥ 500. ≈ **44 %** of the weighted mix (20+10+7+5+2) never reaches business logic.
- Tokens, ride ids and booking ids are hard-coded (tokens expired); `throttle:api` is 60/min/user with ~66 users unless `RATE_LIMIT_ENABLED=false` — the scripts don't assert it.
- `perf-results`: A→B→C = 518 → 537.6 → 530.9 rps, p95 415 → 323 → 337 ms; B→C shows **no gain** from cache + LB (within noise) and all three have a ~4.8–5.0 s max (warm-up artefact). The script that produced them isn't in the repo. Little's-law "9,300–13,000 users" rests on this mix. Script headers contradict configs (Stage 1/2, rates).

**Fix (spec)**: shared `k6-load/lib/` (login in `setup()`, seed helper); `constant-arrival-rate` scenarios; a seeding command producing valid rides/bookings; per-endpoint tags with a threshold "2xx rate ≥ 95 % per named endpoint"; separate READ and WRITE scenarios (unique data per VU for book/confirm); record `http_req_duration{name:…,expected_response:true}`; each config run 3× with a 5-minute steady window; report p50/p95/p99, status breakdown, DB threads_running and lock waits; commit result JSON with the git SHA; regenerate README numbers only from these; add the missing script.

#### RV-18 [P1, VERIFY V6] CI signal
**Evidence**: `phpunit.xml` sets `DB_CONNECTION=sqlite`/`:memory:` without `force`; CI writes DB settings only to `.env`, so PHPUnit's env wins → CI runs sqlite while the workflows provision MySQL, and the audit says money/geo suites skip when the driver isn't mysql. `sonar.yml` runs the whole suite (374 errors / 53 failures per §J) → red on `main`. Migration `2026_08_18_120000_add_launched_status_to_rides` runs raw `ALTER TABLE … MODIFY` with no driver guard (siblings have one).
**Fix**: `phpunit.mysql.xml` (or `force="true"` via env) and `DB_CONNECTION=mysql` in workflow `env:`; a test asserting the driver when `CI_REQUIRE_MYSQL=1`; fail the job if any Money/Geo/NoShow test is skipped; guard the migration; triage the 374 errors as tracked tasks (send `tests/`).

#### RV-19 [P2, VERIFY V8] Fake or derived numbers in admin
- `AdminReportService::getStats()`: `pending_complaints => 0` hardcoded; revenue uses `config('system_admin.phone')` — no such config file uploaded (`config/admin.php` only has `admin.system_admin.phone`), so revenue is likely permanently 0; `VerificationRepository::verifyDriver` uses `config('system_admin.email')` too.
- N+1: `AdminDriverService::getDrivers` (avg-rating query per driver), `formatDriver → resolveDriverPhone` (query per row), `getStatusCounts` (6 counts); `suspended_drivers` hardcoded 0.
  **Fix**: real complaint counts; revenue from the platform wallet (RV-21); `withAvg`/one grouped query; phone from a real `users.phone` (product decision) instead of the last ride's communication number.

#### RV-20 [P2] Payment strategy is one-third wired
`PaymentStrategy` defines booking/completion/refund, but `BookingService`, `RideService`, `Noshowservice` branch on `payment_method` in ≥ 8 places and call `WalletTransactionService` directly; only `processRideCompletionPayment` is used; `processBookingPayment`/`processRefund` are unused (and `EPay::processRefund` maps to driver-cancel semantics).
**Fix** (with the AF-6 money module): a `PaymentOrchestrator` with explicit operations `hold`, `release`, `refund(policy)`, `settleNoShow(target)`, `forfeit`; strategies implement all; services never test `payment_method`. Ratchet the count of `payment_method ===` outside `Domain/Payment`.

#### RV-21 [P1] Wallet identity and money creation
- `POST /wallet/create-direct` creates a wallet for any authenticated user with an arbitrary, unvalidated `phone_number` — bypasses the OTP flow (`initiate` + `verify-and-create`).
- System wallets are looked up by phone (`lockWalletByPhone(config('admin.sycash.phone'))`; defaults `0987654321` / `0912345678` are in the repo) and the lookup never asserts `user_id IS NULL`. If system wallets are unseeded, a user can claim that phone and receive escrow; `SystemWalletSeeder::firstOrCreate(['phone_number'=>…])` would adopt it. `SyrideSeeder` creates a different SyCash wallet (`+963999000001`) that no service reads.
- Money is created from nothing by one actor with no counter-entry: `AdminDashboardController::chargeWallet` (≤ 1,000,000), `PassengerProfileController::chargeWallet` (≤ 10,000,000), wallet-request approval; different `type` strings (`admin_credit` / `admin_charge`).

**Fix**: `wallets.kind` enum(`user|escrow|platform|treasury`), one row per system kind (`user_id NULL`); resolve by kind; assert `isSystemWallet()`; remove `create-direct` (or require verified user + phone regex + OTP); top-ups become ledger transfers `treasury → user` (double-entry, Σ balances = 0); maker-checker above a threshold; daily limits; rename Primary Escrow → `platform`, SyCash → `escrow`.

#### RV-22 [P1] TLS and log hygiene leftovers
- `ArabicPlaceNameService` `->withoutVerifying()` ×3; extend `TlsAndOctaneHygieneTest` to ban `withoutVerifying`.
- `GoogleController::callback` logs `$request->all()` (OAuth `code`/`state`) and sets Guzzle `verify=false` in local/testing.
- `LOG_LEVEL` default `debug` with `Log::info` on hot paths; a single non-rotating file shared by 5 replicas through a bind mount.
  **Fix**: remove; redact; JSON logs to stderr; `LOG_LEVEL=info` in prod; Docker log rotation.

#### RV-23 [P2] Complaints
- `Complaint::$fillable` lacks `ride_id`/`complained_id` and the create migration has no such columns → `Noshowservice::handleConflict` silently loses the linkage (VERIFY columns; fix fillable regardless).
- `ComplaintService::submit` assigns every complaint to the first active agent; `StaffComplaintService::respond` and the controller both notify (duplicate).
- `GET /staff/complaints/{id}` mutates state and is racy.
  **Fix**: migration + fillable + FK; least-loaded assignment (as `ContactController`); one notification; conditional `UPDATE … WHERE assigned_to IS NULL`.

#### RV-24 [P1] Ride payload and N+1
**Evidence**: `Ride::getPickupLocationAttribute/getDestinationLocationAttribute` run `SELECT ST_AsText(...)` on every access (read connection → replica); `RideResource` reads each **three times** (truthiness, lat, lng) → 6 queries per ride per response, plus 2 more when a Ride is serialized; lists `select *` (geometry blobs + `route_geometry` JSON with thousands of points) and `RideResource` returns `route.geometry` in list responses; `searchRides`, `getRides`, `myBookings`, `index` are unpaginated.
**Fix**: populate `pickup_lat/pickup_lng/destination_lat/destination_lng` on write (mutators set both), read from them, backfill from `ST_X/ST_Y` (after V1) then NOT NULL; explicit column lists without geometry; `RideResource` summary vs `RideDetailResource` with geometry; cursor pagination (default 20, max 50).
**Verify**: `DB::listen` test — search with 50 rides ≤ 5 queries.

#### RV-25 [P1, VERIFY V1–V3] Search correctness and cost
**Evidence**: (a) all WKT is `POINT(lng lat)` with SRID 4326 and no `axis-order` option (V1); (b) `whereDate` is non-sargable and defeats `rides_status_departure_seats_index`; (c) strategy B parses `route_geometry` JSON → GeoJSON → `ST_Buffer` per candidate row and is OR-ed with A so both always run; the buffer argument `0.05` is documented as degrees but MySQL treats it as metres for SRID 4326 (V3); (d) no spatial index (V2), and `ST_Distance_Sphere` cannot use one anyway.
**Fix** (after V1–V3): `pickup_point`/`destination_point` SRID-4326 NOT NULL POINT with SPATIAL INDEX; date range + route bounding-box columns (`min_lat,max_lat,min_lng,max_lng`, indexed) as pre-filter; exact `ST_Distance_Sphere` after; route match via `route_line` LINESTRING SRID 4326 with `ST_Distance(route_line, point) <= :meters`; meters in config (`max_distance_m`, `route_buffer_m`); result cap. If V1 shows transposition: use `'axis-order=long-lat'` everywhere (mutators, `RideSearchService`, `RideRepository::updateRide`, seeders, fixtures) and swap stored data (`ST_SwapXY`).
**Verify**: `EXPLAIN` shows range/spatial index; a point 2 km off the route matches with `route_buffer_m=5000`; 500k-ride benchmark (`BulkRideSeeder`) p95 < 200 ms.

#### RV-26 [P2] Staff/admin authorization matrix
**Evidence**: `AdminAuthService::authenticate` admits only `isAdminRole()` (system_admin, sycash) so `admin` cannot use `/api/admin/login`; every `/api/admin/*` route is behind `staff:admin,system_admin` ⇒ **`sycash` (the financial role) is denied everywhere**; wallet-approval routes are `staff:system_admin` ⇒ no separation of duties; `AdminJwtMiddleware` (`auth.admin`) is unused; two login flows; two verification-approval implementations (`AdminDashboardController::approveVerification/rejectVerification` vs `StaffAdminController` — the admin one doesn't fire `UserVerified`, doesn't notify on reject, uses `app()`); `employees` routes are system_admin-only while the service supports admin→support_agent.
**Fix**: role × endpoint matrix as data (`config/permissions.php`) + Gate/Policy + one parametrised test hitting every route with every role; **owner decision**: sycash approves wallet requests, system_admin doesn't; unify login (`/api/staff/login`), delete `AdminAuthService`/`AdminJwtMiddleware`; a single `VerificationService`.

#### RV-27 [P1] Push pipeline cannot deliver
- No route registers/removes FCM tokens; `PushNotificationController::registerToken` passes a `User` where `PushNotificationService::registerToken(int,string,string)` expects an int and 4 args → TypeError; duplicate job `SendPushNotification`.
- `FcmSenderService` is silently disabled when the credentials file is missing; compose mounts only `storage/logs`; `.env.example` says `FCM_CREDENTIALS`, README says `FIREBASE_*`; one HTTP call per token.
- `notifications` / `user_notifications` are never pruned.

**Fix**: `POST /api/push-tokens`, `DELETE /api/push-tokens/{token}`; delete the duplicates; secret mount + startup warning/health flag when unconfigured in production; `sendMulticast`; `notifications:prune --days=90` scheduled.

---

### P2 / P3 — hardening and hygiene

#### RV-28 [P2] Infrastructure hardening
- MySQL: compose creates only root (`.env.example` says "prefer non-root") → `MYSQL_USER/PASSWORD` + least-privilege grants. Replication uses root credentials and deprecated `CHANGE MASTER TO/START SLAVE` → dedicated `REPLICATION SLAVE` user, `CHANGE REPLICATION SOURCE TO`, lag monitoring (V7). `max_connections` vs 5 × 16 workers × (primary + replica PDO) + Horizon.
- Redis: no persistence, one instance for cache/queue/session → AOF + volume, separate logical instance for queues (`noeviction`).
- Docker: no healthchecks; `composer install` without `--no-dev` (ignition/sail/phpunit in prod image); bind-mounted `storage/logs` ownership vs `USER www-data` on Linux.
- nginx: `max_fails=0` disables passive failure detection, no connect timeout, `client_max_body_size 20M` vs 2–5 MB app limits.
- `env()` outside `config/` (`TextMeBotOtpService`, `WhatsAppOtpService`, `EmailOtpService`, `TextMeOtpController`, `ArabicPlaceNameService`) breaks under `config:cache` when `.env` isn't a real process env (the `start.sh` path) → move to config.
- CORS allow-list is localhost only → `CORS_ALLOWED_ORIGINS` env.
- **Primary-only reads** (`->useWritePdo()`): `JwtService::findUserCached` miss path, `LoginController` user lookup, `WalletController::getBalance`, any validation gating money/permissions outside a transaction.

#### RV-29 [P2/P3] Auth hardening batch
- Refresh rotation has no reuse detection → token family; revoke all on reuse.
- `Cache::remember('auth.user.{id}', User)` and `conversation.{id}` serialize full Eloquent models (password hash, `national_id`, `google_id`) into Redis → cache a minimal DTO.
- `User::$fillable` includes `status, token_version, is_verified_*, verification_status, wallet_id, national_id, ban_*, google_id` → shrink; `forceFill` in trusted services.
- Google linking to an unverified local account: invalidate its password and revoke tokens (pre-hijack); require `email_verified`.
- `RefreshTokenController` rate limit is per-IP only (see RV-05).
- Chat `startConversation` lets anyone message anyone → block/report or require a shared ride.
- `RideResource`/`BookingResource` expose `communication_number` to every authenticated user → gate to booked passengers/driver (**product decision**).
- Staff login returns early on unknown identifier (timing).

#### RV-30 [P3] Data model hygiene
- Ratings/comments: original `unique(rater_id, rated_user_id)` vs the "one per ride" rule (V9). The seeded 3.0 rating counts as a real rating and is inserted twice for approved drivers (`UserObserver` with rater NULL + `verifyDriver` with an admin rater) → pick a prior or drop it.
- Time: store UTC (`APP_TIMEZONE=UTC`), convert at the edge; `CreateRideDTO` does `Carbon::parse($v,'Asia/Damascus')->toDateTimeString()` which drops offsets (ISO strings ending in `Z` shift by 3 h); `'Asia/Damascus'` hard-coded in 6 places.
- `wallet_transactions.reference` is unindexed though used for reconciliation → index `(reference, type)`; `posting_key` unique (RV-02).
- `profiles.*_pic` duplicates `photos`; legacy `rides.passengers_confirmed`, `driver_confirmed_at`, `finished_at`.
- Migration `down()` bugs (`create_user_notifications` drops the wrong table); consider `schema:dump` once stable.

#### RV-31 [P3] Dead code wave (grep-confirm each before deleting)
`RideService::finishRide / driverConfirmCompletion / checkAndCompleteRide / notifyAllForConfirmation`; `WalletTransactionService::releaseEarningsToDriver`; `ScoreService::recordRideCompleted / applyScore`; `RideStatus::AWAITING_CONFIRMATION` + 7 read sites (after data migration); `RideRepository::createRide / bookRide / getUpcomingRides / updateRide / deleteRide` (+ interface); unused `rideRepository` dependency in `BookingService`; `GeocodingServiceInterface` (not implemented); `Jobs/SendPushNotification`; `NotificationChannel` (broadcast class with an invalid empty `join`); `Notifications/RideBookedNotification`, `RideCancelledNotification`, `UserVerifiedNotification` (scaffold stubs); `AdminJwtMiddleware`; `JwtService::generateAdminTokenPair`; `WhatsAppOtpService` bypass helpers; `resources/views/auth/admin/*` (routes and `layouts.admin` don't exist); `VerifyCsrfToken::$except` and `EncryptCookies::$except` entries for API paths; `resources/js/bootstrap.js` (Mix syntax under Vite); `start-cluster.bat` / `stop-cluster.bat`; `.rr.yaml` (overwritten by `start.sh`); `stripe/stripe-php` unless a gateway is built.

#### RV-32 [P3] README / docs corrections
Scheduled command names (`otp:cleanup`, `noshow:resolve` every minute — not `otps:cleanup` / `noshow-reports:resolve` / 15 min); deploy trigger branch; "Redis query caching for ride-search results" (false); "multi-gateway wallet" (no gateway); "real-time" (client dead, audit §G); "900+ concurrent users validated" (RV-17); perf table (C ≈ B); `db:seed` description; `.env` variable names (FCM). Add `docs/ARCHITECTURE.md` (contexts, lock hierarchy, state machines, ledger invariants) and 3 ADRs (monolith, ledger design, storage/KYC).

#### RV-33 [P2] Ratchet additions (`BoundaryDependencyTest`)
Count-only-decreases rules for: `catch (\Throwable` in Controllers; `getMessage()` inside controller JSON; `env(` outside `config/`; `payment_method ===` outside `Domain/Payment`; `float` money parameters in money services; `Storage::disk('public')` / `store(…,'public')` in KYC code; `withoutVerifying`; controller methods not routed.

---

## 5. Target design (what the tasks converge to)

1. **Money engine:** double-entry ledger (`treasury`, `escrow`, `platform`, user wallets), `Money` VO end to end, `LedgerEntryType` enum, per-booking `escrow_held` with guarded `UPDATE`s, once-only `posting_key`, lock hierarchy `ride → bookings → wallets`, all side effects after commit, nightly `ledger:reconcile` (Σ balances = 0; `escrow == Σ escrow_held`).
2. **State machines:** explicit allowed-transition tables for `Ride`, `Booking`, `NoshowReport`, `WalletRequest`, tested; no service writes a status without going through the table.
3. **API contract:** domain exceptions → one Handler → one envelope; FormRequests everywhere; pagination on every list.
4. **Auth:** one token codec, audience-separated, per-device logout, ban service.
5. **Storage:** private `kyc` disk + signed access; public disk for avatars only.
6. **Modules:** keep the folder layout; enforce contexts (Identity, Marketplace, Payments, Trust, Comms, Back-office) with the ratchet; split only if a real forcing function appears.

---

## 6. Owner decisions needed before the agent starts the marked tasks

1. `sycash` role: approves wallet requests? (RV-26)
2. Auto-confirm window for unconfirmed rides / escrow release policy. (RV-10)
3. Platform fee on cancellation payouts (currently none: driver gets 100 % of the non-refundable share). (RV-09)
4. Phone-OTP endpoints: keep or delete. (RV-16)
5. Show driver phone to all authenticated users, or only booked passengers? (RV-29)
6. Staff-initiated cancel: refund fully, and score impact? (RV-03)
7. One deployment target: compose+nginx or Render. (RV-08)
8. Account-enumeration policy (uniform responses vs friendly errors). (RV-16)
9. Access-token TTL (600 min → 15–60). (RV-04)
10. Add a real `users.phone`? (RV-19)

---

## 7. Execution order

| Wave | Tasks | Notes |
|---|---|---|
| 0 | V1–V10 | Record results first. |
| 1 | RV-07, RV-06, RV-01, RV-04, RV-05, RV-02 (L1), RV-03 | Security / money loss. RV-07 first (rotation is time-sensitive). |
| 2 | RV-08, RV-18, RV-13, RV-14, RV-16, RV-22 | Pipeline and error model; CI on MySQL before touching money code. |
| 3 | RV-09, RV-02 (L2), RV-10, RV-11, RV-15, RV-21, RV-20 | Money module (AF-6 extended). Needs owner decisions 2, 3, 6. |
| 4 | RV-24, RV-25, RV-17 | Performance; k6 redo only after RV-24/25 so results mean something. |
| 5 | RV-12, RV-26, RV-27, RV-29, RV-19, RV-23 | Auth model, roles, push. |
| 6 | RV-28, RV-30, RV-31, RV-32, RV-33 | Hardening and hygiene. |

Update `APP_FUTURE_AUDIT.md` sections A–I to match §2 once Wave 1 lands, so the agent stops reading stale claims.

---

## 8. Files to send next (so the remaining items can be verified)

`tests/` (to triage the 374 errors and check for skipped suites), the migrations not yet shared, `config/system_admin.php` (if it exists), `docs/audit/ARCHITECTURE_MAP.md` and `SYRIDE_COMPREHENSIVE_AUDIT.md`, the k6 script that produced `perf-results/*`, and a redacted list of production env variable **names** (not values).

---

## 9. Wave-0 execution log — V1–V10 (agent, 2026-09-29)

Run against the repo's scratch MySQL 8.2.0 (single instance). Every result is
pinned by `tests/Feature/Review/WaveZeroVerificationTest.php` (9 tests, 24
assertions, deterministic; recording tests — each asserts CURRENT truth and
carries a comment saying which RV task updates it; no existing test was edited).

| ID | Result | Settled fact (raw evidence) |
|---|---|---|
| V1 | **FAIL — transposed** | Damascus→Aleppo as the code writes `POINT(lng lat)` w/ SRID 4326 = **258 km**; the real ~309 km only appears with lat-first order. MySQL 4326 defaults to lat,lng axis → every stored geometry is misplaced (~country-shifted) and every distance wrong. |
| V2 | **FAIL — no spatial index** | `information_schema.STATISTICS` SPATIAL query = 0 rows (audit's "2 spatial indexes" stale; migration `2025_05_20_143208` dropped them). Extra finding: rides geometry columns carry **no column-level SRS_ID** (plain geometry); SRID 4326 lives only in per-row values from `ST_GeomFromText(...,4326)` — relevant to how RV-25's fix must be written. |
| V3 | **FAIL — worse than predicted** | Raw probe: MySQL 8.2 raises **3618 "st_buffer(LINESTRING) has not been implemented"** for every variant of strategy B (point on/off the line, SRID 0 or 4326). Strategy B never "quietly misses" — it **throws**: any ride with `route_geometry` makes `/api/rides/search` 500. (AF-4-era tests didn't catch it because their fixtures never set `route_geometry`.) For SRID-0 POINT buffers 0.05 = 0.05 **degrees** (area probe), so the doc's "≈5 km" was the intended meaning the engine can't deliver. |
| V4 | **CONFIRMED (collapse) / REFUTED (spoof)** | With `TrustProxies::$proxies = null`: `Request::create` with `REMOTE_ADDR=nginx` + `XFF=203.0.113.9,...` → `ip()` = **nginx container IP** (all clients share one `ip:` bucket — rate-limit collapse real); clients cannot forge the key (spoofing half not exploitable). Docs-gate allowlist never matches. |
| V5 | **CONFIRMED** | `POST /api/wallet/request-charge {amount:0}` → 422 with `{"status":"error","message":"validation failed (…)" , "code":422}` — **no `errors` bag**; clients can't show field-level failures (RV-13 half 1). |
| V6 | **CONFIRMED** | `git show HEAD:phpunit.xml` = `sqlite` / `:memory:` (the worktree's mysql edit is uncommitted and was never committed by choice); CI therefore runs sqlite while workflows provision MySQL. All money/geo/noshow suites that self-skip on non-mysql are **silently skipped in CI today**. |
| V7 | **NOT RUN** | No replica in this environment (scratch single instance). Requires `SHOW REPLICA STATUS` on the real cluster; nothing in RV-28 is blocked by it, but the lag number must come from staging before infra claims are made. |
| V8 | **CONFIRMED** | `config/system_admin.php` does **not exist**; `config('system_admin.phone')` referenced in `AdminReportService.php:78` (revenue always 0) and `VerificationRepository.php:69` (null email in driver-seed path). Correct sibling key is `admin.system_admin.phone`. |
| V9 | **CONFIRMED** | `user_ratings` retains `unique(rater_id, rated_user_id)` — per-ride ratings (RV-30) require extending that index with `ride_id`; `profile_comments` has PRIMARY only. |
| V10 | **CONFIRMED** | `POST /api/rides` routes to `RideController::createRide` which does not exist (`create()` does) → 500 on every call, validated `CreateRideRequest` unreachable. `php artisan route:cache` **succeeds** despite the closure routes (`/up`), so the deploy-path claim needs only the method fix, not closure removal. |

**Net effect on the plan:** §7's wave order stands, with two amendments. (1) RV-25's fix
must address the **ST_Buffer-LINESTRING 500** first (search crashes today) — the
`ST_Distance(route_line, point)` rewrite the review proposes also repairs this, and
`route_geometry`-bearing rides in the demo/seed data make it reachable in production;
(V1) means axis handling must be decided with it (`axis-order=long-lat` vs rewriting all
call sites). (2) RV-18's "skipped must be 0" is load-bearing — with V6, CI currently
exercises *none* of the money/geo suites. The Review folder tests will flip from
recording to asserting fixed truth as each RV task lands; each such edit is that task's
verification, with the RV id in the diff.

