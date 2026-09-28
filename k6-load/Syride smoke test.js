/**
 * SyRide — Smoke Test (routes corrected from api.php)
 *
 * Tests every important endpoint with the EXACT paths from routes/api.php.
 * Run this before the breakpoint test — if anything fails here, don't proceed.
 *
 * Usage:
 *   k6 run --env TOKEN=eyJ... syride-smoke-test.js
 *   k6 run --env BASE_URL=http://localhost:8080 --env TOKEN=eyJ... --env USER_ID=5 syride-smoke-test.js
 *
 * Get TOKEN and USER_ID from:
 *   php artisan loadtest:tokens --count=1
 */

import http from 'k6/http';
import { check, group } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';
const TOKEN    = __ENV.TOKEN   || 'PASTE_JWT_HERE';
const USER_ID  = __ENV.USER_ID || '1';  // needed for GET /api/profile/{userId}

export const options = {
    vus: 1,
    iterations: 1,
    thresholds: {
        'checks': ['rate==1.0'],
    },
};

const auth = {
    headers: {
        'Authorization': `Bearer ${TOKEN}`,
        'Content-Type':  'application/json',
        'Accept':        'application/json',
    },
};

const guest = {
    headers: {
        'Content-Type': 'application/json',
        'Accept':       'application/json',
    },
};

function log(label, r, note = '') {
    const ms   = r.timings.duration.toFixed(0);
    const flag = r.status === 0   ? '❌ NO CONNECTION (is Docker running?)'
        : r.status >= 500  ? '❌ SERVER ERROR — check: docker logs syride_app1'
            : r.status === 401 ? '⚠️  401 — TOKEN is wrong or expired'
                : r.status === 404 ? '❌ 404 — ROUTE NOT FOUND (check api.php)'
                    : r.status === 429 ? '⚠️  429 — rate limited (expected in load test, not here)'
                        : ms > 1000        ? `⚠️  SLOW: ${ms}ms`
                            : `✅ ${ms}ms`;
    const extra = note ? `  [${note}]` : '';
    console.log(`  ${label.padEnd(30)} HTTP ${r.status} | ${flag}${extra}`);
}

