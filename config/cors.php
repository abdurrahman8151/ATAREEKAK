<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'web/*',
        'api/*',
        'admin/*',
        // AF-4: 'sanctum/csrf-cookie' removed together with the Sanctum
        // package — CORS must not advertise a route the app no longer ships.
        // T2-12: the previous 'session-debug' entry pointed at a route that does
        // not exist anywhere in routes/ (grep: zero matches). Dead debug hook;
        // removed so CORS does not advertise a path the app never serves.
    ],

    // T2-12: was ['*']. The app only ever registers GET/POST/PUT/PATCH/DELETE
    // (verified against the live route table); listing the concrete set avoids
    // preflighting arbitrary verbs and makes the permitted surface auditable.
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],

    // RV-28: was hardcoded to localhost only, so a real deployment's web client was blocked
    // until someone edited this file in the image. Make the extra origins environment-driven
    // while keeping the current localhost defaults EXACTLY (no behaviour change), so the owner
    // supplies their real domain via CORS_ALLOWED_ORIGINS (comma-separated) and the localhost
    // entries remain for development. We never invent a production domain here.
    'allowed_origins' => array_values(array_filter(array_merge(
        [
            'http://localhost:3000',  // Flutter web development server
            'http://127.0.0.1:3000',
            'http://localhost:8080',  // Alternative Flutter web port
            'http://127.0.0.1:8080',
        ],
        // Comma-separated extra origins from the environment (trimmed, blanks dropped).
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
        'Cache-Control',
        'Pragma',
    ],

    'exposed_headers' => [
        'Set-Cookie',
        'X-CSRF-TOKEN',
    ],

    'max_age' => 0,

    // T2-12: was a hardcoded `true` with the comment "This is CRUCIAL for session
    // cookies". It is not crucial for THIS app: authentication is JWT-bearer in
    // the Authorization header (see app/Http/Kernel.php — the `api` group runs no
    // StartSession), so `api/*` requests never need cookies and gain nothing from
    // credentialed CORS. With supports_credentials=true the browser will send the
    // web session cookie cross-origin to any listed origin, which is exactly the
    // exposure the audit warns about: on a developer or shared machine any process
    // able to bind localhost:3000/8080 can issue credentialed requests against the
    // session guard.
    //
    // Now env-driven and defaulting to FALSE (safe by default). Local origins are
    // left listed, per the maintainer's decision, so a developer who genuinely
    // needs credentialed cross-origin session access flips
    // CORS_SUPPORTS_CREDENTIALS=true in their own .env rather than everyone
    // inheriting an unsafe committed default.
    'supports_credentials' => filter_var(env('CORS_SUPPORTS_CREDENTIALS', false), FILTER_VALIDATE_BOOLEAN),
];
