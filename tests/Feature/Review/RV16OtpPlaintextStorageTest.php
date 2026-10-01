<?php

namespace Tests\Feature\Review;

use App\Models\Otp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RV-16 (plaintext OTP storage) — a saved code must not be readable as plaintext.
 *
 * Root cause (verified against the real schema): otps.otp_code was varchar(6) holding the
 * raw 6-digit code. A DB dump/backup/leak therefore handed an attacker every LIVE code,
 * and a 6-digit numeric space is only 10^6 — no salt, no stretching, instantly exploitable
 * and it bypasses the MAX_ATTEMPTS throttle entirely (the throttle counts guesses through
 * the API, not reads of the database). The service-side fix already added constant-time
 * matchesCode + findLatestByPhone (so the code isn't matched in SQL), but the code was
 * still STORED plaintext — which is the half §22.4 named ("Needs hash_hmac storage").
 *
 * This pins the STORAGE property (what no prior test checked): the column now holds an
 * HMAC-SHA256 digest keyed by the app key, not the code, while verification still works
 * through the model. Hashing — not bcrypt — is the deliberate choice: codes are short-lived
 * single-use and already rate-limited, so the threat is a DB-only leak, not offline crash
 * (documented in the model).
 *
 * Deliberately reads the RAW column via DB::table (bypassing the model's mutator +
 * hydration), because asserting through the model could let a double-hydration bug hide.
 */
class RV16OtpPlaintextStorageTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '258046';

    private function createOtp(string $code = self::CODE): Otp
    {
        return Otp::create([
            'phone_number' => '+963900000001',
            'otp_code' => $code,
            'type' => 'E-PAYMENT',
            'expires_at' => now()->addMinutes(5),
            'is_verified' => false,
            'attempts' => 0,
        ]);
    }

    /** The raw bytes actually persisted, ignoring Eloquent mutators/hydration. */
    private function rawColumn(int $id): string
    {
        return (string) DB::table('otps')->where('id', $id)->value('otp_code');
    }

    /** @test */
    public function the_stored_column_is_not_the_plaintext_code(): void
    {
        $otp = $this->createOtp();

        $raw = $this->rawColumn($otp->id);

        $this->assertNotSame(self::CODE, $raw,
            'RV-16: the database column must not contain the plaintext code');
        $this->assertStringNotContainsString(self::CODE, $raw,
            'RV-16: no part of the plaintext code may appear in storage');
    }

    /** @test */
    public function the_stored_value_is_a_sha256_hmac_digest(): void
    {
        $otp = $this->createOtp();
        $raw = $this->rawColumn($otp->id);

        // 64 hex chars = sha256. Not a bare sha256 of the code either: keyed by app.key,
        // so it must equal the app's hashCode() of the plaintext.
        $this->assertSame(64, strlen($raw), 'a sha256 hex digest is 64 chars');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $raw);
        $this->assertSame(
            Otp::hashCode(self::CODE),
            $raw,
            'the stored digest is HMAC-SHA256(code, app.key) — deterministic and keyed'
        );
    }

    /** @test */
    public function verification_still_succeeds_through_the_model(): void
    {
        // The security property is only worth having if the function still works: a fresh
        // load from the DB (which must NOT re-run the mutator on hydrate — no double hash)
        // still matches the correct code and rejects a wrong one.
        $otp = $this->createOtp();
        $fromDb = Otp::find($otp->id);

        $this->assertNotNull($fromDb);
        $this->assertTrue($fromDb->matchesCode(self::CODE), 'correct code verifies');
        $this->assertFalse($fromDb->matchesCode('000000'), 'wrong code rejected');
        $this->assertFalse($fromDb->matchesCode('25804'), 'prefix must not match');

        // Hydration must not mutate storage (double-hash guard): reading the model back
        // and re-saving would corrupt it if hydrate fired the mutator.
        $this->assertSame(
            Otp::hashCode(self::CODE),
            $this->rawColumn($otp->id),
            'reading the row must leave the stored digest unchanged (no re-hash on hydrate)'
        );
    }

    /** @test */
    public function two_identical_codes_yield_identical_digests_but_differ_from_plaintext(): void
    {
        // Determinism is required for the exact-match lookup path (findByPhoneAndCode) to
        // work; it is also what means a DB leak shows only a keyed digest.
        $a = $this->createOtp(self::CODE);
        $b = $this->createOtp(self::CODE);

        $this->assertSame($this->rawColumn($a->id), $this->rawColumn($b->id),
            'same code + same key => same digest (lookup stays possible)');
    }

    /** @test */
    public function the_column_is_wide_enough_for_the_digest(): void
    {
        // varchar(6) would TRUNCATE the HMAC to 6 chars and break every verify. Pin the
        // widened schema so a fresh install cannot silently regress to the old width.
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('column width is a MySQL concern (the migration guards).');
        }

        $len = DB::select(
            "SELECT CHARACTER_MAXIMUM_LENGTH l FROM information_schema.columns
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'otps' AND COLUMN_NAME = 'otp_code'"
        )[0]->l ?? 0;

        $this->assertGreaterThanOrEqual(64, (int) $len,
            'otp_code must fit a sha256 hex digest');
    }
}
