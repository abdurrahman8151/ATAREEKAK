<?php

namespace Database\Seeders;

use App\Models\Wallet;
use Illuminate\Database\Seeder;

/**
 * SystemWalletSeeder
 *
 * Creates the two admin-controlled system wallets:
 *   - Primary Escrow  (receives platform fees)
 *   - SyCash          (holds passenger escrow funds)
 *
 * These wallets have no user_id — they belong to the platform, not a person.
 * WalletTransactionService resolves them by phone number from config/admin.php.
 *
 * Uses firstOrCreate so re-seeding never duplicates wallets or resets balances.
 */
class SystemWalletSeeder extends Seeder
{
    public function run(): void
    {
        // Primary Escrow wallet — receives 5% platform fee on every completed ride
        Wallet::firstOrCreate(
            ['phone_number' => config('admin.system_admin.phone')],
            [
                'name' => 'Primary Escrow',
                'user_id' => null,   // system wallet — no owner
                'balance' => 0,
            ]
        );

        // SyCash wallet — holds passenger payments until ride completes/cancels
        Wallet::firstOrCreate(
            ['phone_number' => config('admin.sycash.phone')],
            [
                'name' => 'SyCash',
                'user_id' => null,   // system wallet — no owner
                'balance' => 0,
            ]
        );

        $this->command->info('✅  System wallets ready (Primary Escrow + SyCash).');
    }
}
