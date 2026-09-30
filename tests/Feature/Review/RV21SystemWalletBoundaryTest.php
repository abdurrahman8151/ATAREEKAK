<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * RV-21 — the system-wallet boundary must fail CLOSED, so escrow can never be
 * routed to a hijacker.
 *
 * Root cause (proven in code). Every money path resolves the SyCash/Primary wallet
 * by phone: lockWalletByPhone(config('admin.sycash.phone')). The lookup matched on
 * phone ALONE and never asserted user_id IS NULL, and the repo ships the phone
 * defaults (0987654321 / 0912345678) in config/admin.php. A normal user who
 * registered one of those phones and created a wallet BEFORE the SystemWalletSeeder
 * ran therefore owned the wallet that RECEIVES ALL ESCROW — every booking payment,
 * refund and platform fee would credit the hijacker's balance. Money "created from
 * nothing" for whoever grabbed the reserved phone first.
 *
 * The fix scopes the lookup to system wallets (user_id IS NULL) and throws if none
 * matches, so a user-owned wallet can never be adopted as escrow sink. The seeder
 * firstOrCreate(['phone_number'=>…]) adoption is the coupled half of this and belongs
 * with the wallets.kind refactor (§26.4); this pins the money-boundary that stops the
 * actual theft.
 */
class RV21SystemWalletBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('Requires MySQL: wallet lockForUpdate + balance asserts.');
        }
        parent::setUp();
    }

    /** @test */
    public function a_user_owned_wallet_on_the_sycash_phone_cannot_receive_escrow(): void
    {
        $syCashPhone = config('admin.sycash.phone');

        $driver = User::factory()->create(['is_verified_driver' => true]);
        $passenger = User::factory()->create(['is_verified_passenger' => true]);
        $passenger->profile()->create(['full_name' => 'P', 'number_of_rides' => 0]);

        // Legit passenger wallet, funded.
        $pw = Wallet::create([
            'user_id' => $passenger->id,
            'phone_number' => '0922'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 1_000_000,
        ]);
        $passenger->update(['wallet_id' => $pw->id]);

        // THE ATTACK: a normal user claims the reserved SyCash phone before the seeder
        // runs. No system wallet exists yet (user_id NULL) for this phone. The wallet is
        // FUNDED so that, if the boundary were missing, charge would find no reason to
        // refuse and would deposit escrow here — this is what makes the test falsifiable
        // (an empty wallet would throw "insufficient" from a different line and mask it).
        $hijacker = User::factory()->create(['is_verified_passenger' => true]);
        $hijack = Wallet::create([
            'user_id' => $hijacker->id,               // NOT null -> a user-owned wallet
            'phone_number' => $syCashPhone,
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 5_000_000,
        ]);

        $ride = RideBuilder::for($driver)->paymentMethod('e-pay')->price(100_000)->create();
        $booking = Booking::create([
            'user_id' => $passenger->id, 'ride_id' => $ride->id, 'seats' => 1,
            'status' => Booking::CONFIRMED, 'communication_number' => '0912345678',
        ]);

        // chargePassengerForBooking locks SyCash FIRST; with no system wallet for that
        // phone it must fail closed rather than adopt the hijacker's funded wallet.
        $hijackBefore = (string) $hijack->fresh()->balance; // '5000000.00'
        $this->assertSame('5000000.00', $hijackBefore, 'precondition: hijacker wallet is funded');

        try {
            app(WalletTransactionService::class)->chargePassengerForBooking(
                $booking,
                $ride->fresh(),
                $passenger->fresh()
            );
            $this->fail('expected a fail-closed RuntimeException; charge proceeded');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('System wallet not found for phone', $e->getMessage());
        }

        // The property that makes this falsifiable: without the whereNull('user_id')
        // guard the charge finds the hijacker's wallet and CREDITS it +100000 (escrow
        // theft). With the guard it fails closed and the balance is untouched.
        $this->assertSame(
            $hijackBefore,
            (string) $hijack->fresh()->balance,
            'RV-21: escrow must NEVER be added to a user-owned wallet on a system phone'
        );
    }

    /** @test */
    public function a_correctly_seeded_system_wallet_still_resolves(): void
    {
        // The positive path: user_id NULL wallet is accepted, so the fix cannot
        // break a real deployment.
        $syCashPhone = config('admin.sycash.phone');
        $system = Wallet::create([
            'user_id' => null,
            'phone_number' => $syCashPhone,
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 500_000,
        ]);

        $driver = User::factory()->create(['is_verified_driver' => true]);
        $passenger = User::factory()->create(['is_verified_passenger' => true]);
        $passenger->profile()->create(['full_name' => 'P', 'number_of_rides' => 0]);
        $pw = Wallet::create([
            'user_id' => $passenger->id,
            'phone_number' => '0922'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.Str::random(10),
            'balance' => 1_000_000,
        ]);
        $passenger->update(['wallet_id' => $pw->id]);

        $ride = RideBuilder::for($driver)->paymentMethod('e-pay')->price(100_000)->create();
        $booking = Booking::create([
            'user_id' => $passenger->id, 'ride_id' => $ride->id, 'seats' => 1,
            'status' => Booking::CONFIRMED, 'communication_number' => '0912345678',
        ]);

        app(WalletTransactionService::class)->chargePassengerForBooking(
            $booking, $ride->fresh(), $passenger->fresh()
        );

        // System wallet received escrow; no exception. (Also proves the user_id NULL
        // row is the one selected.)
        $this->assertSame('600000.00', (string) $system->fresh()->balance);
        $this->assertTrue($system->isSystemWallet());
    }
}
