/**
 * SyRide — Full-API Hammer Test
 * 13 endpoints · Zero sleep · Absolute ceiling finder
 *
 * Tokens : 50 passenger (users 251-300) + 16 driver (users 1-16)
 * Rides  : 20 active IDs
 * Bookings: 10 pending IDs (first accept wins; 404 after = expected)
 *
 * Run:
 *   k6 run "k6-load\syride-full-hammer-test.js"
 *
 * Monitor in a second terminal:
 *   while ($true) {
 *     Write-Host (Get-Date -Format "HH:mm:ss")
 *     docker stats --no-stream --format "table {{.Name}}`t{{.CPUPerc}}`t{{.MemUsage}}" | findstr "app\|mysql"
 *     Write-Host "---"; Start-Sleep 10
 *   }
 *
 * Breaking point signals:
 *   MySQL CPU > 80%  → database is bottleneck
 *   App CPU  > 80%  → PHP workers are bottleneck
 *   p95      > 500ms → degradation zone begins
 *   real_5xx > 0%   → server genuinely failing
 */

import http from 'k6/http';
import { check } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// RV-17: only 2xx counts as an expected response. Previously 4xx (incl. 422) was
// listed as expected, so requests that never reached business logic — because the
// load script violated the API contract — were recorded as SUCCESSFUL requests and
// still fed http_req_duration{expected_response:true}. That made the published rps
// numbers measure validation rejection, not the application.
http.setResponseCallback(http.expectedStatuses(
    { min: 200, max: 299 }
));

const BASE_URL = 'http://localhost:8080';

// RV-07 (security): bearer tokens are no longer committed. Mint them
// out-of-band and pass them in:
//   k6 run script.js -e K6_PASSENGER_TOKENS=t1,t2 -e K6_DRIVER_TOKENS=d1,d2
const _tokensFromEnv = (name) => (__ENV[name] || '').split(',').map((t) => t.trim()).filter(Boolean);
const PASSENGER_TOKENS = _tokensFromEnv('K6_PASSENGER_TOKENS');

const DRIVER_TOKENS = _tokensFromEnv('K6_DRIVER_TOKENS');
if (PASSENGER_TOKENS.length === 0 || DRIVER_TOKENS.length === 0) {
  throw new Error('K6_PASSENGER_TOKENS and K6_DRIVER_TOKENS are required (see k6-load/README.md). Committed tokens were removed by RV-07.');
}


// ─── DATA ────────────────────────────────────────────────────────────────────
const RIDE_IDS     = [764, 20423, 17020, 29270, 34028, 33270, 37965, 38201, 41049, 49316, 69132, 73016, 69392, 75041, 93396, 107954, 107908, 115258, 130153, 150173];
const BOOKING_IDS  = [3901, 8574, 9044, 9178, 11182, 13253, 18003, 19065, 20689, 21267];
const USER_IDS     = [251,252,253,254,255,256,257,258,259,260,261,262,263,264,265,266,267,268,269,270,271,272,273,274,275,276,277,278,279,280,281,282,283,284,285,286,287,288,289,290,291,292,293,294,295,296,297,298,299,300];
const LOCATIONS    = [
    { lat: 31.9539, lng: 35.9106 },
    { lat: 31.9784, lng: 35.8594 },
    { lat: 31.9454, lng: 35.9284 },
    { lat: 31.9037, lng: 35.9383 },
    { lat: 32.0156, lng: 35.8621 },
];

// ─── METRICS ─────────────────────────────────────────────────────────────────
const errorRate    = new Rate('real_5xx_errors');
const clientErrors  = new Rate('real_4xx_errors');
const writeLatency = new Trend('write_ops_ms', true);
const readLatency  = new Trend('read_ops_ms', true);

