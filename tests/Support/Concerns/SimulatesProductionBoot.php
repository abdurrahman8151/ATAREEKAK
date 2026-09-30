<?php

namespace Tests\Support\Concerns;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Config;

/**
 * RV-16 — helper for tests that boot the real AppServiceProvider under a chosen
 * APP_ENV.
 *
 * WHY THIS EXISTS
 *
 * `AppServiceProvider::boot()` now runs `guardOtpTestingModes()`, which refuses to
 * boot when `EMAIL_OTP_MODE=testing`, `WALLET_OTP_MODE=testing` or
 * `OTP_BYPASS_ENABLED=true` outside local/testing (see section 22).
 *
 * The committed `phpunit.xml` deliberately sets two of those for the suite:
 * `WALLET_OTP_MODE=testing` and `OTP_BYPASS_ENABLED=true`,
 *
 * so ANY test that simulated `APP_ENV=production` and booted the provider would trip
 * the guard — and the guard is right to: a production deploy carrying these variables
 * publishes every OTP the app issues.
 *
 * A production boot simulation is therefore only realistic if the OTP testing modes
 * are absent, because they are absent in production by definition. This trait clears
 * them for the duration of the simulated boot and restores them afterwards, so a test
 * about a DIFFERENT guard (Pusher credentials, the queue driver) exercises that guard
 * without being blocked by an unrelated, correctly-enforced one.
 *
 * This does not weaken any assertion: it changes the environment being simulated, not
 * what is asserted.
 *
 * Clearing goes through `Env::getRepository()`, because `env()` resolves from the
 * Dotenv repository loaded once at boot — neither `putenv()` nor unsetting
 * $_ENV/$_SERVER actually removes a value there (both were tried and failed).
 */
trait SimulatesProductionBoot
{
    /** The variables guardOtpTestingModes() refuses to see outside local/testing. */
    private array $rv16SavedOtpEnv = [];

    private function rv16OtpEnvNames(): array
    {
        return ['EMAIL_OTP_MODE', 'WALLET_OTP_MODE', 'OTP_BYPASS_ENABLED'];
    }

    /**
     * Boot the real AppServiceProvider with the OTP testing modes removed.
     */
    private function bootProviderWithoutOtpTestingModes(string $env): void
    {
        // RV-37: the guard now reads config('otp.*'), not getenv(), so this is a plain
        // Config::set. The previous version had to clear the value in THREE places at
        // once (the Dotenv repository, $_ENV/$_SERVER, and the live process
        // environment) because a partial clear silently did nothing — each partial
        // attempt was caught by re-running the boot tests.
        foreach ($this->safeOtpConfig() as $key => $value) {
            $this->rv37SavedOtpConfig[$key] = Config::get($key);
            Config::set($key, $value);
        }

        try {
            $this->app['env'] = $env;
            (new AppServiceProvider($this->app))->boot();
        } finally {
            $this->restoreOtpTestingModes();
        }
    }

    /**
     * The values a real production deploy would have.
     */
    private function safeOtpConfig(): array
    {
        return [
            'otp.email_mode' => 'production',
            'otp.wallet_mode' => 'production',
            'otp.bypass' => false,
        ];
    }

    /**
     * Restore whatever was there before, so one test cannot poison the next.
     */
    private function restoreOtpTestingModes(): void
    {
        foreach ($this->rv37SavedOtpConfig as $key => $value) {
            Config::set($key, $value);
        }

        $this->rv37SavedOtpConfig = [];
    }
}
