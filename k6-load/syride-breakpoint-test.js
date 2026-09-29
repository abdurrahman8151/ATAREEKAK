/**
 * SyRide — Breakpoint Test (Realistic Mixed Traffic)
 * 13 APIs · 50 passenger tokens · 16 driver tokens
 * Think time: 3–12s between requests (realistic mobile app behaviour)
 *
 * FLOWS:
 *   40% — Passenger browse → search → book
 *   25% — Status polling + notification badge
 *   20% — App open (user / score / wallet / profile)
 *   10% — Driver (create ride / view bookings / accept booking)
 *    5% — Auth (OTP send)
 *
 * HOW TO RUN:
 *   k6 run "k6-load\syride-breakpoint-test.js"
 *
 * WATCH IN SECOND TERMINAL:
 *   docker stats --format "table {{.Name}}`t{{.CPUPerc}}`t{{.MemUsage}}"
 *
 * BREAKING POINT SIGNALS:
 *   real_errors rate   > 1%    → hard limit reached
 *   p95 ride_search_ms > 500ms → soft degradation
 *   app CPU            > 80%   → PHP workers saturated
 *   MySQL CPU          > 80%   → DB is the bottleneck
 */

import http from 'k6/http';
import { sleep, check, group } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { randomIntBetween } from './k6-utils.js';

http.setResponseCallback(http.expectedStatuses(
    { min: 200, max: 299 }, 400, 401, 403, 404, 409, 422
));

// ─── CONFIG ───────────────────────────────────────────────────────────────────

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

// ─── TOKENS (fresh — generated 2026-08-15, expire in 10 hours) ───────────────

// RV-07 (security): bearer tokens are no longer committed. Mint them
// out-of-band (k6 login step or your own script) and pass them in:
//   k6 run script.js -e K6_PASSENGER_TOKENS=t1,t2 -e K6_DRIVER_TOKENS=d1,d2
const _tokensFromEnv = (name) => (__ENV[name] || '').split(',').map((t) => t.trim()).filter(Boolean);
const PASSENGER_TOKENS = _tokensFromEnv('K6_PASSENGER_TOKENS');
const DRIVER_TOKENS = _tokensFromEnv('K6_DRIVER_TOKENS');
if (PASSENGER_TOKENS.length === 0 || DRIVER_TOKENS.length === 0) {
  throw new Error('K6_PASSENGER_TOKENS and K6_DRIVER_TOKENS are required (see k6-load/README.md). Committed tokens were removed by RV-07.');
}

// ─── DATA ─────────────────────────────────────────────────────────────────────

const RIDE_IDS = [764, 20423, 17020, 29270, 34028, 33270, 37965, 38201, 41049, 49316, 69132, 73016, 69392, 75041, 93396, 107954, 107908, 115258, 130153, 150173];
const BOOKING_IDS = [3901, 8574, 9044, 9178, 11182, 13253, 18003, 19065, 20689, 21267];
const USER_IDS = [251,252,253,254,255,256,257,258,259,260,261,262,263,264,265,266,267,268,269,270,271,272,273,274,275,276,277,278,279,280,281,282,283,284,285,286,287,288,289,290,291,292,293,294,295,296,297,298,299,300];

const LOCATIONS = [
    { lat: 31.9539, lng: 35.9106, name: 'Abdali' },
    { lat: 31.9784, lng: 35.8594, name: 'Mecca Street' },
    { lat: 31.9454, lng: 35.9284, name: 'Sweifieh' },
    { lat: 31.9037, lng: 35.9383, name: 'Airport Road' },
    { lat: 32.0156, lng: 35.8621, name: 'Zarqa Rd' },
];

// ─── METRICS ──────────────────────────────────────────────────────────────────

const errorRate      = new Rate('real_errors');
const searchTime     = new Trend('ride_search_ms', true);
const bookTime       = new Trend('booking_ms', true);
const pollTime       = new Trend('status_poll_ms', true);
const walletTime     = new Trend('wallet_ms', true);
const browseTime     = new Trend('ride_browse_ms', true);
const userAuthTime   = new Trend('user_auth_ms', true);
const acceptTime     = new Trend('booking_accept_ms', true);

// ─── OPTIONS ──────────────────────────────────────────────────────────────────

