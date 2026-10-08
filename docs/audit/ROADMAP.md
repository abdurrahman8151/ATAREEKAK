# SyRide - ROADMAP (features and direction, moved from APP_FUTURE_AUDIT.md sections E-G)

Moved verbatim from `docs/audit/APP_FUTURE_AUDIT.md` sections **E, F and G** on 2026-10-03.
This file is the live feature plan and carries **no status**: for what is done, open, or next,
read `docs/audit/BACKLOG.md` (the task table) and `docs/audit/STATE.md` (what is next). Where a
feature here conflicts with a finding in the audit files, the audit files win - these are
directions, not decisions. `APP_FUTURE_AUDIT.md` still contains one forward reference to this
content - its section J snapshot row for "Geospatial (GPS/tracking), payments, trust features"
reads "open product decisions (sec E)"; that pointer resolves here, and section E1 is the GPS
chapter it means.

---

## E. WHAT A RIDE-SHARING SYSTEM NEEDS THAT THIS ONE LACKS

### E1. Geospatial / GPS — the named gap, and the biggest one (answers "like gps and so on")
What exists: creation-time search (endpoint radius), static route polyline chosen at
creation, geometry columns + spatial indexes. What **doesn't exist, verified**:

| Missing | Evidence | Product impact |
| --- | --- | --- |
| **No driver position at all** | 0 tables (`driver_locations` etc. absent from 29-table census); no route accepts a position update (`routes/api.php` GPS grep: 0 hits); `users` has no `is_online/last_seen`/availability column | You cannot show, match, or dispatch against where drivers are |
| **No live tracking (driver↔passenger)** | broadcast channels are only `conversation.{id}`, `user.{id}`, `rides` (`routes/channels.php:12-57`) — no per-ride channel | The single feature users most expect from "Uber-like" |
| **No ETA** | the only ETA arithmetic is an admin *display* calc (`AdminTripService:275-304`), not recomputed en route | no arrival promise, no dispatch ordering, no "driver is 4 min away" |
| **No nearest-driver matching** | search orders by `departure_time` (`RideSearchService:52`, `RideRepository` likewise); no Haversine ranking vs a passenger point | matching is a time table, not a geo system |
| **Straight-line fare fallback** | `RouteCalculationService:172-191`: fallback = straight line **+30% fudge**; one hardcoded provider (hard-throw if ORS key absent `:28-32`); `verify=false` on its HTTP | price accuracy and availability risk on the one external map provider |
| **No trip recording/replay, no location privacy** | nothing stores traversed paths; pickup/destination exposed verbatim to any authenticated searcher (`RideResource:76`, `BookingResource:28`) | |
| **No ride lifecycle events at timestamps** | only "first passenger confirm flips ride to launched" (`BookingService:505-507`); **no cron moves active→launched or launched→completed** (Kernel census: 4 jobs, none lifecycle) | rides can sit "active" forever with nobody confirming |

Minimal credible path (this is what a defense would score): (1) `driver_positions` table +
`POST /api/rides/{id}/position` (throttled `uploads`) + Redis GEO `GEOADD` on the same
write; (2) private `ride.{id}` Echo channel broadcasting positions; (3) nearest-driver
`search` as an add-on route using the GEO set; (4) a `rides:advance-status` scheduled job.
Each is a day; together they change what the product *is*.

### E2. Money & payments
- **No payment gateway** — `stripe-php` unused (§D4); funds enter only via
  admin-approved `wallet_requests` (a human button). README:12 claims "multi-gateway
  wallet system" — that claim is currently false in code.
- No webhooks, no payouts, no chargebacks, no reconciliation, **no fare engine**
  (`price_per_seat` is whatever the driver types; 0 sites for surge/per-km rates).
- `rides.price_per_seat` is the only money column **not** standardised to (15,2) — it is
  (8,2) (verified live). Intentional? Record it or fix it.

### E3. Trust & safety (a ride-sharing product's second heart)
0 hits for SOS / emergency contacts / share-my-trip / pickup-PIN across `app/`. KYC has
**no document-expiry column anywhere**; no criminal-record/background check; rating is
user-level only (**no per-ride rating** — the `user_ratings` table has unique(rater,rated)
but nothing ties a rating to a completed ride); `rater_id` nullable since
`2026_08_09_184511` means seed-fabricated ratings are indistinguishable from real ones in
`avg('rating')`; the admin "Suspended" driver bucket is a hardcoded `= 0` with a
"not implemented" comment (`AdminDriverService:33,90`); no fraud/velocity checks beyond
rate limiting; no cancellation-reason column anywhere.

