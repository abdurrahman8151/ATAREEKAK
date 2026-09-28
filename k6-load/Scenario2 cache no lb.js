import http from 'k6/http';
import { check } from 'k6';

// SCENARIO 2 — Cache only, No Load Balancer
// Before running:
//   docker compose stop app2 app3
//   set CACHE_DRIVER: redis in docker-compose.yml for app1
//   docker compose up -d --force-recreate app1
//   docker exec syride_app1 php artisan cache:clear

export const options = { vus: 50, duration: '30s' };

// T3-9: credentials come from the environment, never from this file.
// Run with: k6 run --env K6_ADMIN_EMAIL=... --env K6_ADMIN_PASSWORD=... script.js
if (!__ENV.K6_ADMIN_EMAIL || !__ENV.K6_ADMIN_PASSWORD) {
    throw new Error('K6_ADMIN_EMAIL and K6_ADMIN_PASSWORD must be provided via --env');
}

export function setup() {
    console.log('SCENARIO 2 — Redis Cache | No Load Balancer');
    const res = http.post(
        'http://localhost:8080/api/admin/login',
        JSON.stringify({ email: __ENV.K6_ADMIN_EMAIL, password: __ENV.K6_ADMIN_PASSWORD }),
        { headers: { 'Content-Type': 'application/json' } }
    );
    return { token: res.json('tokens.access_token') };
}

export default function (data) {
    const res = http.get('http://localhost:8080/api/admin/dashboard/stats', {
        headers: { Authorization: `Bearer ${data.token}` },
    });
    check(res, { 'status 200': (r) => r.status === 200 });
}
