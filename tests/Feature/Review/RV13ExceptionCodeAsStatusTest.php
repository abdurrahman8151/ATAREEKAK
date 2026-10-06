<?php

namespace Tests\Feature\Review;

use App\Exceptions\Domain\BusinessRuleViolation;
use App\Exceptions\Domain\ConflictViolation;
use App\Exceptions\Domain\DomainException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRequest;
use App\Services\Wallet\WalletRequestService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * RV-13(b) - an exception CODE is not an HTTP status.
 *
 * R2 sec 68. `WalletRequestService` threw PHP's SPL `\DomainException` and put the HTTP status in
 * the integer `$code`; `WalletRequestController` read it back with `$e->getCode() ?: 422`. That
 * worked by coincidence and broke two ways:
 *
 *  - `$code` defaults to **0**, so any throw that forgot it silently became 422 via the `?:`. The
 *    status was being *guessed*, so a rule that should have answered 403 or 404 would answer 422
 *    and nothing would look wrong.
 *  - `$code` is arbitrary. A throw using a non-HTTP code - a SQLSTATE-derived number, a domain
 *    code, anything - hands that number straight to `response()->json($payload, $status)` and
 *    produces a status no client understands.
 *
 * The status belongs to the exception TYPE, which is what `App\Exceptions\Domain\*` exists for.
 *
 * The ratchet below is at **0** and is not a "reduce it a bit" ratchet: the whole defect is that
 * the channel exists at all, so one surviving site is a bug, not progress.
 */
class RV13ExceptionCodeAsStatusTest extends TestCase
{
    use RefreshDatabase;

    // -- The ratchet -----------------------------------------------------------------

    /** @test */
    public function no_controller_uses_an_exception_code_as_an_http_status(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(app_path('Http/Controllers')) as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
                if ($this->isOffendingLine($line)) {
                    $offenders[] = $this->relative($file).':'.($number + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "An exception code is not an HTTP status (RV-13 sec 68). Offenders:\n".implode("\n", $offenders)
        );
    }

    /**
     * The ratchet above is only worth having if it can actually SEE a violation - a ratchet whose
     * pattern stopped matching would pass forever. This proves it catches the exact old string and
     * ignores the fixed one, without needing a file on disk.
     *
     * @test
     */
    public function the_ratchet_would_actually_catch_the_old_anti_pattern(): void
    {
        $this->assertTrue(
            $this->isOffendingLine('            ], $e->getCode() ?: 422);'),
            'the ratchet must flag the pattern it was written for'
        );
        $this->assertFalse(
            $this->isOffendingLine('            ], $e->httpStatus);'),
            'the fixed form must not be flagged, or the ratchet would fail the whole codebase'
        );
        $this->assertFalse(
            $this->isOffendingLine('            // `$e->getCode() ?: 422` was removed - the code is not a status.'),
            'a comment explaining the fix must not be flagged'
        );
    }

    // -- The behaviour the endpoints must keep ---------------------------------------

    /**
     * Status preservation stated as a test rather than an assumption: before this change
     * `$e->getCode() ?: 422` returned 422 for a missing wallet and 409 for a duplicate pending
     * charge. If either number moves, a client changes behaviour, so both are pinned.
     *
     * @test
     */
    public function a_missing_wallet_is_still_422(): void
    {
        $user = User::factory()->create();
        $user->wallet()->delete();
        $user->unsetRelation('wallet');

        try {
            app(WalletRequestService::class)->requestCharge($user->fresh(), 100.0);
            $this->fail('expected a domain violation');
        } catch (DomainException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('WALLET_NOT_FOUND', $e->errorCode);
        }
    }

    /** @test */
    public function a_duplicate_pending_charge_is_still_409(): void
    {
        $user = User::factory()->create();
        $wallet = $this->ensureWallet($user);

        WalletRequest::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => 'charge',
            'amount' => 100,
            'status' => 'pending',
        ]);

