/**
 * k6 harness — setup() authentication (decision un12, owner ruling Option A).
 *
 * WHY THIS FILE EXISTS.
 * The RV-07 fix stopped the harness from COMMITTING bearer tokens, and moved them to env vars.
 * That was necessary but it is not sufficient, and the README says so:
 *
 *   "they expired silently (the ones in git had been dead since 2026-08-14 while the load tests
 *    kept 'passing' — the search slice was 422-ing the whole time)"
 *
 * An env-var token is a SNAPSHOT. It is minted once by a human, pasted into a shell, and then it
 * silently rots — and a load test against an expired token measures 401 latency, not the
 * application's. That is how a green report can be meaningless.
 *
 * So `setup()` LOGS IN through the real API and hands every VU a FRESH token that lives as long as
 * the run. Nothing is pasted, nothing expires mid-run, and the token never touches disk.
 *
 * WHAT setup() RETURNS is passed by k6 to every VU invocation, so the cost of logging in is paid
 * ONCE per run rather than once per VU — which also means login latency itself is NOT what the
 * thresholds measure.
 *
 * CREDENTIALS come from the environment, never from this file:
 *   K6_PEOPLE  — "email:password,email:password,..." (the accounts to drive load with)
 *   BASE_URL   — target (default http://localhost:8080)
 *
 * A THROWAWAY ACCOUNT IS RECOMMENDED: these are real logins against a real database, and the
 * harness bumps nothing but does authenticate.
 */

import http from 'k6/http';
import { check, fail } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';

/**
 * Parse "a@b.com:pw,c@d.com:pw2" into [{email, password}, ...].
 *
 * A colon inside the password would break this. Passwords in the K6_PEOPLE contract must not
 * contain ':' - documented here because the failure mode is otherwise a silent mis-split.
 */
export function parsePeople(raw) {
  return String(raw || '')
    .split(',')
    .map((entry) => entry.trim())
    .filter(Boolean)
    .map((entry) => {
      const at = entry.indexOf(':');
      if (at < 1) {
        throw new Error(
          `K6_PEOPLE entry "${entry}" is not "email:password". Every entry needs a colon.`
        );
      }
      return {
        email: entry.slice(0, at).trim(),
        password: entry.slice(at + 1),
      };
    });
}

/**
 * Log in one account and return its access token.
 *
 * The response shape is LoginController -> JwtService::generateTokenPair:
 *   { tokens: { access_token, refresh_token, token_type }, ... }
 */
export function login(email, password) {
  const res = http.post(
    `${BASE_URL}/api/auth/login`,
    JSON.stringify({ email, password }),
    {
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      tags: { name: 'POST /api/auth/login' },
    }
  );

  const ok = check(res, {
    'login 200': (r) => r.status === 200,
    'login returns an access token': (r) => {
      try {
        return typeof r.json('tokens.access_token') === 'string' &&
               r.json('tokens.access_token').length > 0;
      } catch (e) {
        return false;
      }
    },
  });

  if (!ok) {
    fail(
      `Login failed for ${email}: HTTP ${res.status} ${String(res.body).slice(0, 200)}`
    );
  }

  return res.json('tokens.access_token');
}

/**
 * The standard setup(). Returns { tokens: [...] } for the VUs to draw from.
 *
 * Throws (rather than returning an empty array) when credentials are missing: a load test that
 * silently authenticates nobody is exactly the failure mode RV-17 was filed about.
 */
export function authSetup() {
  const people = parsePeople(__ENV.K6_PEOPLE);

  if (people.length === 0) {
    fail(
      'K6_PEOPLE is required. Set it to "email:password,email:password" - a load test that ' +
      'authenticates nobody measures the 401 path, not the application.'
    );
  }

  console.log(`[harness] logging in ${people.length} account(s) against ${BASE_URL}`);

  const tokens = people.map((person) => login(person.email, person.password));

  const distinct = new Set(tokens).size;
  console.log(`[harness] minted ${tokens.length} token(s), ${distinct} distinct`);

  if (distinct !== tokens.length) {
    // The SAME account appearing twice would quietly halve the effective VU population and make
    // the numbers look worse than the system is. Say so rather than reporting a misleading p95.
    console.warn(
      `[harness] WARNING: only ${distinct} distinct token(s) for ${tokens.length} account(s). ` +
      'Effective concurrent-user count is lower than the account count.'
    );
  }

  return { tokens, baseUrl: BASE_URL };
}

/** Pick a token for this VU iteration, round-robin so load spreads over all accounts. */
export function pickToken(tokens, vu) {
  if (!tokens || tokens.length === 0) {
    fail('setup() returned no tokens - the harness did not authenticate.');
  }
  return tokens[(__ITER % tokens.length)];
}

/** Standard Authorization header for a token. */
export function authHeader(token) {
  return {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };
}