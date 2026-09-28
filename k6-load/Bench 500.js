import http from "k6/http";
import { check } from "k6";

export const options = {
    stages: [
        { duration: "10s", target: 100 },
        { duration: "10s", target: 300 },
        { duration: "20s", target: 500 },
        { duration: "10s", target: 0 },
    ],
    thresholds: {
        "http_req_duration": ["p(95)<500"],
        "checks": ["rate>0.95"],
    },
};

// T3-9: credentials come from the environment, never from this file.
// Run with: k6 run --env K6_ADMIN_EMAIL=... --env K6_ADMIN_PASSWORD=... script.js
if (!__ENV.K6_ADMIN_EMAIL || !__ENV.K6_ADMIN_PASSWORD) {
    throw new Error('K6_ADMIN_EMAIL and K6_ADMIN_PASSWORD must be provided via --env');
}

export function setup() {
    const res = http.post(
        "http://localhost:8080/api/admin/login",
        JSON.stringify({ email: __ENV.K6_ADMIN_EMAIL, password: __ENV.K6_ADMIN_PASSWORD }),
        { headers: { "Content-Type": "application/json" } }
    );
    const token = res.json("tokens.access_token");
    if (!token) { console.error("Login failed: " + res.body); }
    return { token };
}

export default function (data) {
    const headers = { Authorization: `Bearer ${data.token}` };
    const r1 = http.get("http://localhost:8080/api/admin/routes/popular?limit=10", { headers });
    const r2 = http.get("http://localhost:8080/api/admin/drivers/top?limit=10", { headers });
    check(r1, { "popular routes <500ms": (r) => r.status === 200 && r.timings.duration < 500 });
    check(r2, { "top drivers <500ms": (r) => r.status === 200 && r.timings.duration < 500 });
}
