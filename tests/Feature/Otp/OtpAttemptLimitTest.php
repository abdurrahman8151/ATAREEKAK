<?php

namespace Tests\Feature\Otp;

use App\Interfaces\OtpRepositoryInterface;
use App\Models\Otp;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T2-3 regression — the 3-attempt cap on an OTP must actually be enforced.
 *
 * Before the fix, every verify path located the code with
 * OtpRepository::findByPhoneAndCode(), i.e. the query filtered on the *guessed*
 * value. A wrong guess therefore matched no row and returned null, so
 * incrementAttempts() was never reached — it had zero call sites in the entire
 * codebase. Otp::isValid() and scopeActive() both gated on `attempts < 3`, but
 * `attempts` stayed 0 forever, so a 6-digit code remained brute-forceable for
 * its full 10-minute lifetime.
 *
 * The fix separates "find the live code" from "compare the guess": the row is
 * fetched by identifier only, the guess is compared with hash_equals(), and a
 * mismatch increments `attempts` and burns the code at the cap.
 *
 * Two identifiers are in play and they are NOT interchangeable:
 *   - the phone-based flows (/api/otp/*) key `phone_number` to the normalised
 *     form '+963983337214';
 *   - the email-based flows (/api/auth/password/*) store the email address in
 *     the same `phone_number` column (see EmailOtpService::sendOtp()).
 */
class OtpAttemptLimitTest extends TestCase
{
    use RefreshDatabase;

    /** Normalised identifier used by the phone-based (WhatsApp) endpoints. */
    private const PHONE = '+963983337214';

    /** Raw form the client posts for the number above. */
    private const PHONE_RAW = '0983337214';

    /** Identifier used by the email-based password-reset flow. */
    private const EMAIL = 'otp-limit@example.com';

    private function otp(string $identifier, string $code = '123456', ?Carbon $expiresAt = null): Otp
    {
        return Otp::create([
            'phone_number' => $identifier,
            'otp_code'     => $code,
            'type'         => 'E-PAYMENT',
            'expires_at'   => $expiresAt ?? Carbon::now()->addMinutes(10),
            'is_verified'  => false,
            'attempts'     => 0,
        ]);
    }

    /** A live code for the phone-based endpoint. */
    private function phoneOtp(string $code = '123456', ?Carbon $expiresAt = null): Otp
    {
        return $this->otp(self::PHONE, $code, $expiresAt);
    }

    private function guess(string $code)
    {
        return $this->postJson('/api/otp/verify', [
            'phone_number' => self::PHONE_RAW,
            'otp_code'     => $code,
        ]);
    }

    // ── The core defect: attempts must be recorded ─────────────────────────

    public function test_a_wrong_guess_is_recorded_against_the_code(): void
    {
        $otp = $this->phoneOtp();

        $this->guess('999999')
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        // Pre-fix this was 0: the wrong code matched no row, so nothing was counted.
        $this->assertSame(1, $otp->fresh()->attempts);
    }

    public function test_attempts_accumulate_across_repeated_wrong_guesses(): void
    {
        $otp = $this->phoneOtp();

        foreach (['111111', '222222', '333333'] as $code) {
            $this->guess($code)->assertStatus(400);
        }

        $this->assertSame(3, $otp->fresh()->attempts);
    }

    /**
     * The point of the whole exercise: after the cap the attacker is locked out,
     * even if they subsequently supply the CORRECT code.
     */
    public function test_the_code_is_burned_after_the_attempt_cap(): void
    {
        $otp = $this->phoneOtp();

        for ($i = 0; $i < Otp::MAX_ATTEMPTS; $i++) {
            $this->guess('999999')->assertStatus(400);
        }

        $this->assertSame(Otp::MAX_ATTEMPTS, $otp->fresh()->attempts);
        $this->assertFalse($otp->fresh()->isValid());

        // The right code no longer works — brute force has been shut down.
        $this->guess('123456')
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertFalse((bool) $otp->fresh()->is_verified);
    }

    // ── Correct behaviour preserved ────────────────────────────────────────

    public function test_the_correct_code_still_verifies_on_the_first_try(): void
    {
        $otp = $this->phoneOtp();

        $this->guess('123456')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $otp = $otp->fresh();
        $this->assertTrue((bool) $otp->is_verified);
        $this->assertSame(0, $otp->attempts, 'A successful attempt must not be counted as a failure.');
    }

    public function test_a_correct_code_still_works_below_the_cap(): void
    {
        $otp = $this->phoneOtp();

        // Two misses, then the right code — still within the allowance.
        $this->guess('999999')->assertStatus(400);
        $this->guess('888888')->assertStatus(400);

        $this->guess('123456')->assertStatus(200)->assertJsonPath('success', true);

        $this->assertTrue((bool) $otp->fresh()->is_verified);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $otp = $this->phoneOtp('123456', Carbon::now()->subMinute());

        $this->guess('123456')
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertFalse((bool) $otp->fresh()->is_verified);
    }

    public function test_a_verified_code_cannot_be_replayed(): void
    {
        $this->phoneOtp();

        $this->guess('123456')->assertStatus(200);

        $this->guess('123456')
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    // ── The password-reset flow named in the audit ─────────────────────────

    private function resetOtp(string $code = '123456'): Otp
    {
        return Otp::create([
            'phone_number' => self::EMAIL,
            'otp_code'     => $code,
            'type'         => 'password_reset',
            'expires_at'   => Carbon::now()->addMinutes(10),
            'is_verified'  => false,
            'attempts'     => 0,
        ]);
    }

    private function verifyReset(string $code)
    {
        return $this->postJson('/api/auth/password/verify-otp', [
            'email'    => self::EMAIL,
            'otp_code' => $code,
        ]);
    }

    public function test_the_password_reset_otp_is_brute_force_protected(): void
    {
        User::factory()->create(['email' => self::EMAIL, 'status' => 1]);

        $otp = $this->resetOtp();

        // Exhaust the allowance through the real endpoint.
        foreach (['111111', '222222', '333333'] as $code) {
            $this->verifyReset($code)->assertStatus(400);
        }

        $this->assertSame(3, $otp->fresh()->attempts);

        // No reset_token may be issued for the correct code afterwards.
        $this->verifyReset('123456')
            ->assertStatus(400)
            ->assertJsonMissingPath('reset_token');
    }

    public function test_the_password_reset_flow_still_succeeds_within_the_cap(): void
    {
        User::factory()->create(['email' => self::EMAIL, 'status' => 1]);

        $this->resetOtp();

        $this->verifyReset('123456')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['reset_token']);
    }

    public function test_a_wrong_password_reset_guess_is_also_recorded(): void
    {
        User::factory()->create(['email' => self::EMAIL, 'status' => 1]);

        $otp = $this->resetOtp();

        $this->verifyReset('999999')->assertStatus(400);

        $this->assertSame(1, $otp->fresh()->attempts);
    }

    // ── Repository/model contract ──────────────────────────────────────────

    public function test_the_latest_lookup_ignores_the_guess_and_prefers_the_newest_row(): void
    {
        $repo = app(OtpRepositoryInterface::class);

        $this->phoneOtp('111111');
        $newest = $this->phoneOtp('222222');

        // A code that matches NOTHING must still return the live row, so that a
        // failed attempt can be recorded against it.
        $found = $repo->findLatestByPhone(self::PHONE);

        $this->assertNotNull($found);
        $this->assertSame($newest->id, $found->id);
        $this->assertFalse($found->matchesCode('999999'));
        $this->assertTrue($found->matchesCode('222222'));
    }

    public function test_matches_code_is_exact(): void
    {
        $otp = $this->phoneOtp();

        $this->assertTrue($otp->matchesCode('123456'));
        $this->assertFalse($otp->matchesCode('123457'));
        $this->assertFalse($otp->matchesCode('12345'));
        $this->assertFalse($otp->matchesCode('1234567'));
        $this->assertFalse($otp->matchesCode(''));
    }

    public function test_register_failed_attempt_reports_when_the_code_is_burned(): void
    {
        $otp = $this->phoneOtp();

        // MAX_ATTEMPTS is 3: still allowed after 1 and 2, burned on the 3rd.
        $this->assertTrue($otp->registerFailedAttempt());
        $this->assertTrue($otp->registerFailedAttempt());
        $this->assertFalse($otp->registerFailedAttempt());

        $this->assertSame(Otp::MAX_ATTEMPTS, $otp->fresh()->attempts);
        $this->assertFalse($otp->fresh()->isValid());
    }
}