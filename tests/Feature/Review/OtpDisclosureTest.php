<?php

namespace Tests\Feature\Review;

use App\Domain\ValueObjects\Email;
use App\DTOs\Auth\SendEmailOtpDTO;
use App\Providers\AppServiceProvider;
use App\Services\EmailOtpService;
use App\Services\TextMeBotOtpService;
use App\Support\OtpDisclosure;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * RV-16 — a live OTP must never be disclosed outside local/testing.
 *
 * Three services could disclose a code, under three unrelated conditions:
 *   - `EmailOtpService`    whenever `EMAIL_OTP_MODE=testing`, with no environment
 *                          check at all — one misconfigured variable published every
 *                          signup and password-reset code.
 *   - `WhatsAppOtpService` whenever `WALLET_OTP_MODE=testing`.
 *   - `TextMeBotOtpService` when the provider API key is unset, AND when sending
 *                          FAILS. The failure path is the worst: any transient
 *                          provider outage published every code.
 *
 * An OTP is a single-factor credential, so disclosing it lets the holder complete
 * signup, password reset or a wallet top-up.
 *
 * These tests assert BOTH directions: denied outside development, still delivered in
 * local/testing so the suite keeps working.
 */
class OtpDisclosureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RV-37: config, not putenv(). The OTP modes are read from config('otp.*')
        // now, so a test overrides them with Config::set — which the framework rebuilds
        // per test, so the override cannot leak into a neighbouring test. The previous
        // putenv() version leaked for the rest of the PHP process, which is exactly the
        // order-dependence V14 measured.
        Config::set('otp.email_mode', 'testing');
        Config::set('otp.wallet_mode', 'testing');
        Config::set('otp.bypass', true);
    }

    protected function tearDown(): void
    {
        Config::set('otp.email_mode', 'production');
        Config::set('otp.wallet_mode', 'production');
        Config::set('otp.bypass', false);

        parent::tearDown();
    }

    private function pretendProduction(): void
    {
        $this->app['env'] = 'production';
    }

    /** @test */
    public function disclosure_is_allowed_in_testing_so_the_suite_still_works(): void
    {
        $this->assertTrue(
            OtpDisclosure::isAllowed(),
            'the testing environment must keep receiving codes, otherwise the whole suite breaks'
        );
    }

    /** @test */
    public function disclosure_is_denied_in_production_even_when_the_mode_variable_is_set(): void
    {
        $this->pretendProduction();

        $this->assertFalse(
            OtpDisclosure::isAllowed(),
            'EMAIL_OTP_MODE=testing must not be enough to disclose codes in production'
        );
    }

    /** @test */
    public function sanitize_strips_the_code_in_production_but_keeps_the_rest_of_the_result(): void
    {
        $this->pretendProduction();

        $result = OtpDisclosure::sanitize([
            'success' => true,
            'message' => 'OTP generated (testing mode).',
            'otp_code' => '123456',
            'expires_at' => '2030-01-01 00:00:00',
        ]);

        $this->assertArrayNotHasKey('otp_code', $result);
        // Stripping the code must not break the caller: success/message still travel.
        $this->assertTrue($result['success']);
        $this->assertSame('OTP generated (testing mode).', $result['message']);
        $this->assertArrayHasKey('expires_at', $result);
    }

    /** @test */
    public function sanitize_keeps_the_code_in_testing(): void
    {
        $result = OtpDisclosure::sanitize(['success' => true, 'otp_code' => '123456']);

        $this->assertSame('123456', $result['otp_code']);
    }

    /** @test */
    public function email_otp_service_does_not_return_the_code_in_production(): void
    {
        $this->pretendProduction();

        // The mode variable is set (setUp), which is precisely the misconfiguration
        // this guards against.
        $this->assertSame('testing', config('otp.email_mode'));

        $result = app(EmailOtpService::class)->sendOtp(
            new SendEmailOtpDTO(
                Email::from('rv16@example.com'),
                'Rv16',
                'EMAIL_VERIFICATION'
            )
        );

        $this->assertArrayNotHasKey(
            'otp_code',
            $result,
            'RV-16: EmailOtpService leaked a live code while EMAIL_OTP_MODE=testing in production'
        );
    }

    /**
     * The "provider not configured" branch — which also returned the code.
     *
     * Uses the REAL service rather than a stand-in, so the leak path is exercised.
     *
     * The key is cleared through Laravel's Env REPOSITORY, not putenv() and not
     * $_ENV/$_SERVER. `env()` resolves through `Env::get()` -> the Dotenv repository,
     * whose values are loaded once at boot; a later putenv() does NOT reach it, and
     * unsetting $_ENV/$_SERVER does not either (both were tried — the precondition
     * assertion caught the second). The repository's own `clear()` is what actually
     * removes it.
     *
     * With no key configured the service never opens a connection, so this is both the
     * leak path and a hermetic test.
     *
     * @test
     */
    public function textme_bot_service_does_not_return_the_code_in_production_when_unconfigured(): void
    {
        $this->pretendProduction();

        // RV-37: the provider key is now read from config, so Config::set is enough
        // to simulate "not configured". This is the fix for the live-call incident
        // recorded in section 22.3: the previous version cleared the value with
        // putenv()/Env::getRepository(), but putenv never reaches the repository env()
        // reads, so the real key survived and this test called the LIVE provider.
        $original = config('services.textmebot.api_key');
        Config::set('services.textmebot.api_key', null);

        try {
            $this->assertNull(
                config('services.textmebot.api_key'),
                'precondition: the provider key must be absent for the unconfigured branch'
            );

            $result = app(TextMeBotOtpService::class)->sendOtp('0912345678', 'REGISTRATION');

            $this->assertArrayNotHasKey(
                'otp_code',
                $result,
                'RV-16: TextMeBotOtpService returned a live code when unconfigured in production'
            );
        } finally {
            if ($original !== null) {
                Config::set('services.textmebot.api_key', $original);
            }
        }
    }

    /**
     * Defence in depth: a deployment carrying a testing OTP mode must fail to boot
     * rather than start and leak codes silently.
     *
     * OtpDisclosure already refuses to return codes outside local/testing, so this
     * guard is about the OTHER failure mode — a misconfigured deploy that looks
     * healthy until someone notices the variable. Exposed as its own test because
     * it is a different mechanism (a boot guard, like RV-04's JWT secret guard).
     *
     * @test
     */
    public function the_boot_guard_refuses_a_testing_otp_mode_in_production(): void
    {
        $this->pretendProduction();

        $provider = new AppServiceProvider($this->app);
        $guard = new \ReflectionMethod($provider, 'guardOtpTestingModes');
        $guard->setAccessible(true);

        // With the testing mode set (setUp) and env=production, booting must throw.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to start with a testing OTP mode/');

        $guard->invoke($provider);
    }

    /**
     * The same guard must NOT fire in testing, or nothing could run.
     *
     * @test
     */
    public function the_boot_guard_stays_quiet_in_testing(): void
    {
        $provider = new AppServiceProvider($this->app);
        $guard = new \ReflectionMethod($provider, 'guardOtpTestingModes');
        $guard->setAccessible(true);

        $this->assertSame('testing', app()->environment());
        $guard->invoke($provider); // must not throw

        $this->addToAssertionCount(1);
    }
}
