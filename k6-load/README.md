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
