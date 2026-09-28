<?php

use Illuminate\Support\Str;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Session Driver
    |--------------------------------------------------------------------------
    */
    'driver' => env('SESSION_DRIVER', 'cookie'),

    /*
    |--------------------------------------------------------------------------
    | Session Lifetime
    |--------------------------------------------------------------------------
    */
    'lifetime' => env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,

    /*
    |--------------------------------------------------------------------------
    | Session Encryption
    |--------------------------------------------------------------------------
    | T2-12: 'false' is CORRECT for this deployment and is intentionally left
    | unchanged. SESSION_DRIVER is redis (see .env), so the session payload
    | lives server-side and the cookie carries only an opaque id — there is
    | nothing sensitive in the cookie to encrypt. Encryption only matters for
    | the 'cookie' driver. This comment exists so nobody "fixes" a non-defect:
    | the audit listed 'encrypt => false' but it is not a vulnerability here.
    */
    'encrypt' => false,

    /*
    |--------------------------------------------------------------------------
    | Session File Location
    |--------------------------------------------------------------------------
    */
    'files' => storage_path('framework/sessions'),

    /*
    |--------------------------------------------------------------------------
    | Session Database Connection
    |--------------------------------------------------------------------------
    */
    'connection' => env('SESSION_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Session Database Table
    |--------------------------------------------------------------------------
    */
    'table' => 'sessions',

    /*
    |--------------------------------------------------------------------------
    | Session Cache Store
    |--------------------------------------------------------------------------
    */
    'store' => env('SESSION_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Session Sweeping Lottery
    |--------------------------------------------------------------------------
    */
    'lottery' => [2, 100],

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Name
    |--------------------------------------------------------------------------
    */
    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_session'
    ),

    /*
    |--------------------------------------------------------------------------
    | Session Cookie Configuration
    |--------------------------------------------------------------------------
    */
    'path' => '/',
    'domain' => env('SESSION_DOMAIN', null),

    // T2-12: the two flags below previously read
    //     'secure'    => env('SESSION_SECURE_COOKIE', false),
    //     'http_only' => false,   // hardcoded — could not be re-enabled without a code change
    // The hardcoded http_only=false was the real defect: it stripped the primary
    // XSS mitigation from the session cookie (any injected script could read
    // document.cookie and exfiltrate a live session), justified in a comment as
    // "for Flutter web access". That justification does not hold: the whole API is
    // JWT-bearer (the `api` middleware group in app/Http/Kernel.php has no
    // StartSession/EncryptCookies at all, so it sends no session cookie), and even
    // for the `web` session routes an HttpOnly cookie is STILL transmitted on
    // cross-origin credentialed requests — HttpOnly only blocks JavaScript
    // *reading*, which no flow depends on. Restoring it cannot break a client that
    // only sends the cookie. http_only is now env-driven so a genuinely unusual
    // local need is a .env toggle, not a source edit.
    //
    // secure stays env-driven; production .env already sets SESSION_SECURE_COOKIE
    // =true. Its default is left false so plain-http local dev is not silently
    // broken (browsers drop Secure cookies over http), but the effective
    // production value is true, as verified.
    'secure' => env('SESSION_SECURE_COOKIE', false),
    'http_only' => env('SESSION_HTTP_ONLY', true),
    'same_site' => env('SESSION_SAME_SITE', 'lax'), // Changed from 'none' to 'lax'

    /*
    |--------------------------------------------------------------------------
    | Partitioned Cookies
    |--------------------------------------------------------------------------
    */
    'partitioned' => false,
];
