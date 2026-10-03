# k6 load tests — token contract (RV-07)

The scripts in this folder used to embed ~500 **hard-coded bearer tokens**. They
were committed, therefore readable by anyone with repository access, and they
expired silently (the ones in git had been dead since 2026-08-14 while the load
tests kept "passing" — see RV-17: the search slice was 422-ing the whole time).

**RV-07 removed every committed token.** The six scripts that used them now read
them from the environment and fail fast if they are missing:

| Variable | Meaning |
|---|---|
| `K6_PASSENGER_TOKENS` | comma-separated passenger access tokens |
| `K6_DRIVER_TOKENS` | comma-separated driver access tokens |
| `BASE_URL` | target (default `http://localhost:8080`) |
| `K6_ADMIN_EMAIL` / `K6_ADMIN_PASSWORD` | seed-credential contract used by the Scenario scripts |

```bash
k6 run syride-spike-only.js \
  -e K6_PASSENGER_TOKENS="$P1,$P2,$P3" \
  -e K6_DRIVER_TOKENS="$D1,$D2" \
  -e BASE_URL="https://staging.example"
```

Without those two variables the script throws before the first VU starts, so a
missing token can never masquerade as a green load test again.

## Minting tokens without putting them in the shell history

## The harness (decision un12) — use this instead of pasting tokens

`harness.js` + `perf-3run.js` remove the manual step above entirely. `setup()` **logs in through
the real API** and hands every VU a token that is fresh for the length of the run.

Why this matters: a token pasted via `-e` is a **snapshot**. It rots silently, and a load test
against an expired token measures the 401 path — which is exactly the failure this folder was filed
for ("the search slice was 422-ing the whole time"). Tokens minted in `setup()` cannot expire
mid-run, and they never touch disk.

```bash
k6 run --summary-export perf-results/run-$(date +%F).json k6-load/perf-3run.js \
  -e BASE_URL=https://your-host \
  -e K6_PEOPLE="a@b.com:pw,c@d.com:pw" \
  -e K6_RATE=50 -e K6_RUN_SECONDS=60
```

| Variable | Meaning |
|---|---|
| `K6_PEOPLE` | `email:password,email:password` — the accounts to drive load with (**required**) |
| `BASE_URL` | target (default `http://localhost:8080`) |
| `K6_RATE` | arrivals/second for runs 1-2 (default 50); run 3 uses 1.5× |
| `K6_RUN_SECONDS` | seconds per run (default 60) |
| `K6_RIDE_IDS` | optional `id,id` so ride-detail reads join the search reads |
| `K6_OUT_DIR` | where the JSON summary is written (default `perf-results`) |

**Do not rename `K6_RUN_SECONDS` to `K6_DURATION`.** Measured on k6 v2.0.0: `K6_DURATION` collides
with a built-in config key, k6 logs `env level configuration overrode scenarios configuration
entirely`, silently builds its own default scenario, and the only visible error is an unrelated
`the duration must be at least 1s, but is 1ms`. You end up measuring a different load than the file
describes. `K6_RATE` and `BASE_URL` are unaffected.

### Why three runs

They are not three identical runs — identical back-to-back runs mostly measure cache warmth from
the previous one. They escalate: **run 1 baseline → run 2 same rate → run 3 at 1.5×**. A regression
shows up as *run 2 worse than run 1*, which means a queue, a lock or a leak rather than raw CPU.
Read the teardown banner before quoting any of it.

### Two thresholds that are warnings in disguise

- `syride_auth_rejections == 0` — non-zero means tokens went stale mid-run, i.e. the harness
  regressed to the RV-17 failure mode.
- `dropped_iterations == 0` — a low p95 with dropped iterations means the VU pool ran out; that is
  not a passing result, it is a smaller load than you think it was.

## Minting tokens by hand (only if you must)

Log in through the real API and capture the token:

```bash
curl -s -X POST "$BASE_URL/api/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"<seeded passenger email>","password":"<seeded password>"}' \
  | jq -r '.tokens.access_token'
```

For a fleet of users, use the app's own loader
(`php artisan loadtest:tokens --count=N --export=table`); it is guarded against
running in production (AF-4f) and reads the seeded credentials from the
environment.

## Do not

Do not commit a token, a password, or an API key into this folder — even "just
for the load test". `.github/workflows/gitleaks.yml` fails the build on any
commit that does (RV-07).
