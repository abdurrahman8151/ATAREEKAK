<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * RV-37: `Http::preventStrayRequests()` (in TestCase::setUp) cannot see the
     * three call sites that build a Guzzle client directly - WhatsAppOtpService,
     * TextMeBotOtpService and GoogleController - so a developer machine whose
     * `.env` carries provider credentials could still let the suite egress. Each
     * of those paths is credential-gated, so neutralising the credentials here
     * closes the last route without touching the owner-owned `phpunit.xml` or
     * `.env` (both are explicitly never-committed local files).
     *
     * A test that needs a CONFIGURED provider sets the key itself with
     * `Config::set` for that test only, which is the pattern RV-22 already
     * established - visible per test instead of ambient for the whole process.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // The credential/config gates of the three Guzzle-direct call sites
        // (WhatsApp/TextMeBot OTP, GoogleController) plus the enable-flag those
        // services read. Nulling the keys stops the send; `textmebot.enabled => false`
        // additionally keeps the controller's own "provider disabled" 400 branch the
        // correct result for a suite with no provider — TextMeOtpControllerTest asserts
        // that branch, and a developer .env with TEXTMEBOT_ENABLED=true would otherwise
        // steer it down the provider path (measured: it turned 400 into 200).
        foreach ([
            'services.textmebot.api_key' => null,
            'services.textmebot.enabled' => false,
            'services.callmebot.api_key' => null,
            'services.chatdaddy.api_key' => null,
            'services.google.client_id' => null,
            'services.google.client_secret' => null,
        ] as $key => $value) {
            $app['config']->set($key, $value);
        }

        return $app;
    }
}
