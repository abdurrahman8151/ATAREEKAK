<?php

namespace Tests\Feature\Review;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * RV-22 — Google OAuth callback must never write credentials to the log.
 *
 * Root cause (verified in code): callback() opened with three debug-era Log::info lines
 * that wrote `$request->all()` (containing the OAuth authorization `code` AND the CSRF
 * `state`) and then logged `code` and `state` again individually. A Google authorization
 * code is single-use but live for its TTL and exchangeable for access/refresh tokens —
 * anyone who can read storage/logs (log shipped to an aggregator, a shared dev box, the
 * non-rotating file shared by 5 replicas that R2 warns about) could complete or replay a
 * victim's login. "Logs = credential store" is exactly the hygiene class RV-22 closes.
 *
 * The assertion is against the REAL log file, not a Log spy: a spy only sees the call it
 * watches, while any other path that logs the same request would escape it. A sentinel
 * line is written and asserted present first, so an empty sink can never masquerade as
 * a passing "nothing was leaked" assertion (that would be a vacuous test — caught twice
 * this session with causality needles, so prevented by construction here).
 */
class RV22OauthCredentialRedactionTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;

    private const SECRET_CODE = '4/0A_SECRET_AUTH_CODE_z9x8q1';

    private const SECRET_STATE = 'csrf_STATE_abcdef123567';

    protected function setUp(): void
    {
        parent::setUp();

        // Unique per test: Monolog keeps the stream handle open for the app's lifetime,
        // and on Windows an open file cannot be unlinked — a shared path across two tests
        // deadlocks the second one's append.
        $this->logPath = storage_path('logs/rv22-'.microtime(true).' '.mt_rand().'.log');

        config(['logging.default' => 'rv22']);
        config(['logging.channels.rv22' => [
            'driver' => 'single',
            'path' => $this->logPath,
            'level' => 'debug',
        ]]);
    }

    protected function tearDown(): void
    {
        clearstatcache();
        @unlink($this->logPath);
        Mockery::close();
        parent::tearDown();
    }

    private function sink(): string
    {
        clearstatcache();

        return is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';
    }

    /** Prove the channel under test is actually capturing writes before trusting it. */
    private function armSink(): void
    {
        Log::info('RV22-SENTINEL');
        $this->assertStringContainsString('RV22-SENTINEL', $this->sink(),
            'the log sink under test must be functional before asserting absence');
    }

    private function mockDriver(?SocialiteUser $user = null, ?\Throwable $throw = null): void
    {
        $driver = Mockery::mock(GoogleProvider::class);
        $driver->shouldReceive('setHttpClient')->andReturnSelf();

        if ($throw !== null) {
            $driver->shouldReceive('user')->andThrow($throw);
        } else {
            $driver->shouldReceive('user')->andReturn($user);
        }

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    private function socialiteUser(): SocialiteUser
    {
        $u = Mockery::mock(SocialiteUser::class);
        $u->shouldReceive('getId')->andReturn('rv22-uid');
        $u->shouldReceive('getEmail')->andReturn('rv22@gmail.com');
        $u->shouldReceive('getName')->andReturn('RV TwentyTwo');
        $u->shouldReceive('getAvatar')->andReturn('https://example.com/a.jpg');
        $u->user = ['given_name' => 'RV', 'family_name' => 'TwentyTwo'];

        return $u;
    }

    /** @test */
    public function a_successful_callback_never_writes_the_code_or_state_to_logs(): void
    {
        $this->armSink();
        $this->mockDriver($this->socialiteUser());

        $response = $this->get('/auth/google/callback?code='.self::SECRET_CODE
            .'&state='.self::SECRET_STATE);

        // Control: the flow genuinely ran, so absence below is not an empty-test artifact.
        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('users', ['email' => 'rv22@gmail.com']);

        $sink = $this->sink();

        $this->assertStringNotContainsString(
            self::SECRET_CODE,
            $sink,
            'RV-22: the OAuth authorization code must never reach the log file'
        );
        $this->assertStringNotContainsString(
            self::SECRET_STATE,
            $sink,
            'RV-22: the CSRF state must never reach the log file'
        );
    }

    /** @test */
    public function the_invalid_state_path_logs_a_flag_not_the_state_value(): void
    {
        $this->armSink();
        $this->mockDriver(throw: new InvalidStateException('bad state'));

        $response = $this->get('/auth/google/callback?code='.self::SECRET_CODE
            .'&state='.self::SECRET_STATE);

        $response->assertStatus(401);

        $sink = $this->sink();

        // The warning genuinely fired (again: absence must be earned, not assumed).
        $this->assertStringContainsString('Invalid State', $sink);
        $this->assertStringContainsString('state_present', $sink);

        $this->assertStringNotContainsString(
            self::SECRET_STATE,
            $sink,
            'RV-22: even on mismatch the raw state can be a victim token; log presence only'
        );
        $this->assertStringNotContainsString(
            self::SECRET_CODE,
            $sink,
            'RV-22: the code must not be logged on the failure path either'
        );
    }
}
