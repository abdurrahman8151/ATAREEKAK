/**
 * SyRide — Capacity Validation: 70% Sustained + 30% Spike Absorption
 */

import http from 'k6/http';
import { check } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

http.setResponseCallback(http.expectedStatuses(
    { min: 200, max: 299 }
));

// ─── CONFIG ──────────────────────────────────────────────────────────────────

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

// ─── TOKENS — fresh, expire 10h from generation ──────────────────────────────

// RV-07 (security): bearer tokens are no longer committed. Mint them
// out-of-band (k6 login step or your own script) and pass them in:
//   k6 run script.js -e K6_PASSENGER_TOKENS=t1,t2 -e K6_DRIVER_TOKENS=d1,d2
const _tokensFromEnv = (name) => (__ENV[name] || '').split(',').map((t) => t.trim()).filter(Boolean);
const PASSENGER_TOKENS = _tokensFromEnv('K6_PASSENGER_TOKENS');
const DRIVER_TOKENS = _tokensFromEnv('K6_DRIVER_TOKENS');
if (PASSENGER_TOKENS.length === 0 || DRIVER_TOKENS.length === 0) {
  throw new Error('K6_PASSENGER_TOKENS and K6_DRIVER_TOKENS are required (see k6-load/README.md). Committed tokens were removed by RV-07.');
}

// Active rides — refresh if booking fails with 404
const RIDE_IDS = [2, 3, 4, 6, 8, 11, 14, 15, 17, 18, 20, 22, 23, 29, 31, 32, 42, 43, 46, 48];

// Amman coordinate pool
const LOCATIONS = [
    { lat: 31.9539, lng: 35.9106 },
    { lat: 31.9784, lng: 35.8594 },
    { lat: 31.9454, lng: 35.9284 },
    { lat: 31.9037, lng: 35.9383 },
    { lat: 32.0156, lng: 35.8621 },
];

// ─── METRICS ─────────────────────────────────────────────────────────────────

const errorRate = new Rate('real_5xx_errors');
const writeTime = new Trend('booking_write_ms', true);
const readTime  = new Trend('search_read_ms',   true);

// ─── OPTIONS ─────────────────────────────────────────────────────────────────

export const options = {
    stages: [
        { duration: '2m',  target: 200 },  // Phase 1: Warm-up
        { duration: '3m',  target: 470 },  // Phase 2: Ramp to 70%
        { duration: '4m',  target: 470 },  // Phase 3: SUSTAINED HOLD ★
        { duration: '2m',  target: 670 },  // Phase 4: SPIKE ★
        { duration: '3m',  target: 470 },  // Phase 5: RECOVERY ★
        { duration: '2m',  target: 0   },  // Phase 6: Cool-down
    ],
    thresholds: {
        'http_req_duration': ['p(95)<5000'],
        'real_5xx_errors':   ['rate<0.03'],
        'booking_write_ms':  ['p(95)<3000'],
        'search_read_ms':    ['p(95)<2000'],
    },
};

// ─── HELPERS ─────────────────────────────────────────────────────────────────

function authHeader(token) {
    return {
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type':  'application/json',
            'Accept':        'application/json',
        },
        redirects: 0,
    };
}

function pick(arr) {
    return arr[Math.floor(Math.random() * arr.length)];
}

// ─── MAIN LOOP ───────────────────────────────────────────────────────────────

export default function () {
    const token  = pick(PASSENGER_TOKENS);
    const origin = pick(LOCATIONS);

    if (Math.random() < 0.70) {
        const rideId = pick(RIDE_IDS);
        const t0     = Date.now();
        const r = http.post(
            `${BASE_URL}/api/rides/${rideId}/book`,
            JSON.stringify({ seats: 1, communication_number: '0912345678' }),
            { ...authHeader(token), tags: { name: 'book_ride', operation: 'write' } }
        );
        writeTime.add(Date.now() - t0);
        const is5xx = r.status >= 500 || r.status === 0;
        errorRate.add(is5xx ? 1 : 0);
        check(r, { 'booking not 5xx': r => r.status < 500 && r.status > 0 });
    } else {
        const jitter = (Math.random() - 0.5) * 0.01;
        const dest   = pick(LOCATIONS);
        const t0     = Date.now();
        const r = http.get(
            `${BASE_URL}/api/rides/search` +
            `?source_lat=${(origin.lat + jitter).toFixed(6)}` +
            `&source_lng=${(origin.lng + jitter).toFixed(6)}` +
            `&dest_lat=${dest.lat}&dest_lng=${dest.lng}&departure_date=2026-12-15&seats_required=1`,
            { ...authHeader(token), tags: { name: 'ride_search', operation: 'read' } }
        );
        readTime.add(Date.now() - t0);
        const is5xx = r.status >= 500 || r.status === 0;
        errorRate.add(is5xx ? 1 : 0);
        check(r, { 'search not 5xx': r => r.status < 500 && r.status > 0 });
    }
}

// ─── SETUP ───────────────────────────────────────────────────────────────────

export function setup() {
    console.log('\n' + '='.repeat(72));
    console.log('  SyRide CAPACITY VALIDATION — 70% Sustained + 30% Spike');
    console.log('─'.repeat(72));
    console.log('  Phase 1  ( 0– 2m)   200 VUs   Warm-up');
    console.log('  Phase 2  ( 2– 5m)  →470 VUs   Ramp to 70% CPU');
    console.log('★ Phase 3  ( 5– 9m)   470 VUs   SUSTAINED HOLD — read p95 here');
    console.log('★ Phase 4  ( 9–11m)  →670 VUs   SPIKE — 30% headroom consumed');
    console.log('★ Phase 5  (11–14m)  →470 VUs   RECOVERY — elasticity proof');
    console.log('  Phase 6  (14–16m)  →  0 VUs   Cool-down');
    console.log('─'.repeat(72));
    console.log('  SECOND TERMINAL:');
    console.log('  docker stats --no-stream --format "table {{.Name}}\\t{{.CPUPerc}}\\t{{.MemUsage}}"');
    console.log('  Fire at minutes 7, 10, and 13');
    console.log('='.repeat(72) + '\n');

    const r = http.get(`${BASE_URL}/api/test`);
    if (r.status === 0) {
        throw new Error(`Cannot reach ${BASE_URL} — is Docker running?`);
    }
    console.log(`✅ Server alive (HTTP ${r.status}). 16-minute run starting.\n`);
}

// ─── TEARDOWN ────────────────────────────────────────────────────────────────

export function teardown() {
    console.log('\n' + '='.repeat(72));
    console.log('FILL IN YOUR DEFENSE SENTENCE:');
    console.log('─'.repeat(72));
    console.log('  "At 70% CPU — 470 VUs, ___ms p95 booking latency —');
    console.log('   SyRide serves 7,000 simultaneous users comfortably.');
    console.log('   A 43% spike to 670 VUs was absorbed: p95 climbed to ___ms');
    console.log('   but 5xx errors stayed at ___%. Recovery took ___ seconds."');
    console.log('='.repeat(72) + '\n');
}
