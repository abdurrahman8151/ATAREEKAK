<?php

namespace Database\Seeders;

use App\Models\Wallet;
use Illuminate\Database\Seeder;
use RuntimeException;

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
 * RV-21: the lookup is scoped to SYSTEM wallets (user_id IS NULL), not to the phone
 * alone. `wallets.phone_number` is UNIQUE, so the previous
 * `firstOrCreate(['phone_number' => …])` would MATCH a user-owned wallet that had
 * already claimed a reserved phone, create nothing, and still print "System wallets
 * ready" — the platform would own no wallet while a normal account silently held the
 * address that receives all escrow. That is the exact configuration the money boundary
 * in WalletTransactionService::lockWalletByPhone() now refuses to use, so re-running
 * this seeder could never repair it either. It fails loudly and explains the fix
 * instead of reporting a false success.
 *
 * Re-seeding an existing system wallet remains a no-op: balances are never reset.
 */
class SystemWalletSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensureSystemWallet(config('admin.system_admin.phone'), 'Primary Escrow');
        $this->ensureSystemWallet(config('admin.sycash.phone'), 'SyCash');

        // `$this->command` is only set when the seeder runs through the Artisan
        // command, so null-safe here lets the same code run (and be tested) elsewhere.
        $this->command?->info('✅  System wallets ready (Primary Escrow + SyCash).');
    }

    /**
     * Guarantee a platform-owned (user_id NULL) wallet exists for this phone.
     */
    private function ensureSystemWallet(string $phone, string $name): void
    {
        $wallet = Wallet::where('phone_number', $phone)->first();

        if ($wallet === null) {
            Wallet::create([
                'name' => $name,
                'user_id' => null,   // system wallet — no owner
                'phone_number' => $phone,
                'balance' => 0,
            ]);

            return;
        }

        if ($wallet->isSystemWallet()) {
            return;     // already seeded — never duplicate and never reset its balance
        }

        // A normal account holds the reserved address. Do NOT mutate that user's data
        // automatically (it carries a real balance) and do NOT pretend success.
        throw new RuntimeException(
            "Cannot seed the '{$name}' system wallet: wallet #{$wallet->id} owned by "
            ."user #{$wallet->user_id} already occupies the reserved phone number "
           ."'{$phone}'. Money paths reject a user-owned wallet in this role, so escrow "
            .'is unavailable until it is resolved. Move that wallet to its own phone '
            ."number (update wallets.phone_number for wallet #{$wallet->id}) and set "
            .'ADMIN_WALLET_PHONE / SYCASH_WALLET_PHONE to unowned numbers, then re-run '
            .'php artisan db:seed --class=SystemWalletSeeder.'
        );
    }
}