// ─── STAGES ──────────────────────────────────────────────────────────────────
// *** STAGE 2 VERSION — 5 nodes × 16 workers = 80 workers total ***
//
// Stage 1 (24 workers) saturated at 400 VUs.
// Stage 2 (80 workers) needs ~900 VUs to reach equivalent saturation.
//
// Ramp curve mirrors the Stage 1 test shape but scaled 2.25×:
//   30s  warmup at 10     — prime caches, settle connections
//   1m   ramp  to 50      — light baseline, confirm all 5 nodes serving
//   1m   ramp  to 200     — where Stage 1 was comfortable
//   2m   ramp  to 400     — Stage 1 saturation point (Stage 2 has headroom here)
//   2m   ramp  to 650     — Stage 2 working hard, watch CPU climb
//   2m   hold  at 900     — Stage 2 saturation target: look for p95 > 500ms
//   30s  ramp  to 0       — cool down
//
// Saturation signals (watch docker stats in second terminal):
//   app CPU  > 380%  → PHP workers fully engaged (= true ceiling)
//   p95      > 500ms → degradation zone — note the VU count here
//   real_5xx > 0%    → hard failure — record req/s immediately before this
//
export const options = {
    stages: [
        { duration: '30s', target: 10  },  // warm up
        { duration: '1m',  target: 100 },  // baseline
        { duration: '1m',  target: 200 },  // climbing
        { duration: '2m',  target: 400 },  // Stage 1 saturation zone
        { duration: '2m',  target: 500 },  // push past ceiling to confirm it
        { duration: '30s', target: 0   },  // cool down
    ],
    thresholds: {
        'real_5xx_errors':   ['rate<0.10'],
        // RV-17: a run whose requests are mostly rejected by validation is NOT a
        // capacity measurement — fail it instead of reporting rps.
        'real_4xx_errors':   ['rate<0.05'],
        'http_req_duration': ['p(95)<60000'],
        'write_ops_ms':      ['p(95)<60000'],
    },
};

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

function auth(token) {
    return {
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type':  'application/json',
            'Accept':        'application/json',
        },
    };
}

