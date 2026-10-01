<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'openroute' => [
        'api_key' => env('OPENROUTE_API_KEY'),
        'cache_ttl' => env('OPENROUTE_CACHE_TTL', 86400),
        'ssl_verify' => env('OPENROUTE_SSL_VERIFY', true),
        'timeout' => env('OPENROUTE_TIMEOUT', 30),
    ],
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    // config/services.php
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'), // Reference .env variable name
        'client_secret' => env('GOOGLE_CLIENT_SECRET'), // Reference .env variable name
        'redirect' => env('GOOGLE_REDIRECT_URL'), // Reference .env variable name
    ],
    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
        'sender_id' => env('FCM_SENDER_ID'),
        'credentials' => env('FCM_CREDENTIALS'),
    ],
    'chatdaddy' => [
        'api_key' => env('CHATDADDY_API_KEY'),
        'base_url' => env('CHATDADDY_BASE_URL', 'https://api.chatdaddy.tech'),
    ],

    // RV-37: TextMeBot was read straight from env() inside the service constructor.
    // Reading it from config lets a test simulate "provider not configured" with
    // Config::set instead of fighting ambient process state — which is what caused a
    // test to call the LIVE provider during RV-16.
    'textmebot' => [
        'api_key' => env('TEXTMEBOT_API_KEY'),
        // RV-28: the enabled flag was read via env() inside TextMeOtpController, which
        // silently breaks under `php artisan config:cache` (env() outside config/ returns
        // null once config is cached). Own it here like the api_key above.
        'enabled' => filter_var(env('TEXTMEBOT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // RV-28: WhatsAppOtpService read CALLMEBOT_API_KEY via env() in its constructor —
    // the same config:cache hazard, and it also blocked Config::set in tests. Owned here.
    'callmebot' => [
        'api_key' => env('CALLMEBOT_API_KEY'),
    ],

    // RV-28: ArabicPlaceNameService read MAPBOX_ACCESS_TOKEN via env(). Same hazard.
    'mapbox' => [
        'access_token' => env('MAPBOX_ACCESS_TOKEN'),
    ],

];