export const options = {
    stages: [
        { duration: '1m',  target: 100 },
        { duration: '3m',  target: 300 },
        { duration: '3m',  target: 500 },
        { duration: '3m',  target: 700 },
        { duration: '3m',  target: 900 },
        { duration: '2m',  target: 0   },
    ],
    thresholds: {
        'http_req_duration': ['p(95)<2000'],
        'real_errors':       ['rate<0.05'],
        'ride_search_ms':    ['p(95)<1000'],
        'booking_ms':        ['p(95)<2000'],
        'status_poll_ms':    ['p(95)<500'],
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

function record(r, label) {
    const ok = r.status > 0 && r.status < 500;
    errorRate.add(ok ? 0 : 1);
    if (!ok) console.error(`❌ ${label}: HTTP ${r.status} (${r.timings.duration.toFixed(0)}ms)`);
}

// ─── FLOWS ────────────────────────────────────────────────────────────────────

// NEW API 1: GET /api/rides — browse available rides (most common passenger action)
// NEW API 2: GET /api/rides/search — search specific route
// Existing: POST /api/rides/{id}/book
function passengerBrowseSearchBookFlow() {
    const token  = pick(PASSENGER_TOKENS);
    const origin = pick(LOCATIONS);
    const dest   = pick(LOCATIONS.filter(l => l.name !== origin.name));
    const jitter = (Math.random() - 0.5) * 0.01;

    group('browse', () => {
        const t0 = Date.now();
        const r = http.get(`${BASE_URL}/api/rides`, { ...auth(token), tags: { name: 'ride_browse' } });
        browseTime.add(Date.now() - t0);
        check(r, { 'browse not 500': (r) => r.status !== 500 });
        record(r, 'ride browse');
    });

    sleep(randomIntBetween(2, 4));

    group('search', () => {
        const t0 = Date.now();
        const r = http.get(
            `${BASE_URL}/api/rides/search` +
            `?pickup_lat=${origin.lat + jitter}&pickup_lng=${origin.lng + jitter}` +
            `&destination_lat=${dest.lat}&destination_lng=${dest.lng}&seats=1`,
            { ...auth(token), tags: { name: 'ride_search' } }
        );
        searchTime.add(Date.now() - t0);
        check(r, { 'search not 500': (r) => r.status !== 500, 'search not 404': (r) => r.status !== 404 });
        record(r, 'ride search');
    });

    sleep(randomIntBetween(3, 6));

    if (Math.random() < 0.5) {
        const rideId = pick(RIDE_IDS);
        group('book', () => {
            const t0 = Date.now();
            const r = http.post(
                `${BASE_URL}/api/rides/${rideId}/book`,
                JSON.stringify({ seats: 1, pickup_lat: origin.lat, pickup_lng: origin.lng }),
                { ...auth(token), tags: { name: 'book_ride' } }
            );
            bookTime.add(Date.now() - t0);
            check(r, { 'book not 500': (r) => r.status !== 500 });
            record(r, 'book ride');
        });
        sleep(randomIntBetween(1, 3));
    }
}

// Existing: GET /api/rides/{id} + GET /api/notifications/unread-count
function statusPollingFlow() {
    const token  = pick(PASSENGER_TOKENS);
    const rideId = pick(RIDE_IDS);

    group('poll', () => {
        const t0 = Date.now();
        const r = http.get(`${BASE_URL}/api/rides/${rideId}`, { ...auth(token), tags: { name: 'ride_status' } });
        pollTime.add(Date.now() - t0);
        check(r, { 'poll not 500': (r) => r.status !== 500, 'poll < 500ms': (r) => r.timings.duration < 500 });
        record(r, 'status poll');
    });

    sleep(randomIntBetween(3, 5));

    group('notif badge', () => {
        const r = http.get(`${BASE_URL}/api/notifications/unread-count`, { ...auth(token), tags: { name: 'notif_count' } });
        check(r, { 'badge not 500': (r) => r.status !== 500 });
        record(r, 'notif badge');
    });

    sleep(randomIntBetween(2, 4));
}

// NEW API 3: GET /api/user — auth check on every app open
// Existing: GET /api/score, GET /api/wallet/balance, GET /api/profile/{userId}
function appOpenFlow() {
    const token  = pick(PASSENGER_TOKENS);
    const userId = pick(USER_IDS);

    group('user auth', () => {
        const t0 = Date.now();
        const r = http.get(`${BASE_URL}/api/user`, { ...auth(token), tags: { name: 'user_auth' } });
        userAuthTime.add(Date.now() - t0);
        check(r, { 'user not 500': (r) => r.status !== 500 });
        record(r, 'user auth');
    });

    group('score', () => {
        const r = http.get(`${BASE_URL}/api/score`, { ...auth(token), tags: { name: 'score' } });
        check(r, { 'score not 500': (r) => r.status !== 500 });
        record(r, 'score');
    });

    group('wallet', () => {
        const t0 = Date.now();
        const r = http.get(`${BASE_URL}/api/wallet/balance`, { ...auth(token), tags: { name: 'wallet' } });
        walletTime.add(Date.now() - t0);
        check(r, { 'wallet not 500': (r) => r.status !== 500, 'wallet not 404': (r) => r.status !== 404 });
        record(r, 'wallet balance');
    });

    group('profile', () => {
        const r = http.get(`${BASE_URL}/api/profile/${userId}`, { ...auth(token), tags: { name: 'profile' } });
        check(r, { 'profile not 500': (r) => r.status !== 500, 'profile not 404': (r) => r.status !== 404 });
        record(r, 'profile');
    });

    sleep(randomIntBetween(3, 8));
}

// Existing: POST /api/rides/create-with-route, GET /api/bookings
// NEW API: POST /api/bookings/{id}/accept
function driverFlow() {
    const token  = pick(DRIVER_TOKENS);
    const origin = pick(LOCATIONS);
    const dest   = pick(LOCATIONS.filter(l => l.name !== origin.name));

    group('create ride', () => {
        const r = http.post(
            `${BASE_URL}/api/rides/create-with-route`,
            JSON.stringify({
                origin_lat:      origin.lat,
                origin_lng:      origin.lng,
                destination_lat: dest.lat,
                destination_lng: dest.lng,
                available_seats: randomIntBetween(1, 4),
                departure_time:  new Date(Date.now() + 3600000).toISOString(),
                price_per_seat:  randomIntBetween(3, 10),
            }),
            { ...auth(token), tags: { name: 'create_ride' } }
        );
        check(r, { 'create not 500': (r) => r.status !== 500 });
        record(r, 'create ride');
    });

    sleep(randomIntBetween(5, 12));

    group('my bookings', () => {
        const r = http.get(`${BASE_URL}/api/bookings`, { ...auth(token), tags: { name: 'my_bookings' } });
        check(r, { 'bookings not 500': (r) => r.status !== 500 });
        record(r, 'my bookings');
    });

    sleep(randomIntBetween(2, 4));

    // Driver accepts a booking (30% of driver flow iterations)
    if (Math.random() < 0.30) {
        const bookingId = pick(BOOKING_IDS);
        group('accept booking', () => {
            const t0 = Date.now();
            // 403 expected when driver doesn't own the ride — that's correct API behaviour
            const r = http.post(
                `${BASE_URL}/api/bookings/${bookingId}/accept`,
                JSON.stringify({}),
                { ...auth(token), tags: { name: 'accept_booking' } }
            );
            acceptTime.add(Date.now() - t0);
            check(r, { 'accept not 500': (r) => r.status !== 500 });
            record(r, 'accept booking');
        });
    }

    sleep(randomIntBetween(3, 6));
}

function authFlow() {
    group('otp send', () => {
        const r = http.post(
            `${BASE_URL}/api/otp/send`,
            JSON.stringify({ phone: `+96279${randomIntBetween(1000000, 9999999)}` }),
            { headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, tags: { name: 'otp_send' } }
        );
        check(r, { 'otp not 500': (r) => r.status !== 500, 'otp not 404': (r) => r.status !== 404 });
        record(r, 'otp send');
    });
    sleep(randomIntBetween(3, 8));
}

// ─── MAIN ─────────────────────────────────────────────────────────────────────

export default function () {
    const rand = Math.random();
    if      (rand < 0.40) passengerBrowseSearchBookFlow();
    else if (rand < 0.65) statusPollingFlow();
    else if (rand < 0.85) appOpenFlow();
    else if (rand < 0.95) driverFlow();
    else                  authFlow();
}

// ─── SETUP / TEARDOWN ─────────────────────────────────────────────────────────

export function setup() {
    console.log('\n' + '='.repeat(60));
    console.log('  SyRide Breakpoint Test — 13 APIs · Realistic Traffic');
    console.log(`  Target: ${BASE_URL}`);
    console.log(`  Tokens: ${PASSENGER_TOKENS.length} passenger, ${DRIVER_TOKENS.length} driver`);
    console.log(`  Rides: ${RIDE_IDS.length} · Bookings: ${BOOKING_IDS.length}`);
    console.log('  Stages: 100 → 900 VUs over 15 minutes');
    console.log('  APIs: browse, search, book, poll, notif, user, score, wallet,');
    console.log('        profile, create-ride, bookings, accept-booking, otp');
    console.log('='.repeat(60) + '\n');
    const r = http.get(`${BASE_URL}/api/test`);
    if (r.status === 0) throw new Error(`Cannot reach ${BASE_URL}`);
    console.log(`✅ Server alive (HTTP ${r.status}). Starting ramp-up.\n`);
}

export function teardown() {
    console.log('\n' + '='.repeat(60));
    console.log('BREAKPOINT TEST — HOW TO READ RESULTS');
    console.log('─'.repeat(60));
    console.log('real_errors rate   > 1%    → hard limit');
    console.log('ride_search_ms p95 > 500ms → soft degradation');
    console.log('http_req_duration p95 > 2s → unacceptable for mobile');
    console.log('app CPU            > 80%   → PHP workers saturated');
    console.log('MySQL CPU          > 80%   → DB is bottleneck');
    console.log('─'.repeat(60));
    console.log('CAPACITY (Little\'s Law):');
    console.log('  req/s from docker stats × 15s real think time = real users');
    console.log('='.repeat(60) + '\n');
}
