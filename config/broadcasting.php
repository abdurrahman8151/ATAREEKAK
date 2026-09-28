<?php

return [
    'default' => env('BROADCAST_DRIVER', 'pusher'),

    'connections' => [
        'pusher' => [
            'driver' => 'pusher',
            // T2-8: these three previously carried literal fallbacks
            // (env('PUSHER_APP_KEY', '<literal>')) in a file that is committed
            // to a PUBLIC repository, so the credentials — including the
            // server-only Pusher SECRET — were disclosed in version control,
            // and a deploy that lost its env silently kept working on them
            // instead of failing. They are now env-only with NO default.
            // PUSHER_APP_CLUSTER keeps its non-secret region default; it is not
            // a credential. AppServiceProvider::boot() hard-stops a production
            // boot when the pusher driver is selected without these values.
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'ap2'),
                'useTLS' => true,
                'encrypted' => true,
            ],
            'client_options' => [
                // Optional: Add any specific Guzzle client options here
                // 'verify' => env('APP_ENV') === 'production' ? true : false,
            ],
        ],

        // Other connections (keep as fallback)
        'log' => [
            'driver' => 'log',
        ],
        'null' => [
            'driver' => 'null',
        ],
    ],
];
