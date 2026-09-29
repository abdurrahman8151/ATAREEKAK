<?php

namespace App\Providers;

use App\Support\HorizonAccess;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    protected function gate(): void
    {
        // RV-06: this gate was `return true` for every caller on the assumption
        // that "port 8080 is only exposed to localhost" — false for the public
        // Render service, and nginx's `location /` forwards the horizon prefix.
        // An unauthenticated /horizon exposed job payloads (user ids, phone
        // numbers, notification text) plus job retry/delete.
        // Policy now lives in HorizonAccess: local/testing keep working,
        // everything else needs the HORIZON_ACCESS_TOKEN secret and fails
        // closed when it is unset.
        Gate::define('viewHorizon', function ($user = null) {
            return HorizonAccess::allows();
        });
    }
}
