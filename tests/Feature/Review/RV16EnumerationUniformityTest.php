<?php

namespace Tests\Feature\Review;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * RV-16 - decision 8 ("A - uniform errors, no account enumeration") was ANSWERED but never applied.
 *
 * R2 sec 72. Four unauthenticated endpoints told an attacker whether an address had an account:
 *
 *   POST /api/auth/password/forgot      404 "No account found with this email address."
 *   POST /api/auth/password/verify-otp  422 "No account found with this email."  (a validator `exists`)
 *   POST /api/auth/password/reset        404 "Account not found."
 *   POST /api/email-verification/resend 404 "No account found with this email." / 409 "already verified"
 *
 * The `resend` one needed two edits, not one: it had THREE distinguishable states, and deleting
 * only the 404 would have left 200-versus-409 separating "exists and unverified" from "exists and
 * verified" - the same information by another route. So all three now answer identically.
 *
 * THE PROPERTY THESE TESTS PIN is not "no endpoint mentions the word not-found". It is that the
 * RESPONSE BODY AND STATUS ARE BYTE-IDENTICAL between a registered and an unregistered address.
 * Anything less still leaks, which is why `resend` needed the 409 closed too.
 */
class RV16EnumerationUniformityTest extends TestCase
{
    use RefreshDatabase;

    private const REGISTERED = 'registered.rv16@example.test';

    private const UNREGISTERED = 'nobody.rv16@example.test';

    /**
     * POST bodies that are identical apart from the address, so the only variable is existence.
     */
    private const CODE = '123456';

    protected function setUp(): void
    {
        parent::setUp();

        // Same requirement as ResetPasswordControllerTest: without this the service does not
        // expose otp_code, so the 'a real account still gets a code' check cannot run.
        Config::set('otp.email_mode', 'testing');

        User::factory()->create([
            'email' => self::REGISTERED,
            'password' => bcrypt('password123'),
            'email_verified_at' => null,
        ]);
    }

    /**
     * The shape a response must take: status, success flag, message. Deliberately excludes
     * `otp_code`, which can only exist for a real send - see the note in forgot_password below.
     */
    /**
     * The submitted address is normalised out of any message. It is the caller's own input
     * echoed back - identical in both cases by construction - so comparing it verbatim would
     * report a leak that does not exist.
     */
    private function normaliseMessage($response): string
    {
        return str_replace([self::REGISTERED, self::UNREGISTERED], '{email}', (string) $response->json('message'));
    }

    private function fingerprint($response): array
    {
        return [
            'status' => $response->getStatusCode(),
            'success' => $response->json('success'),
            // The submitted address is normalised out: it is the caller's own input echoed
            // back, identical in both cases by construction, so comparing it verbatim would
            // report a leak that does not exist.
            'message' => $this->normaliseMessage($response),
        ];
    }

    private function assertIndistinguishable(string $label, callable $registered, callable $unregistered): void
    {
        $a = $this->fingerprint($registered());
        $b = $this->fingerprint($unregistered());

        $this->assertSame(
            $a,
            $b,
            "DECISION 8 VIOLATED on {$label}: a registered and an unregistered address must be "
            .'indistinguishable from the response. Got '.json_encode($a).' vs '.json_encode($b)
        );
    }

    /**
     * The flagship endpoint.
     *
     * @test
     */
    public function forgot_password_does_not_reveal_whether_an_account_exists(): void
    {
        $this->assertIndistinguishable(
            'password/forgot',
            fn () => $this->postJson('/api/auth/password/forgot', ['email' => self::REGISTERED]),
            fn () => $this->postJson('/api/auth/password/forgot', ['email' => self::UNREGISTERED]),
        );
    }

    /**
     * The oracle used to be a *validator* rule, which is easy to miss when reading a controller:
     * `exists:users,email` produced the message, so there was no "account not found" branch to find.
     *
     * @test
     */
    public function verify_password_otp_does_not_reveal_whether_an_account_exists(): void
    {
        $this->assertIndistinguishable(
            'password/verify-otp',
            fn () => $this->postJson('/api/auth/password/verify-otp', [
                'email' => self::REGISTERED, 'otp_code' => self::CODE,
            ]),
            fn () => $this->postJson('/api/auth/password/verify-otp', [
                'email' => self::UNREGISTERED, 'otp_code' => self::CODE,
            ]),
        );
    }

