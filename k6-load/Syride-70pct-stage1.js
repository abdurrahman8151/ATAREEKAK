/**
 * SyRide — Stage 2 · 70% Operating Point Test
 * Constant-arrival-rate: exactly 618 req/s for 3 minutes
 *
 * WHY THIS TEST:
 *   The hammer test measures p95 at 100% saturation (900 VUs, 883 req/s).
 *   This test measures p95 at exactly 70% capacity (618 req/s) — the
 *   comfortable operating target. That p95 is the "system at 9,300 users"
 *   number that goes into the report.
 *
 * CONFIG:
 *   Stage 2 — 5 nodes × 16 workers = 80 total PHP workers
 *   Target: 618 req/s = 883 × 0.70
 *   Plateau: 3 minutes (p95 from this window = the reportable number)
 *
 * HOW TO RUN:
 *   k6 run "k6-load\syride-70pct-stage2.js"
 *
 * FOR STAGE 1 (3 nodes · 8 workers):
 *   Change rate: 618 → 489   (699 × 0.70)
 *   Change the label in setup() accordingly
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


// ─── DATA ─────────────────────────────────────────────────────────────────────
const RIDE_IDS    = [764, 20423, 17020, 29270, 34028, 33270, 37965, 38201, 41049, 49316, 69132, 73016, 69392, 75041, 93396, 107954, 107908, 115258, 130153, 150173];
const BOOKING_IDS = [3901, 8574, 9044, 9178, 11182, 13253, 18003, 19065, 20689, 21267];
const USER_IDS    = [251,252,253,254,255,256,257,258,259,260,261,262,263,264,265,266,267,268,269,270,271,272,273,274,275,276,277,278,279,280,281,282,283,284,285,286,287,288,289,290,291,292,293,294,295,296,297,298,299,300];
const LOCATIONS   = [
    { lat: 31.9539, lng: 35.9106 },
    { lat: 31.9784, lng: 35.8594 },
    { lat: 31.9454, lng: 35.9284 },
    { lat: 31.9037, lng: 35.9383 },
    { lat: 32.0156, lng: 35.8621 },
];

// ─── METRICS ──────────────────────────────────────────────────────────────────
const errorRate    = new Rate('real_5xx_errors');
const writeLatency = new Trend('write_ops_ms', true);
const readLatency  = new Trend('read_ops_ms', true);

// ─── SCENARIOS ────────────────────────────────────────────────────────────────
// Two scenarios in sequence:
//   1. warmup  — 30s at 100 req/s  (primes Redis, stabilises workers)
//   2. plateau — 3m at 618 req/s   (the measurement window — p95 from here = 9,300 users)
//
// TO USE FOR STAGE 1 (3 nodes · 8 workers):
//   Change plateau rate: 618 → 489   (699 × 0.70)
//   Adjust the label in setup()

export const options = {
    scenarios: {
        warmup: {
            executor: 'constant-arrival-rate',
            rate: 100,
            timeUnit: '1s',
            duration: '30s',
            preAllocatedVUs: 60,
            maxVUs: 120,
            startTime: '0s',
        },
        plateau: {
            executor: 'constant-arrival-rate',
            rate: 643,                   // ← Stage 1: 699 × 0.70 = 489
            timeUnit: '1s',
            duration: '3m',
            preAllocatedVUs: 400,
            maxVUs: 1200,
            startTime: '30s',
        },
    },
    thresholds: {
        'http_req_duration': ['p(95)<60000'],
        'real_5xx_errors':   ['rate<0.05'],
    },
};

// ─── HELPERS ──────────────────────────────────────────────────────────────────
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

// ─── MAIN — same 13-API mix as hammer test ────────────────────────────────────
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
        r = http.get(`${BASE_URL}/api/rides/search?source_lat=${(origin.lat + jitter).toFixed(6)}&source_lng=${(origin.lng + jitter).toFixed(6)}&dest_lat=${dest.lat}&dest_lng=${dest.lng}&departure_date=2026-12-15&seats_required=1`, auth(pToken));
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
        r = http.post(`${BASE_URL}/api/rides/${rideId}/book`, JSON.stringify({ seats: 1, communication_number: '0912345678' }), auth(pToken));
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
        r = http.post(`${BASE_URL}/api/bookings/${bookId}/accept`, JSON.stringify({}), auth(dToken));
        writeLatency.add(Date.now() - t0);
    } else if (roll < 97) {
        t0 = Date.now();
        r = http.post(`${BASE_URL}/api/rides/create-with-route`, JSON.stringify({ pickup_lat: origin.lat, pickup_lng: origin.lng, destination_lat: dest.lat, destination_lng: dest.lng, departure_time: '2026-12-15 09:00:00', vehicle_type: 'sedan', available_seats: 3, price_per_seat: 5, payment_method: 'cash', booking_type: 'direct', communication_number: '0912345678' }), auth(dToken));
        writeLatency.add(Date.now() - t0);
    } else if (roll < 99) {
        const phone = `+9629${(Math.floor(Math.random() * 90000000) + 10000000)}`;
        t0 = Date.now();
        r = http.post(`${BASE_URL}/api/otp/send`, JSON.stringify({ phone_number: phone }), { headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' } });
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

// ─── SETUP ────────────────────────────────────────────────────────────────────
export function setup() {
    console.log('\n' + '='.repeat(65));
    console.log('  SyRide — 70% Operating Point Test · Stage 1 (3nodes · 24 workers)');
    console.log('  Target: 643 req/s for 3 minutes = system at ~9,650 concurrent users');
    console.log('  Warmup: 30s at 100 req/s → then plateau at 618 req/s');
    console.log('  p95 from the final output = "system at 9,300 users" number');
    console.log('  NOTE: tokens expire 10h after generation — check for 401s');
    console.log('='.repeat(65) + '\n');
    const r = http.get(`${BASE_URL}/api/test`);
    if (r.status === 0) throw new Error('Server unreachable');
    if (r.status === 401) throw new Error('Tokens expired — regenerate with: php artisan loadtest:tokens --count=50');
    console.log(`✅ Server alive (HTTP ${r.status}). Starting warmup.\n`);
}

export function teardown() {
    console.log('\n' + '='.repeat(65));
    console.log('  READ FROM OUTPUT:');
    console.log('  http_req_duration p95  = p95 at 9,300 users (Stage 2 · 70%)');
    console.log('  write_ops_ms p95       = write latency at 9,300 users');
    console.log('  read_ops_ms p95        = read latency at 9,300 users');
    console.log('  real_5xx_errors rate   = should be 0.00%');
    console.log('  For Stage 1 (3 nodes): change rate to 489 in plateau scenario');
    console.log('='.repeat(65) + '\n');
}