### E4. Operations & engagement
No ride reminders (the dead `SendScheduledNotification` was exactly this feature, §D4);
no driver online/availability; complaints have no user-side withdraw/outcome/SLA; the
"OTP channels" are CallMeBot/TextMeBot WhatsApp (free-tier hobby gateways, no real SMS
provider at all) and both logging paths contain testing-mode dummy-OTP bypasses
(`WhatsAppOtpService:57-61,175`, `EmailOtpService:53`); admin password rotation is
**commented out** in the scheduler (`Kernel:64-68`).

### E5. For grading specifically
- README claims (production-grade / real-time / multi-gateway) diverge from code — a
  defense examiner greps the claims.
- **No architecture doc, no ERD, no ADRs** (`docs/` contains only the bug audit; README
  :94's "layered, domain-oriented architecture" is the whole written architecture).
- l5-swagger **is** live and gated (T3-12) but its 132 documented paths come from ~11
  annotated endpoints via hand-written `app/Docs/*Docs.php` stub classes — admin/staff
  surfaces are undocumented in the spec.
- The 133 test files with per-finding causality suites *are* the strength story — package
  them: `php artisan test --filter=T1` is literally a scripted demo of the audit trail.

---

## F. WHAT TO ADD, PRIORITIZED (feature & library decisions)

**P0 — corrects false claims in the existing architecture (each is small)**
1. Shared object storage: `FILESYSTEM_DISK=s3` (MinIO container in dev, real bucket in
   prod) — closes A3.1, the actual scaling break.
2. Uncomment `FlushUploadedFiles` in `config/octane.php` (octane.php:84).
3. Remove all `verify => false` (3 sites) — the repo already ships a CA trust story
   (T3 batch deleted the committed cacert; don't re-add its absence as an excuse).
4. Wire-or-delete the dead set (§D4): `RideSearchService` decision, `stripe-php` (remove
   or integrate), 3 dead events, test-mode no-show gates → config-driven hours.
5. CI: Pint + Larastan (start level 4→5), a required-tests workflow.
6. Delete or hide `Test*` debug commands from the prod image.

**P1 — the domain becomes what it claims**
7. `Money` VO across the mutation layer (7 services, 66 sites — the T3-2/T1-2 money
   findings make this the highest-value correctness work left).
8. Single `LedgerEvent` enum (kills D2's two dialects), `ledger:reconcile` job.
9. Real payment: **pick one** — integrate Stripe properly (webhook route,
   `payment_intents`, wallet top-up only) or delete `stripe-php` and document "manual
   top-up" honestly. A university deployment can get a Stripe test-mode live.

**P2 — what makes it a ride-sharing app (the GPS chapter, E1)**
10. `driver_positions` + `POST position` (throttle `uploads`) + Redis GEO + `ride.{id}`
    presence/private channel + nearest-driver search endpoint.
11. Fare engine: `pricing_rules` table (base + per-km), `RouteCalculationService` polyline
    distance, quote endpoint at ride creation.
12. `rides:advance-status` scheduler (active→launched→completed by time + confirmations);
    cancellation-reason column; per-ride `ride_ratings` table.
13. Real push (the `FcmSenderService` + firebase config exist — needs production keys +
    the `push_notification_tokens` surface is already there).

**P3 — trust & polish**
14. SOS/trip-share (even "call police 911" deep-link + share-link via signed URL),
    document-expiry dates in KYC, driver availability flag, user-side complaint withdraw.
15. `docs/ARCHITECTURE.md` + generated ERD + 3 ADRs (monolith-not-micro, MySQL-spatial vs
    PostGIS, wallet-as-escrow) — grading, cheap, and it forces the A1 reasoning to be
    written down.
16. A `driver app` + `passenger app` — currently the product has **149 endpoints and 7
    views**; everything in this section is invisible to an examiner without at least a
    map screen consuming the new position channel. (Even a 200-line Inertia/React page.)

Libraries, honestly: Echo/pusher-js/laravel-echo already in `package.json`, Horizon/redis
already deployed — **the stack is not missing libraries; it is missing the wiring of the
ones it has.** Resist adding: microservices, Kafka, Kubernetes, a separate search cluster
— each would be a README feature and an operational tax.

---

## G. THE FRONT-END TRAP (P1 for claims, because "real-time" is currently fiction)

`resources/js` is 755 bytes and **broken by construction**: `bootstrap.js:9-10` reads
`process.env.MIX_PUSHER_APP_KEY` — Laravel-**Mix** syntax in a **Vite** project (vite does
not polyfill `process.env`; the value is literally `undefined` at runtime), and the only
listener (`:16` `window.Echo.private(user.${window.userId})`) is gated on `window.userId`,
which nothing in the 7 Blade views ever sets. Meanwhile server-side broadcasting is real
(`RideCreated/RideCancelled` ShouldBroadcast, Horizon + redis queue correct).
So: the notification/real-time **server** half is production-shaped, the **client** half
is a 30-line file that has never worked. Either ship a real client (Inertia+React with
Echo + Vite `import.meta.env.VITE_PUSHER_KEY`) or stop describing the product as real-time.