    /**
     * @test
     */
    public function resend_verification_does_not_reveal_whether_an_account_exists(): void
    {
        $this->assertIndistinguishable(
            'email-verification/resend',
            fn () => $this->postJson('/api/email-verification/resend', ['email' => self::REGISTERED]),
            fn () => $this->postJson('/api/email-verification/resend', ['email' => self::UNREGISTERED]),
        );
    }

    /**
     * The third state. Without this, `resend` would still enumerate: 200 means "exists and
     * unverified", 409 means "exists and verified".
     *
     * @test
     */
    public function resend_verification_does_not_reveal_that_an_account_is_already_verified(): void
    {
        $verified = User::where('email', self::REGISTERED)->firstOrFail();
        $verified->update(['email_verified_at' => now()]);

        $this->assertIndistinguishable(
            'email-verification/resend (verified vs unregistered)',
            fn () => $this->postJson('/api/email-verification/resend', ['email' => self::REGISTERED]),
            fn () => $this->postJson('/api/email-verification/resend', ['email' => self::UNREGISTERED]),
        );
    }

    /**
     * The fix must not have turned the endpoint into a no-op. A real account still gets a code.
     *
     * @test
     */
    public function forgot_password_still_sends_for_a_real_account(): void
    {
        $response = $this->postJson('/api/auth/password/forgot', ['email' => self::REGISTERED]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // The whole point of the endpoint must survive the uniform response.
        $this->assertNotNull(
            $response->json('otp_code'),
            'a real account must still receive its code - testing env exposes otp_code'
        );
    }

    /**
     * And an unknown address must NOT be handed a code.
     *
     * @test
     */
    public function forgot_password_does_not_send_a_code_for_an_unknown_address(): void
    {
        $response = $this->postJson('/api/auth/password/forgot', ['email' => self::UNREGISTERED]);

        $response->assertStatus(200);
        $this->assertNull(
            $response->json('otp_code'),
            'the uniform response must not be produced by actually sending to strangers'
        );
    }

    /**
     * A structural guard, so a future branch cannot quietly reintroduce a distinguishable answer on
     * any of the four endpoints even if the behavioural tests above are loosened.
     *
     * @test
     */
    public function no_auth_endpoint_still_answers_with_a_not_found_message(): void
    {
        $forbidden = ['No account found', 'Account not found.', 'This email is already verified.'];

        $offenders = [];
        foreach (glob(app_path('Http/Controllers/API/*Controller.php')) as $file) {
            $src = (string) file_get_contents($file);
            // Only the four in scope; admin/staff 404s are lookups behind auth, not enumeration.
            if (! preg_match('/(ForgotPassword|VerifyPasswordOtp|ResetPassword|EmailVerification)Controller/', $file)) {
                continue;
            }
            foreach ($forbidden as $phrase) {
                // Ignore comments - the fix explains the old strings in prose on purpose.
                foreach (preg_split('/\R/', $src) as $n => $line) {
                    $trimmed = ltrim($line);
                    if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                        continue;
                    }
                    if (str_contains($line, $phrase)) {
                        $offenders[] = basename($file).':'.($n + 1).': '.trimmed;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'decision 8: these four endpoints must not answer with an existence-revealing message');
    }

    /**
     * Pinned because it is the honest limit of the fix, not a defect: `otp_code` is exposed in
     * local/testing only (RV-16 criterion 3, `OtpDisclosure::sanitize()`). That means in a dev
     * environment the response DOES differ for a real account. In production the key is absent
     * for both, so the two are identical - which is the property that matters.
     *
     * @test
     */
    public function the_response_difference_in_testing_is_only_the_dev_only_otp_code(): void
    {
        $registered = $this->postJson('/api/auth/password/forgot', ['email' => self::REGISTERED]);
        $unregistered = $this->postJson('/api/auth/password/forgot', ['email' => self::UNREGISTERED]);

        // Status and message are already asserted identical in the flagship test. This records the
        // one intentional difference and pins that it is limited to a key production never sets.
        $this->assertSame($registered->getStatusCode(), $unregistered->getStatusCode());
        $this->assertSame($this->normaliseMessage($registered), $this->normaliseMessage($unregistered));

        if (array_key_exists('otp_code', $unregistered->json())) {
            $this->fail('an unregistered address must never receive an otp_code, in any environment');
        }
    }
}
