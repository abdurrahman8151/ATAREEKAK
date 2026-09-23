<?php

use App\Models\Wallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * `GET /admin/wallet` (Reports page) and every real fee-crediting flow
 * (WalletTransactionService, CashRideFeeService) resolve the platform's
 * system wallets via config('admin.system_admin.phone') / config('admin.sycash.phone').
 * Neither wallet was ever created in production — only a separately-seeded
 * demo escrow wallet at a hardcoded test phone number exists (Syrideseeder,
 * `+963999000001`) — so every one of those lookups throws "System wallet for
 * phone [...] not found", which is exactly the "Server Error" on the Reports
 * page. This is the same firstOrCreate the RuntimeException itself points at
 * (SystemWalletSeeder), wrapped in a migration so it runs automatically on
 * deploy instead of depending on someone remembering to seed manually.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wallets')) {
            return;
        }

        Wallet::firstOrCreate(
            ['phone_number' => config('admin.system_admin.phone')],
            ['name' => 'Primary Escrow', 'user_id' => null, 'balance' => 0]
        );

        Wallet::firstOrCreate(
            ['phone_number' => config('admin.sycash.phone')],
            ['name' => 'SyCash', 'user_id' => null, 'balance' => 0]
        );
    }

    public function down(): void {}
};