        try {
            app(WalletRequestService::class)->requestCharge($user->fresh(), 100.0);
            $this->fail('expected a domain violation');
        } catch (DomainException $e) {
            $this->assertSame(409, $e->httpStatus, 'the 409 must survive the channel change');
            $this->assertSame('PENDING_CHARGE_EXISTS', $e->errorCode);
            $this->assertInstanceOf(ConflictViolation::class, $e);
        }
    }

    /**
     * The defect in its purest form: a throw with NO code. Under the old pattern that silently
     * became 422. Under the new one the exception carries its own answer, and - this is the point -
     * a `code` of 0 can no longer be mistaken for a status at all.
     *
     * @test
     */
    public function an_exception_code_of_zero_can_never_be_read_as_a_status(): void
    {
        $violation = new BusinessRuleViolation('nope', 'SOME_RULE');

        $this->assertSame(0, $violation->getCode(), 'the SPL code is untouched and still 0');
        $this->assertSame(422, $violation->httpStatus, 'the status comes from the type instead');

        // The two channels are now genuinely independent, which is the whole point.
        $this->assertNotSame($violation->getCode(), $violation->httpStatus);
    }

    /**
     * Every exception this service can raise must carry a REAL HTTP status. The old pattern had no
     * such guarantee - that is precisely the invariant it could not state.
     *
     * @test
     */
    public function every_domain_status_this_service_can_produce_is_a_real_http_status(): void
    {
        $source = (string) file_get_contents(app_path('Services/Wallet/WalletRequestService.php'));

        preg_match_all('/throw new (\w+)\(/', $source, $matches);
        $classes = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($classes, 'sanity: the service still throws domain exceptions');

        // A framework exception the service legitimately raises is not a domain rule violation, so
        // it is allowed to stay outside the hierarchy. Anything else must carry its own httpStatus.
        // Compared by SHORT name, because the regex above captured only the class basename.
        $frameworkThrows = [basename(str_replace('\\', '/', ModelNotFoundException::class))];

        foreach ($classes as $short) {
            if (in_array($short, $frameworkThrows, true)) {
                continue;
            }

            $fqcn = 'App\\Exceptions\\Domain\\'.$short;

            $this->assertTrue(class_exists($fqcn), "{$short} must be part of the domain hierarchy");
            $this->assertTrue(
                is_subclass_of($fqcn, DomainException::class),
                "{$short} must carry its own httpStatus"
            );

            $status = (new $fqcn('x', 'X'))->httpStatus;
            $this->assertContains(
                $status,
                [400, 401, 403, 404, 409, 422, 423, 429],
                "{$short} carries {$status}, which is not a usable HTTP status"
            );
        }
    }

    /**
     * The service must no longer throw PHP's SPL `\DomainException`, which is a DIFFERENT class from
     * this app's hierarchy - it was the reason the status had to ride in an integer `$code`.
     *
     * @test
     */
    public function the_service_no_longer_throws_the_spl_domain_exception(): void
    {
        $source = (string) file_get_contents(app_path('Services/Wallet/WalletRequestService.php'));

        foreach (explode("\n", $source) as $number => $line) {
            if ($this->isCommentOrBlank($line)) {
                continue;
            }

            $this->assertStringNotContainsString(
                'new \\DomainException',
                $line,
                'line '.($number + 1).' still throws the SPL \\DomainException'
            );
        }
    }

    // -- Helpers ---------------------------------------------------------------------

    private function isOffendingLine(string $line): bool
    {
        if ($this->isCommentOrBlank($line)) {
            return false;
        }

        return str_contains($line, 'getCode()');
    }

    /**
     * Same fixture as `WalletRequestControllerTest::ensureWallet`. A wallet request cannot exist
     * without a wallet (`wallet_requests.wallet_id` is NOT NULL), so a user who is supposed to
     * HOLD a request has to be given one first.
     */
    private function ensureWallet(User $user): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'phone_number' => '09'.random_int(10000000, 99999999),
                'wallet_number' => 'WLT-'.Str::random(8),
                'balance' => 0,
            ]
        );
    }

    /**
     * Comments are skipped on purpose. Several controllers carry a comment explaining that they
     * REMOVED this pattern, and counting the explanation would make the ratchet fail on the record
     * of its own fix. Only code counts.
     */
    private function isCommentOrBlank(string $line): bool
    {
        $trimmed = ltrim($line);

        if ($trimmed === '') {
            return true;
        }

        return str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '#')
            || str_starts_with($trimmed, '*');
    }

    /** @return list<string> */
    private function phpFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
