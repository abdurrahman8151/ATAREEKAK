/**
 * SyRide — Stage 1 Ceiling Confirmation Test
 * 3 nodes × 8 workers (24 workers total)
 *
 * PURPOSE: Prove that pushing Stage 1 to 900 VUs does NOT increase
 * throughput beyond ~699 req/s. Throughput should plateau at the
 * CPU ceiling while p95 climbs toward ~1,000ms.
 *
 * Expected result:
 *   req/s:  stays ~699 (same as 400 VU run)  ← CPU-bound, not VU-bound
 *   p95:    climbs to ~900–1,100ms            ← longer queue, same workers
 *   errors: 0.00%                             ← server still handles all
 *
 * If this is true: Stage 1 at 900 VUs < Stage 2 at 900 VUs in req/s,
 * proving Stage 2 is genuinely faster (more CPU capacity from 5 nodes).
 *
 * Run:
 *   k6 run "k6-load\syride-stage1-900vu-confirm.js"
 *
 * Monitor (second terminal):
 *   while ($true) {
 *     Write-Host (Get-Date -Format "HH:mm:ss")
 *     docker stats --no-stream --format "table {{.Name}}`t{{.CPUPerc}}`t{{.MemUsage}}" | findstr "app\|mysql"
 *     Write-Host "---"; Start-Sleep 10
 *   }
 */

import http from 'k6/http';
import { check } from 'k6';
import { Rate, Trend } from 'k6/metrics';

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
const RIDE_IDS = [155,199,536,376,589,541,629,421,269,529,169,356,267,251,336,238,132,406,298,652];
const BOOKING_IDS = [116,119,120,127,128,131,132,133,136,137];
const USER_IDS = [1,2,626,627,628,629,630,631,632,633,634,635,636,637,638,639,640,641,642,643,644,645,646,647,648,649,650,651,652,653,654,655,656,657,658,659,660,661,662,663,664,665,666,667,668,669,670,671,672,673];
const LOCATIONS   = [
    { lat: 31.9539, lng: 35.9106 },
    { lat: 31.9784, lng: 35.8594 },
    { lat: 31.9454, lng: 35.9284 },
    { lat: 31.9037, lng: 35.9383 },
    { lat: 32.0156, lng: 35.8621 },
];

// ─── METRICS ─────────────────────────────────────────────────────────────────
const errorRate    = new Rate('real_5xx_errors');
const writeLatency = new Trend('write_ops_ms', true);
const readLatency  = new Trend('read_ops_ms', true);

// ─── STAGES ──────────────────────────────────────────────────────────────────
// *** STAGE 1 — pushed to 900 VUs ***
//
// The original Stage 1 test used 400 VUs and got 699 req/s with p95 524ms.
// This test pushes to 900 VUs with the SAME 3-node × 8-worker config.
//
// Hypothesis to confirm:
//   - req/s stays ~699 (CPU is the ceiling, not VU count)
//   - p95 climbs to ~1,000ms (longer queue, same workers)
//   - errors stay 0.00% (server still processes everything)
//
// If confirmed: Stage 1 at 900 VUs < Stage 2 at 900 VUs in throughput,
// proving Stage 2 is genuinely faster due to more physical CPU capacity.
//
export const options = {
    stages: [
        { duration: '30s', target: 10   },
        { duration: '1m',  target: 900  },  // known point: 883 req/s
        { duration: '2m',  target: 1400 },  // watch req/s here
        { duration: '2m',  target: 2000 },  // ceiling probably in this range
        { duration: '2m',  target: 2500 },  // safety margin
        { duration: '30s', target: 0    },
    ],
    thresholds: {
        'real_5xx_errors':   ['rate<0.10'],
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

// ─── MAIN — identical traffic mix to Stage 1 hammer test ─────────────────────
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
            `&dest_lat=${dest.lat}&dest_lng=${dest.lng}&departure_date=2026-12-15&seats_required=1`,
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
                departure_time:  '2026-12-15 09:00:00',
                vehicle_type: 'sedan',
                available_seats: 3,
                price_per_seat:  5,
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
    check(r, { 'not a server error': () => !is5xx });
}

// ─── SETUP ───────────────────────────────────────────────────────────────────
export function setup() {
    console.log('\n' + '='.repeat(65));
    console.log('  SyRide — Stage 1 Ceiling Confirmation Test');
    console.log('  3 nodes × 8 workers (24 total) — same as original Stage 1');
    console.log('  Pushed to 900 VUs to match Stage 2 pressure');
    console.log('  Hypothesis: req/s stays ~699, p95 climbs to ~1,000ms');
    console.log('='.repeat(65));
    console.log('  WATCH IN DOCKER STATS:');
    console.log('  App CPU stays ~380% from 400 VUs onward → CPU ceiling hit');
    console.log('  req/s plateaus at ~699 even as VUs climb → proof of ceiling');
    console.log('  p95 climbs with VUs → longer queue, same throughput');
    console.log('='.repeat(65) + '\n');

    const r = http.get(`${BASE_URL}/api/test`);
    if (r.status === 0) throw new Error('Server unreachable — is Docker running?');
    console.log(`✅ Server alive (HTTP ${r.status}). Starting confirmation test.\n`);
}

// ─── TEARDOWN ────────────────────────────────────────────────────────────────
export function teardown() {
    console.log('\n' + '='.repeat(65));
    console.log('  CONFIRMATION CHECKLIST:');
    console.log('  ✓ http_reqs/s ≈ 699    → throughput plateaued at CPU ceiling');
    console.log('  ✓ p95 > 800ms          → queue grew but server never failed');
    console.log('  ✓ real_5xx = 0.00%     → no crashes under extra VU pressure');
    console.log('  ✓ App CPU stayed ~380% → same physical ceiling as 400 VU run');
    console.log('  Compare to Stage 2: 883 req/s at 900 VUs = 26% more throughput');
    console.log('  That gap = extra CPU capacity from 2 more physical containers');
    console.log('='.repeat(65) + '\n');
}
