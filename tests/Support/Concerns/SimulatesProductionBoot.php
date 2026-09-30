<?php

namespace Tests\Support\Concerns;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Env;

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
        $repository = Env::getRepository();
        $this->rv16SavedOtpEnv = [];

        foreach ($this->rv16OtpEnvNames() as $name) {
            // THREE places hold these values under PHPUnit, and ALL must be cleared —
            // PHPUnit's <env> entries populate every one of them:
            //  1. the live process environment (getenv), via putenv at startup;
            //  2. $_ENV and $_SERVER, which PHPUnit also fills in;
            //  3. the Dotenv repository, which is what env() reads.
            // guardOtpTestingModes() consults all three, so clearing only some of
            // them left the guard still firing (each partial fix was caught by
            // re-running the boot tests).
            $this->rv16SavedOtpEnv[$name] = [
                'getenv' => getenv($name),
                'env' => $_ENV[$name] ?? null,
                'server' => $_SERVER[$name] ?? null,
                'repo' => $repository->get($name),
            ];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            $repository->clear($name);
        }

        try {
            $this->app['env'] = $env;
            (new AppServiceProvider($this->app))->boot();
        } finally {
            $this->restoreOtpTestingModes();
        }
    }

    /**
     * Restore whatever was there before, so one test cannot poison the next.
     */
    private function restoreOtpTestingModes(): void
    {
        $repository = Env::getRepository();

        foreach ($this->rv16SavedOtpEnv as $name => $saved) {
            if ($saved['getenv'] !== false && $saved['getenv'] !== null) {
                putenv($name.'='.$saved['getenv']);
            }
            if ($saved['env'] !== null) {
                $_ENV[$name] = $saved['env'];
            }
            if ($saved['server'] !== null) {
                $_SERVER[$name] = $saved['server'];
            }
            if ($saved['repo'] !== null && $saved['repo'] !== false) {
                $repository->set($name, $saved['repo']);
            }
        }

        $this->rv16SavedOtpEnv = [];
    }
}
