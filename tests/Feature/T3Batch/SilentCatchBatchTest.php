<?php

namespace Tests\Feature\T3Batch;

use App\Models\User;
use App\Models\Wallet;
use App\Services\NotificationService;
use App\Services\Wallet\WalletRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T3-13 — silent `catch (\Throwable) {}` sites must now surface failures.
 *
 * Two instruments:
 *  1. a structural sweep of app/Http, app/Services, app/Providers: every catch
 *     block whose body contains no code must be explicitly annotated
 *     "intentionally silent" (only the two per-request cache listeners are).
 *     This is the anti-reintroduction contract for the 27 sites the batch
 *     converted to Log::warning.
 *  2. one behavioural test proving a swallowed notification failure keeps the
 *     domain action intact AND lands a warning in the log (pre-fix: the
 *     failure vanished with no trace).
 */
class SilentCatchBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_undocumented_empty_catch_blocks_remain_in_application_code(): void
    {
        $offenders = [];

        foreach (['app/Http', 'app/Services', 'app/Providers'] as $tree) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($tree), \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') {
                    continue;
                }
                $src = file_get_contents($f->getPathname());
                foreach ($this->emptyCatchBodies($src) as $line) {
                    $offenders[] = substr($f->getPathname(), strlen(base_path('/') ?: base_path())).':'.$line;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'catch blocks with empty/comment-only bodies must carry the "intentionally silent" annotation (T3-13)'
        );
    }

    /**
     * Locate `catch (...) { ... }` whose body has no executable code.
     * Brace-counting, not regex, so nested structures can't fool it.
     *
     * @return list<int> 1-based line numbers of offending catch statements
     */
    private function emptyCatchBodies(string $src): array
    {
        $hits = [];
        $re = '/catch\s*\(([^)]*)\)\s*\{/';
        if (! preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($m[0] as $match) {
            $start = $match[1] + strlen($match[0]); // first char after '{'
            $depth = 1;
            $i = $start;
            $len = strlen($src);
            while ($i < $len && $depth > 0) {
                $ch = $src[$i];
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $i++;
            }
            $body = substr($src, $start, max(0, $i - $start));

            // strip whitespace, // comments, # comments, /* */ comments
            $code = preg_replace('(/\*.*?\*/|//[^\n]*|\#[^\n]*)', '', $body);
            $code = trim($code);

            if ($code !== '') {
                continue; // genuine handler body
            }

            // Empty body. Legal only when annotated — either inside the braces
            // or on the same source line after the closing brace (the cache
            // listeners put `// intentionally silent` there).
            $lineEnd = strpos($src, "\n", $i);
            $lineTail = substr($src, $i, ($lineEnd === false ? strlen($src) : $lineEnd) - $i);

            if (! str_contains($body, 'intentionally silent') && ! str_contains($lineTail, 'intentionally silent')) {
                $hits[] = substr_count(substr($src, 0, $match[1]), "\n") + 1;
            }
        }

        return $hits;
    }

    public function test_a_swallowed_notification_failure_is_logged_and_keeps_the_request_working(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'phone_number' => '0944'.rand(100000, 999999),
            'balance' => 1000,
        ]);
        $user->update(['wallet_id' => $wallet->id]);

        // Force every notification to explode.
        $broken = $this->mock(NotificationService::class);
        $broken->shouldReceive('createNotification')
            ->andThrow(new \RuntimeException('notification backend down'));

        Log::spy();

        $request = app(WalletRequestService::class)->requestCharge($user->fresh(), 500.0);

        // The domain outcome survived the notification failure...
        $this->assertDatabaseHas('wallet_requests', [
            'id' => $request->id, 'status' => 'pending', 'amount' => 500.00,
        ]);

        // ...and the failure is no longer invisible (pre-T3-13: no log line).
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $msg): bool => str_contains($msg, 'notification backend down'))
            ->atLeast()->once();
    }
}
