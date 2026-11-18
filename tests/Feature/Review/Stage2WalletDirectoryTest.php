<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Modules\Money\Contracts\WalletDirectory;
use App\Modules\Money\DTOs\WalletSummary;
use App\Modules\Money\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stage 2 C1, audit sec 271: characterization for the contract other modules will use instead of
 * `$user->wallet`. Pinned here, before any caller is switched over, because the whole point of the
 * DTO is that swarms of call sites move onto it without any of them changing what the API returns.
 */
final class Stage2WalletDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function walletFor(User $user, float $balance, float $debt = 0.0): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'phone_number' => '09'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT),
            'balance' => $balance,
            'cash_ride_debt' => $debt,
        ]);
    }

    public function test_it_returns_the_wallets_numbers_as_a_read_only_summary(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletFor($user, 1500.5, 250.0);

        $summary = app(WalletDirectory::class)->forUser($user->id);

        $this->assertInstanceOf(WalletSummary::class, $summary);
        $this->assertSame($wallet->id, $summary->id);
        $this->assertSame($user->id, $summary->userId);
        $this->assertSame((string) $wallet->wallet_number, $summary->walletNumber);
        // The money columns are passed through untyped on purpose: casting them here turned the
        // wallet/balance snapshot's "1500.00" into 1500.5, so the assertion pins the raw value.
        $this->assertSame('1500.50', $summary->balance);
        $this->assertSame('250.00', $summary->cashRideDebt);
    }

    public function test_it_returns_null_when_the_user_has_no_wallet(): void
    {
        $user = User::factory()->create();

        $this->assertNull(app(WalletDirectory::class)->forUser($user->id));
        $this->assertSame([], app(WalletDirectory::class)->summariesFor([]));
    }

    public function test_the_batched_lookup_is_one_query_and_keys_by_user(): void
    {
        $users = User::factory()->count(3)->create();
        foreach ($users as $index => $user) {
            $this->walletFor($user, 100.0 * ($index + 1));
        }
        $withoutWallet = User::factory()->create();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $summaries = app(WalletDirectory::class)->summariesFor(
            [$users[0]->id, $users[1]->id, $users[2]->id, $withoutWallet->id]
        );

        // One query for the whole set: this contract exists so a list render never turns into an N+1.
        $this->assertSame(1, $queries);
        $this->assertSame(
            [$users[0]->id, $users[1]->id, $users[2]->id],
            array_keys($summaries)
        );
        $this->assertSame('100.00', $summaries[$users[0]->id]->balance);
        $this->assertSame('300.00', $summaries[$users[2]->id]->balance);
        $this->assertArrayNotHasKey($withoutWallet->id, $summaries);
    }

    public function test_for_user_is_a_single_query(): void
    {
        $user = User::factory()->create();
        $this->walletFor($user, 10.0);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        app(WalletDirectory::class)->forUser($user->id);

        $this->assertSame(1, $queries, 'forUser() must stay one query: callers have a budget');
    }
}