// ─── MAIN ────────────────────────────────────────────────────────────────────
export default function () {
    const roll   = Math.random() * 100;
    const pToken = pick(PASSENGER_TOKENS);
    const dToken = pick(DRIVER_TOKENS);
    const rideId = pick(RIDE_IDS);
    const bookId = pick(BOOKING_IDS);
    const userId = pick(USER_IDS);
    const origin = pick(LOCATIONS);
    const dest   = pick(LOCATIONS);
    const jitter = (Math.random() - 0.5) * 0.02;

    let t0, r;

    if (roll < 20) {
        t0 = Date.now();
        r = http.get(
            `${BASE_URL}/api/rides/search` +
            `?source_lat=${(origin.lat + jitter).toFixed(6)}` +
            `&source_lng=${(origin.lng + jitter).toFixed(6)}` +
            `&dest_lat=${dest.lat}&dest_lng=${dest.lng}` +
            `&departure_date=2026-12-15&seats_required=1`,
            auth(pToken)
        );
        readLatency.add(Date.now() - t0);

    } else if (roll < 30) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/rides?page=${Math.ceil(Math.random() * 5)}`, auth(pToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 45) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/rides/${rideId}`, auth(pToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 55) {
        t0 = Date.now();
        r = http.post(
            `${BASE_URL}/api/rides/${rideId}/book`,
            JSON.stringify({ seats: 1, communication_number: '0912345678' }),
            auth(pToken)
        );
        writeLatency.add(Date.now() - t0);

    } else if (roll < 63) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/notifications/unread-count`, auth(pToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 68) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/wallet/balance`, auth(pToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 72) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/score`, auth(pToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 75) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/profile/${userId}`, auth(pToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 85) {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/bookings`, auth(dToken));
        readLatency.add(Date.now() - t0);

    } else if (roll < 92) {
        t0 = Date.now();
        r = http.post(
            `${BASE_URL}/api/bookings/${bookId}/accept`,
            JSON.stringify({}),
            auth(dToken)
        );
        writeLatency.add(Date.now() - t0);

    } else if (roll < 97) {
        t0 = Date.now();
        r = http.post(
            `${BASE_URL}/api/rides/create-with-route`,
            JSON.stringify({
                pickup_lat: origin.lat, pickup_lng: origin.lng,
                destination_lat: dest.lat, destination_lng: dest.lng,
                departure_time: '2026-12-15 09:00:00',
                vehicle_type: 'sedan',
                available_seats: 3,
                price_per_seat: 5,
                payment_method: 'cash',
                booking_type: 'direct',
                communication_number: '0912345678',
            }),
            auth(dToken)
        );
        writeLatency.add(Date.now() - t0);

    } else if (roll < 99) {
        const phone = `+9629${(Math.floor(Math.random() * 90000000) + 10000000)}`;
        t0 = Date.now();
        r = http.post(
            `${BASE_URL}/api/otp/send`,
            JSON.stringify({ phone_number: phone }),
            { headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' } }
        );
        writeLatency.add(Date.now() - t0);

    } else {
        t0 = Date.now();
        r = http.get(`${BASE_URL}/api/user`, auth(pToken));
        readLatency.add(Date.now() - t0);
    }

    const is5xx = r.status >= 500 || r.status === 0;
    errorRate.add(is5xx ? 1 : 0);
    // RV-17: 4xx used to be invisible here (and counted as an expected response),
    // so an expired token or a contract-breaking payload produced a green run.
    // R1 §RV-17: "2xx rate >= 95% per named endpoint" — i.e. 4xx must stay under 5%.
    const is4xx = r.status >= 400 && r.status < 500;
    clientErrors.add(is4xx ? 1 : 0);
    check(r, { 'not a server error': () => !is5xx });
}

// ─── SETUP ───────────────────────────────────────────────────────────────────
export function setup() {
    console.log('\n' + '='.repeat(65));
    console.log('  SyRide FULL-API HAMMER TEST — STAGE 1 (3 nodes · 24 workers)');
    console.log('  13 endpoints · Zero sleep · Absolute ceiling finder');
    console.log('  50 passenger tokens (users 251-300)');
    console.log('  16 driver tokens    (users 1-16)');
    console.log('  75% passenger | 22% driver | 3% auth');
    console.log('  Writes: book(10%) + accept(7%) + create-ride(5%) = 22%');
    console.log('  Cache defeated: search(20%) uses coordinate jitter');
    console.log('  Peak target: 900 VUs — saturation expected ~650-900 VUs');
    console.log('='.repeat(65));
    console.log('  WATCH FOR IN DOCKER STATS:');
    console.log('  MySQL CPU > 80%  → database is bottleneck');
    console.log('  App CPU  > 380% → PHP workers fully saturated');
    console.log('  p95      > 500ms → degradation zone begins — note VU count');
    console.log('  real_5xx > 0%   → hard limit reached — note req/s now');
    console.log('='.repeat(65) + '\n');

    const r = http.get(`${BASE_URL}/api/test`);
    if (r.status === 0) throw new Error('Server unreachable — is Docker running?');
    console.log(`✅ Server alive (HTTP ${r.status}). Hammer test starting.\n`);
}

// ─── TEARDOWN ────────────────────────────────────────────────────────────────
export function teardown() {
    console.log('\n' + '='.repeat(65));
    console.log('  WHAT TO REPORT:');
    console.log('  1. Peak req/s where p95 < 500ms   = sustainable ceiling');
    console.log('  2. VU count at first 5xx errors    = hard limit');
    console.log('  3. Which container peaked highest  = bottleneck component');
    console.log('  4. write_ops_ms p95 vs read_ops_ms = relative endpoint cost');
    console.log('  5. Ceiling req/s × 15s think time  = real concurrent users');
    console.log('='.repeat(65) + '\n');
}
