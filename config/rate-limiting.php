<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master Toggle
    |--------------------------------------------------------------------------
    | Set RATE_LIMIT_ENABLED=false in .env to disable all throttling globally.
    | Useful for load testing. Set to true in production always.
    */
    'enabled' => env('RATE_LIMIT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Per-Address Flood Guard (multiplier)
    |--------------------------------------------------------------------------
    | For PUBLIC endpoints that carry an account identifier (email / phone /
    | username), requests are throttled on TWO independent buckets at once:
    |   - a strict per-ACCOUNT bucket at the category limit below, which an
    |     attacker cannot escape by rotating source IP addresses; and
    |   - a looser per-IP bucket at (category limit x this multiplier), which
    |     bounds a single abusive address without starving every legitimate
    |     user who shares one NAT / carrier gateway.
    | Set to 1 to make the per-IP cap equal to the per-account cap (stricter,
    | but re-introduces shared-IP starvation).
    */
    'ip_backstop_multiplier' => max(1, (int) env('RATE_LIMIT_IP_BACKSTOP_MULTIPLIER', 4)),

    /*
    |--------------------------------------------------------------------------
    | Per-Category Limits (requests per minute)
    |--------------------------------------------------------------------------
    | Each can be overridden individually in .env without touching code.
    |
    | Real-world reference:
    |   auth    →  5  (OTP/login brute-force protection)
    |   api     →  60  (standard user actions)
    |   search  →  30  (heavier DB queries, don't hammer)
    |   uploads →  10  (bandwidth + storage cost)
    |   admin   →  300 (trusted role, higher throughput)
    |   staff   →  200 (trusted role, moderate throughput)
    |
    | NOTE ON THE SOURCE OF TRUTH (original-audit T2-10): this file previously
    | had a byte-identical twin named config/rate_limiting.php (underscore) that
    | nothing loaded — RouteServiceProvider only ever read `rate-limiting` — so
    | an operator editing the underscore file changed nothing. That duplicate
    | has been deleted; this is the single config.
    */
    'limits' => [
        'auth'    => (int) env('RATE_LIMIT_AUTH',    5),
        'api'     => (int) env('RATE_LIMIT_API',     60),
        'search'  => (int) env('RATE_LIMIT_SEARCH',  30),
        'uploads' => (int) env('RATE_LIMIT_UPLOADS', 10),
        'admin'   => (int) env('RATE_LIMIT_ADMIN',   300),
        'staff'   => (int) env('RATE_LIMIT_STAFF',   200),
    ],

];
