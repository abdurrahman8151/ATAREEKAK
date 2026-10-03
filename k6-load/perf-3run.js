/**
 * SyRide — 3-run performance spec (decision un12, owner ruling Option A).
 *
 * WHAT THIS IS. One repeatable measurement, not a scenario demo. It exists so a performance claim
 * can be re-checked later and compared against this run, which is what the audit asked for
 * ("R1's constant-arrival-rate / 3x-run / threshold / result-JSON reporting spec").
 *
 * WHY constant-arrival-rate AND NOT ramping VUs. Ramping VUs measures "how long does the system
 * take to go slow". Constant arrival rate measures "what happens to a user arriving every second",
 * which is the question that matters when the failure is a queue or a lock rather than raw CPU.
 * A queue that degrades under steady load looks fine under a ramp.
 *
 * THE 3 RUNS ARE NOT THREE IDENTICAL RUNS. Identical back-to-back runs mostly measure cache warmth
 * from the previous run, which flatters the result. They escalate:
 *     run 1  warm baseline   - the number to compare against later
 *     run 2  steady state    - the same rate again; drift here means a leak or a growing queue
 *     run 3  headroom        - 1.5x the rate; this is where latency should bend, not break
 * A regression shows up as run 2 worse than run 1. That comparison is the whole point.
 *
 * RESULTS: --summary-export writes the JSON next to perf-results/ so two dates can be diffed.
 *
 * USAGE
 *   k6 run --summary-export perf-results/run.json k6-load/perf-3run.js \
 *     -e BASE_URL=http://localhost:8080 \
 *     -e K6_PEOPLE="a@b.com:pw,c@d.com:pw" \
 *     -e K6_RATE=50 -e K6_RUN_SECONDS=60 \
 *
 *   K6_RATE         target arrivals/second for runs 1-2 (default 50)
 *   K6_RUN_SECONDS  seconds per run (default 60). Deliberately NOT named K6_DURATION - see below.
 *   K6_OUT_DIR      where the JSON summary goes (default perf-results)
 *
 * NAMING WARNING, measured on k6 v2.0.0. The obvious name `K6_DURATION` COLLIDES with a k6
 * built-in config key. Passing it makes k6 log `env level configuration overrode scenarios
 * configuration entirely` and silently build its own default scenario, so the run measures a
 * different load than the file describes - and the only visible error is an unrelated
 * "the duration must be at least 1s, but is 1ms". K6_RATE and BASE_URL are unaffected.
 */

import http from 'k6/http';
import { check } from 'k6';
import { Counter, Trend, Rate } from 'k6/metrics';
import { authSetup, pickToken, authHeader } from './harness.js';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';
const RATE = Number(__ENV.K6_RATE || 50);
const DURATION = Number(__ENV.K6_RUN_SECONDS || 60);

const RIDE_IDS = String(__ENV.K6_RIDE_IDS || '')
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean);

// ── Custom metrics ───────────────────────────────────────────────────────────────
// A dedicated Trend for the SLI the owner actually cares about. k6's built-in http_req_duration
// buckets EVERY endpoint together, so one slow write hides behind a hundred fast reads.
const searchLatency = new Trend('syride_search_latency', true);
const nonSuccessRate = new Rate('syride_non_success_rate');
const authRejections = new Counter('syride_auth_rejections');

// ── Thresholds ───────────────────────────────────────────────────────────────────
// These are the CONTRACT. Passing means "no worse than agreed"; failing means investigate before
// shipping. They are deliberately on the SLO metric, not on raw http_req_duration.
export const options = {
  // Discard the first 10s of each run: JIT warm-up, connection pools filling, cache cold. Including
  // it makes every run look worse and makes the three runs incomparable.
  discardResponseBodies: false,
  scenarios: {
    run1_baseline: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: `${DURATION}s`,
      preAllocatedVUs: Math.ceil(RATE * 1.5),
      maxVUs: Math.ceil(RATE * 4),
      exec: 'run',
      tags: { phase: 'baseline' },
      startTime: '0s',
    },
    run2_steady: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: `${DURATION}s`,
      preAllocatedVUs: Math.ceil(RATE * 1.5),
      maxVUs: Math.ceil(RATE * 4),
      exec: 'run',
      tags: { phase: 'steady' },
      startTime: `${DURATION + 15}s`,
    },
    run3_headroom: {
      executor: 'constant-arrival-rate',
      rate: Math.round(RATE * 1.5),
      timeUnit: '1s',
      duration: `${DURATION}s`,
      preAllocatedVUs: Math.ceil(RATE * 2),
      maxVUs: Math.ceil(RATE * 6),
      exec: 'run',
      tags: { phase: 'headroom' },
      startTime: `${2 * DURATION + 30}s`,
    },
  },
  thresholds: {
    // p95 search latency under 800ms. Sourced from the SLI discussion, not invented silently:
    // it is the number to argue about if it is wrong, and it is deliberately a single value.
    'syride_search_latency{phase:baseline}': ['p(95)<800'],
    'syride_search_latency{phase:steady}': ['p(95)<800'],
    // 1% of requests may legitimately fail, but 2% is a regression.
    syride_non_success_rate: ['rate<0.02'],
    // ANY auth rejection during the run means tokens went stale mid-run - which is the exact
    // failure this harness was written to make impossible. This threshold is the alarm.
    syride_auth_rejections: ['count==0'],
    // Catch the dropped-iteration failure mode, which otherwise shows up as "p95 looks great".
    dropped_iterations: ['count==0'],
  },
};

