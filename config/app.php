<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [
    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    */
    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    */
    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    */
    'url' => env('APP_URL', 'http://localhost'),
    'asset_url' => env('ASSET_URL'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    */
    'timezone' => env('APP_TIMEZONE', 'Asia/Damascus'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    */
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    */
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    */
    'maintenance' => [
        'driver' => 'file',
    ],

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    */
    'providers' => ServiceProvider::defaultProviders()->merge([
        AppServiceProvider::class,
        AuthServiceProvider::class,
        EventServiceProvider::class,
        RouteServiceProvider::class,
        BroadcastServiceProvider::class,
        HorizonServiceProvider::class,
    ])->toArray(),

    /*
    |--------------------------------------------------------------------------
    | Class Aliases
    |--------------------------------------------------------------------------
    */
    'aliases' => Facade::defaultAliases()->merge([
        // Custom Facades (if any)
    ])->toArray(),

    /*
    |--------------------------------------------------------------------------
    | Custom Service Configurations
    |--------------------------------------------------------------------------
    */
    'services' => [
        'openroute' => [
            'key' => env('OPENROUTE_API_KEY'),
            'cache_ttl' => env('OPENROUTE_CACHE_TTL', 86400),
            'geocode_endpoint' => 'https://api.openrouteservice.org/geocode/search',
            'directions_endpoint' => 'https://api.openrouteservice.org/v2/directions/driving-car',
        ],
    ],
];
