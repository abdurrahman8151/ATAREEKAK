<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Search thresholds (AF-4, app-future audit)
    |--------------------------------------------------------------------------
    |
    | These were compile-time constants on RideSearchService (MAX_DISTANCE_KM=20,
    | ROUTE_BUFFER_DEGREES=0.05). Configured values preserve the exact previous
    | behavior; they exist so load tests and production can tune the matching
    | radius without a code deploy.
    |
    */

    'search' => [
        // Rides whose pickup AND destination fall within this distance of the
        // searched endpoints match by "endpoint strategy".
        'max_distance_km' => env('RIDE_SEARCH_MAX_DISTANCE_KM', 20),

        // Buffer (in decimal degrees) around a ride's stored route geometry
        // used by the "route strategy" — a search point this close to the
        // polyline matches even if the endpoints are far. 0.05 deg ~ 5 km.
        'route_buffer_degrees' => env('RIDE_SEARCH_ROUTE_BUFFER_DEGREES', 0.05),
    ],

    /*
    |--------------------------------------------------------------------------
    | No-show reporting windows
    |--------------------------------------------------------------------------
    |
    | AF-4 (app-future audit): these were hardcoded on Noshowservice with the
    | REAL values (1 h / 2 h) commented out and testing values (1 min / 2 min)
    | in force — a two-minute dispute window is not a product rule, and a
    | deploy could not tune it without editing money-path code. The real hours
    | are now the default.
    |
    */

    'noshow' => [
        // How long after departure the no-show report button unlocks.
        'gate_hours' => (float) env('NOSHOW_GATE_HOURS', 1),

        // How long the reported party has to file a counter-report before the
        // reporter wins by default and the penalty settles.
        'dispute_hours' => (float) env('NOSHOW_DISPUTE_HOURS', 2),
    ],
];