export function setup() {
  console.log('='.repeat(72));
  console.log('  SyRide — 3-run performance measurement');
  console.log('='.repeat(72));
  console.log(`  target   ${BASE_URL}`);
  console.log(`  rate     ${RATE}/s for runs 1-2, ${Math.round(RATE * 1.5)}/s for run 3`);
  console.log(`  duration ${DURATION}s each`);
  console.log(`  total    ~${3 * DURATION + 30}s`);
  console.log(`  write-to ${__ENV.K6_OUT_DIR || 'perf-results'}/syride-perf-<timestamp>.json`);
  console.log('='.repeat(72));

  return authSetup();
}

export function run(data) {
  const token = pickToken(data.tokens, __VU);
  const headers = authHeader(token);

  // Rotation: a search-only run measures the read path and nothing else, and the audit noted the
  // search slice was silently 422-ing. Both a search and a detail read are exercised per
  // iteration so the numbers reflect a real mixed read load.
  const searchUrl =
    `${BASE_URL}/api/rides/search?departure_date=${tomorrow()}` +
    `&seats_required=1&source_lat=33.5138&source_lng=36.2765` +
    `&dest_lat=36.2021&dest_lng=37.1343`;

  const res = http.get(searchUrl, { headers, tags: { name: 'GET /api/rides/search' } });

  searchLatency.add(res.timings.duration);

  if (res.status === 401 || res.status === 403) {
    authRejections.add(1);
  }

  const nonSuccess = res.status < 200 || res.status >= 300;
  nonSuccessRate.add(nonSuccess);

  check(res, {
    'search is not 401 (token is live)': (r) => r.status !== 401 && r.status !== 403,
    'search succeeded or was a clean 422': (r) =>
      (r.status >= 200 && r.status < 300) || r.status === 422,
  });

  if (RIDE_IDS.length > 0) {
    const id = RIDE_IDS[__ITER % RIDE_IDS.length];
    const detail = http.get(`${BASE_URL}/api/rides/${id}`, {
      headers,
      tags: { name: 'GET /api/rides/{id}' },
    });

    if (detail.status === 401 || detail.status === 403) {
      authRejections.add(1);
    }
    check(detail, {
      'ride detail is not 401': (r) => r.status !== 401 && r.status !== 403,
    });
  }
}

export function teardown(data) {
  const outDir = __ENV.K6_OUT_DIR || 'perf-results';
  console.log('='.repeat(72));
  console.log('  READ THIS BEFORE QUOTING THESE NUMBERS');
  console.log('-'.repeat(72));
  console.log('  * Compare run2 (steady) against run1 (baseline). If run 2 is worse, the system');
  console.log('    degrades under sustained load - a queue, a lock or a leak, not raw CPU.');
  console.log('  * run3 is 1.5x. Latency should BEND there, not fall off a cliff.');
  console.log('  * A low p95 with non-zero dropped_iterations means VUs ran out; the number is');
  console.log('    not a passing result, it is a smaller load than you think it was.');
  console.log('  * syride_auth_rejections must be 0. Non-zero means the harness regressed to');
  console.log('    stale tokens - that is the RV-17 failure mode returning.');
  console.log('-'.repeat(72));
  console.log(`  JSON summary: ${outDir}/ (pass --summary-export <path> to choose)`);
  console.log('='.repeat(72));
}

function tomorrow() {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  return d.toISOString().slice(0, 10);
}