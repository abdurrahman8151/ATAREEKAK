<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies (RV-05)
    |--------------------------------------------------------------------------
    |
    | Comma-separated addresses/CIDRs of the reverse proxies in front of the
    | app. When set, Laravel honours X-Forwarded-For from those addresses only,
    | so `$request->ip()` is the real client instead of the proxy container.
    |
    | Unset/empty => trust nobody (Laravel's previous behaviour here), so this
    | cannot widen trust by accident. '0' is an explicit "trust nothing".
    |
    | Do NOT use '*'. nginx-docker.conf overwrites X-Forwarded-For with
    | $remote_addr, which is what makes honouring the header safe; with '*' any
    | client that can set the header would choose its own rate-limit bucket.
    |
    | Docker Compose default (nginx + app on one bridge network): 172.16.0.0/12
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