export default function () {
    console.log(`\n🎯 ${BASE_URL}`);
    console.log(`🔑 ${TOKEN.substring(0, 30)}...`);
    console.log('─'.repeat(65));

    // ── UTILITY ─────────────────────────────────────────────────────────────────

    group('Utility', () => {
        const r = http.get(`${BASE_URL}/api/test`, guest);
        check(r, {
            'GET /api/test → 200': (r) => r.status === 200,
        });
        log('GET /api/test', r);
    });

    // ── GUEST: OTP ───────────────────────────────────────────────────────────────
    // throttle:auth — 5/min. This will 429 if you run the smoke test twice quickly.

    group('Guest: OTP', () => {
        const r = http.post(
            `${BASE_URL}/api/otp/send`,
            JSON.stringify({ phone: '+962791234567' }),
            guest
        );
        check(r, {
            'POST /api/otp/send → not 500':  (r) => r.status !== 500,
            'POST /api/otp/send → not 0':    (r) => r.status !== 0,
            'POST /api/otp/send → < 3s':     (r) => r.timings.duration < 3000,
        });
        log('POST /api/otp/send', r, '429 expected if run twice quickly');
    });

    // ── GUEST: AUTH ──────────────────────────────────────────────────────────────

    group('Guest: Auth', () => {
        // Login with wrong creds — just checking the route exists and returns 401/422, not 404/500
        const r = http.post(
            `${BASE_URL}/api/auth/login`,
            JSON.stringify({ phone: '+962799999999', password: 'wrong' }),
            guest
        );
        check(r, {
            'POST /api/auth/login → not 404': (r) => r.status !== 404,
            'POST /api/auth/login → not 500': (r) => r.status !== 500,
            'POST /api/auth/login → not 0':   (r) => r.status !== 0,
        });
        log('POST /api/auth/login', r, '401/422 expected with fake creds');
    });

    // ── AUTHENTICATED: RIDES ─────────────────────────────────────────────────────
    // These are the hot paths — fix 404s here before running breakpoint test

    group('Authenticated: Rides', () => {

        group('GET /api/rides/search', () => {
            // throttle:search (30/min) + throttle:api (60/min) — effective cap: 30/min
            const r = http.get(
                `${BASE_URL}/api/rides/search?pickup_lat=31.9539&pickup_lng=35.9106` +
                `&destination_lat=31.9454&destination_lng=35.9284&seats=1`,
                auth
            );
            check(r, {
                'GET /api/rides/search → not 404': (r) => r.status !== 404,
                'GET /api/rides/search → not 500': (r) => r.status !== 500,
                'GET /api/rides/search → not 0':   (r) => r.status !== 0,
                'GET /api/rides/search → < 2s':    (r) => r.timings.duration < 2000,
                'GET /api/rides/search → json':    (r) => r.headers['Content-Type']?.includes('application/json'),
            });
            log('GET /api/rides/search', r, 'MOST IMPORTANT ROUTE');
        });

        group('GET /api/rides (listing, no search filter)', () => {
            // throttle:api only (60/min) — safer for load testing than /search
            const r = http.get(`${BASE_URL}/api/rides`, auth);
            check(r, {
                'GET /api/rides → not 404': (r) => r.status !== 404,
                'GET /api/rides → not 500': (r) => r.status !== 500,
                'GET /api/rides → < 2s':    (r) => r.timings.duration < 2000,
            });
            log('GET /api/rides', r);
        });

        group('GET /api/rides/{rideId} (status polling)', () => {
            // Replace 1 with a real ride ID from your DB: SELECT id FROM rides LIMIT 1;
            const r = http.get(`${BASE_URL}/api/rides/1`, auth);
            check(r, {
                'GET /api/rides/1 → not 404': (r) => r.status !== 404,
                'GET /api/rides/1 → not 500': (r) => r.status !== 500,
                'GET /api/rides/1 → < 500ms': (r) => r.timings.duration < 500,
            });
            log('GET /api/rides/1', r, '404 = ride 1 does not exist, use a real ID');
        });

        group('POST /api/rides/{rideId}/book (create booking)', () => {
            // This is the correct booking endpoint — NOT POST /api/bookings (that doesn't exist)
            const r = http.post(
                `${BASE_URL}/api/rides/1/book`,
                JSON.stringify({ seats: 1, pickup_lat: 31.9539, pickup_lng: 35.9106 }),
                auth
            );
            check(r, {
                'POST /api/rides/1/book → not 404': (r) => r.status !== 404,
                'POST /api/rides/1/book → not 500': (r) => r.status !== 500,
                'POST /api/rides/1/book → < 3s':    (r) => r.timings.duration < 3000,
            });
            log('POST /api/rides/1/book', r, '422 expected with fake data');
        });

    });

    // ── AUTHENTICATED: BOOKINGS ───────────────────────────────────────────────────

    group('Authenticated: Bookings', () => {
        const r = http.get(`${BASE_URL}/api/bookings`, auth);
        check(r, {
            'GET /api/bookings → not 404': (r) => r.status !== 404,
            'GET /api/bookings → not 500': (r) => r.status !== 500,
            'GET /api/bookings → < 1s':    (r) => r.timings.duration < 1000,
        });
        log('GET /api/bookings', r);
    });

    // ── AUTHENTICATED: USER DATA ──────────────────────────────────────────────────

    group('Authenticated: User data', () => {

        group('GET /api/score', () => {
            const r = http.get(`${BASE_URL}/api/score`, auth);
            check(r, {
                'GET /api/score → not 404': (r) => r.status !== 404,
                'GET /api/score → not 500': (r) => r.status !== 500,
                'GET /api/score → < 500ms': (r) => r.timings.duration < 500,
            });
            log('GET /api/score', r);
        });

        group('GET /api/wallet/balance', () => {
            // CORRECT endpoint — NOT /api/wallet
            const r = http.get(`${BASE_URL}/api/wallet/balance`, auth);
            check(r, {
                'GET /api/wallet/balance → not 404': (r) => r.status !== 404,
                'GET /api/wallet/balance → not 500': (r) => r.status !== 500,
                'GET /api/wallet/balance → < 500ms': (r) => r.timings.duration < 500,
            });
            log('GET /api/wallet/balance', r);
        });

        group('GET /api/notifications/unread-count (badge check)', () => {
            // Lighter than /api/notifications — this is polled constantly by the app
            const r = http.get(`${BASE_URL}/api/notifications/unread-count`, auth);
            check(r, {
                'notifications/unread-count → not 404': (r) => r.status !== 404,
                'notifications/unread-count → not 500': (r) => r.status !== 500,
                'notifications/unread-count → < 300ms': (r) => r.timings.duration < 300,
            });
            log('GET /api/notifications/unread-count', r, 'fastest notification endpoint');
        });

        group('GET /api/notifications (full list)', () => {
            const r = http.get(`${BASE_URL}/api/notifications`, auth);
            check(r, {
                'GET /api/notifications → not 404': (r) => r.status !== 404,
                'GET /api/notifications → not 500': (r) => r.status !== 500,
                'GET /api/notifications → < 1s':    (r) => r.timings.duration < 1000,
            });
            log('GET /api/notifications', r);
        });

        group('GET /api/profile/{userId}', () => {
            // CORRECT endpoint — GET /api/profile (no userId) does NOT exist
            const r = http.get(`${BASE_URL}/api/profile/${USER_ID}`, auth);
            check(r, {
                [`GET /api/profile/${USER_ID} → not 404`]: (r) => r.status !== 404,
                [`GET /api/profile/${USER_ID} → not 500`]: (r) => r.status !== 500,
                [`GET /api/profile/${USER_ID} → < 500ms`]: (r) => r.timings.duration < 500,
            });
            log(`GET /api/profile/${USER_ID}`, r, `set --env USER_ID=<real id> if 404`);
        });

    });

    // ── AUTHENTICATED: CREATE RIDE (driver) ───────────────────────────────────────

    group('Authenticated: Driver', () => {
        const r = http.post(
            `${BASE_URL}/api/rides`,
            JSON.stringify({
                origin_lat:       31.9539,
                origin_lng:       35.9106,
                destination_lat:  31.9454,
                destination_lng:  35.9284,
                available_seats:  2,
                departure_time:   new Date(Date.now() + 3600000).toISOString(),
                price_per_seat:   5,
            }),
            auth
        );
        check(r, {
            'POST /api/rides → not 404': (r) => r.status !== 404,
            'POST /api/rides → not 500': (r) => r.status !== 500,
            'POST /api/rides → < 3s':    (r) => r.timings.duration < 3000,
        });
        log('POST /api/rides', r, '422 expected if token is passenger role');
    });

    console.log('\n' + '─'.repeat(65));
    console.log('If 404 → route path is wrong (check the table above)');
    console.log('If 401 → TOKEN is wrong or expired (re-run: php artisan loadtest:tokens)');
    console.log('If 500 → application error (check: docker logs syride_app1)');
    console.log('If status 0 → server not running on ' + BASE_URL);
    console.log('\nAll green? → run the breakpoint test.');
}
