<?php

use Illuminate\Support\Str;

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'),
    'middleware' => ['web'],
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 180,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'silenced' => [],
    'metrics' => ['trim_snapshots' => ['job' => 24, 'queue' => 24]],
    'waits' => ['redis:notifications' => 3, 'redis:default' => 5],
    'workers' => null,
    'fast_termination' => false,
    'timeout' => 60,

    'environments' => [
        'production' => [
            'notifications-supervisor' => [
                'connection' => 'redis',
                'queue' => ['notifications'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'size',
                'maxProcesses' => 6,
                'minProcesses' => 2,
                'tries' => 3,
                'timeout' => 30,
            ],
            'default-supervisor' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'size',
                'maxProcesses' => 4,
                'minProcesses' => 1,
                'tries' => 3,
                'timeout' => 60,
            ],
        ],
        'local' => [
            'local-supervisor' => [
                'connection' => 'redis',
                'queue' => ['notifications', 'default'],
                'balance' => 'simple',
                'processes' => 4,
                'tries' => 1,
                'timeout' => 60,
            ],
        ],
    ],
];
