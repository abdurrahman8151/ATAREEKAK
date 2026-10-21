<?php

namespace Tests\Feature\Review;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Config;
use Tests\Support\Concerns\SimulatesProductionBoot;
use Tests\TestCase;

/**
 * Characterization of the two boot guards in AppServiceProvider (guardJwtSecret, guardOtpTestingModes),
 * pinned on the UNCHANGED provider before the guards are moved.
 *
 * Neither refusal was pinned by any test: EnvironmentGuardsBatchTest covers only the queue and docs guards,
 * and SimulatesProductionBoot clears the OTP variables precisely so the OTP guard does NOT fire. These tests
 * assert the refusal itself, on the real boot() path, under a simulated production environment.
 *
 * Only configuration is set (jwt.secret, otp.*); the provider is the real one. Nothing is mocked.
 */
class BootGuardsRefusalCharacterizationTest extends TestCase
{
    use SimulatesProductionBoot;

    public function test_production_boot_refuses_a_short_jwt_secret(): void
    {
        Config::set('queue.default', 'redis');
        $this->bootProviderWithoutOtpTestingModes('production');
        Config::set('jwt.secret', 'too-short');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_SECRET must be set to at least 32 bytes outside local/testing');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_production_boot_refuses_a_testing_otp_mode(): void
    {
        Config::set('queue.default', 'redis');
        $this->bootProviderWithoutOtpTestingModes('production');
        Config::set('jwt.secret', str_repeat('k', 40));
        Config::set('otp.email_mode', 'testing');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to start with a testing OTP mode enabled outside local/testing');

        (new AppServiceProvider($this->app))->boot();
    }
}
