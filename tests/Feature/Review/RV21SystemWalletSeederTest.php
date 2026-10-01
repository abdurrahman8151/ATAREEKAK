<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\SystemWalletSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RV-21 (seeder half) — SystemWalletSeeder must guarantee a PLATFORM-owned wallet
 * and fail loudly rather than report a false success.
 *
 * Why this needed changing. `wallets.phone_number` is UNIQUE, and the seeder used
 * `firstOrCreate(['phone_number' => …])`. Matched on phone alone, that call MATCHES a
 * user-owned wallet that already claimed a reserved number (0912345678 / 0987654321 are
 * the shipped defaults in config/admin.php), creates nothing, and then still prints
 * "✅ System wallets ready". Meanwhile every money path resolves the escrow wallet by
 * that phone — so the platform would hold no escrow wallet while a normal account owns
 * the address that receives all of it. Since RV-21 made lockWalletByPhone() reject
 * user-owned rows, that state also became unrecoverable by re-running the seeder: the
 * old code would keep "succeeding" forever without ever fixing anything.
 *
 * These pin the replacement: create when absent, no-op when already a system wallet,
 * and THROW (naming the offending wallet and the remedy) when a user holds the phone.
 */
class RV21SystemWalletSeederTest extends TestCase
{
    use RefreshDatabase;

    private function primaryPhone(): string
    {
        return (string) config('admin.system_admin.phone');
    }

    private function syCashPhone(): string
    {
        return (string) config('admin.sycash.phone');
    }

    /** @test */
    public function it_creates_both_system_wallets_owned_by_no_one(): void
    {
        $this->assertDatabaseMissing('wallets', ['phone_number' => $this->syCashPhone()]);

        (new SystemWalletSeeder)->run();

        foreach ([$this->primaryPhone(), $this->syCashPhone()] as $phone) {
            $wallet = Wallet::where('phone_number', $phone)->firstOrFail();
            $this->assertTrue(
                $wallet->isSystemWallet(),
                "wallet for {$phone} must be a system wallet (user_id NULL)"
            );
        }
    }

    /** @test */
    public function re_seeding_is_a_no_op_and_never_resets_a_balance(): void
    {
        (new SystemWalletSeeder)->run();

        $syCash = Wallet::where('phone_number', $this->syCashPhone())->firstOrFail();
        $syCash->balance = 4_500_000;   // real escrow sitting in the wallet
        $syCash->save();

        (new SystemWalletSeeder)->run();   // must be safe to re-run
        (new SystemWalletSeeder)->run();

        $after = Wallet::where('phone_number', $this->syCashPhone())->firstOrFail();

        $this->assertSame('4500000.00', (string) $after->balance, 're-seeding must not wipe escrow');
        $this->assertSame(
            1,
            Wallet::where('phone_number', $this->syCashPhone())->count(),
            're-seeding must not duplicate the system wallet'
        );
    }

    /**
     * The behavior the old code got wrong: a user has already claimed the reserved
     * phone. firstOrCreate matched it, created nothing, and reported success.
     */
    public function test_it_refuses_to_report_success_when_a_user_owns_the_reserved_phone(): void
    {
        $hijacker = User::factory()->create();
        $stolen = Wallet::create([
            'user_id' => $hijacker->id,                 // user-owned...
            'phone_number' => $this->syCashPhone(),     // ...on the RESERVED escrow phone
            'balance' => 0,
        ]);

        try {
            (new SystemWalletSeeder)->run();
            $this->fail('seeder must refuse to report success while a user owns the reserved phone');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reserved phone number', $e->getMessage());
            $this->assertStringContainsString((string) $stolen->id, $e->getMessage(),
                'the message must name the offending wallet so it can actually be fixed');
        }

        // No system wallet was conjured, and the user row was left alone.
        $this->assertTrue($stolen->fresh()->user_id !== null);
        $this->assertFalse(Wallet::where('phone_number', $this->syCashPhone())->whereNull('user_id')->exists());
    }

    /** @test */
    public function the_reserved_phones_are_distinct_so_one_seed_cannot_satisfy_both(): void
    {
        // Guards the premise the hijack relies on. If both config defaults ever
        // collapsed onto one number, Primary and SyCash would share a wallet and the
        // 5% platform fee would silently land in escrow.
        $this->assertNotSame(
            $this->primaryPhone(),
            $this->syCashPhone(),
            'system wallet phones must differ'
        );
    }
}
