<?php

namespace Tests\Feature\Review;

use App\Domain\ValueObjects\Money;
use App\Models\User;
use App\Models\Wallet;
use App\Modules\Console\Services\AdminWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\TestCase;

/**
 * Characterization of the one failure path of AdminWalletService::chargeWallet that no other test
 * pins: the External Capital account is missing.
 *
 * The service must refuse loudly and leave the target wallet untouched. Without this pin, a refactor
 * that swallowed the missing-account case, or that wrote the credit before checking the account,
 * would pass every existing test. The message is pinned exactly because it names the seeder to run.
 *
 * Uses the real service through the container, no mocks of money classes.
 */
class AdminChargeWalletMissingExternalCharacterizationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private const TARGET_PHONE = '0911333444';

    public function test_charge_refuses_loudly_and_credits_nothing_when_external_capital_is_missing(): void
    {
        $this->seedSystemWallets(0.0);

        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $target = Wallet::create([
            'user_id' => $user->id,
            'phone_number' => self::TARGET_PHONE,
            'wallet_number' => 'WLT-EXT'.random_int(100000, 999999),
            'balance' => 100,
        ]);
        $user->update(['wallet_id' => $target->id]);

        // Remove the External Capital account so the lookup in chargeWallet returns null.
        Wallet::where('phone_number', config('admin.external.phone'))->delete();

        $thrown = null;
        try {
            app(AdminWalletService::class)->chargeWallet(
                self::TARGET_PHONE,
                Money::from(5000),
                ['type' => 'admin'],
            );
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'chargeWallet must refuse when External Capital is missing.');
        $this->assertSame(
            'External Capital wallet not found for phone: '.config('admin.external.phone')
            .'. Run: php artisan db:seed --class=SystemWalletSeeder',
            $thrown->getMessage()
        );

        // The transaction rolled back: the target balance is unchanged.
        $this->assertEquals(100.0, (float) $target->fresh()->balance);
    }
}
