<?php

namespace Database\Seeders;

use RuntimeException;

/**
 * RV-39 — refuse to run a data-forging seeder while the application is in production.
 *
 * AF-4f (2687872) put this guard on the five debug COMMANDS via
 * App\Console\Commands\GuardsAgainstProductionExecution. Seeders were never covered:
 * `php artisan db:seed --class=<Seeder> --force` reaches them through a different seam
 * (SeedCommand -> Seeder::__invoke -> run()), so the four seeders R2 sec 3 named could
 * still be executed against the live database:
 *
 *   SyrideSeeder        prompts to TRUNCATE every user/ride/wallet table
 *   BulkRideSeeder      bulk-inserts 500,000 rides + 1,000,000 bookings
 *   Atarikaktestseeder  freezes the clock with Carbon::setTestNow() and mints rides
 *   UserRealFlowSeeder  mutates a fixed real user (#36) and its wallets
 *
 * A trait cannot wrap the framework's __invoke() call, so each guarded seeder calls
 * refuseProduction() as the FIRST statement of its run(). The guard reads the container
 * environment (what Application::environment() and `--env=production` set), not a raw
 * config key, matching the command-side trait.
 *
 * It THROWS rather than returning a message: run() has no exit code, and a throw cannot
 * be ignored by a future caller that forgets an early return. Failing loudly is the same
 * discipline SystemWalletSeeder adopted for the hijacked-phone case (RV-21, 70d1f36).
 *
 * Deploy seeders (SpecialAccountSeeder, SystemAdminSeeder, SystemWalletSeeder) are
 * deliberately NOT guarded: they are idempotent firstOrCreate/no-op creators and are the
 * ones a production bootstrap is supposed to run. RV39SeederHygieneTest pins that split
 * both ways - guarded classes throw, deploy classes do not carry the trait.
 */
trait RefusesProduction
{
    /**
     * Call first thing in run(). Throws when the app environment is production.
     */
    protected function refuseProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                static::class.' is a development/test seeder that forges or destroys data '
                .'(truncations, bulk fake rows, frozen clocks, fixed real users). It refuses '
                .'to run while the app environment is production - this check is independent '
                .'of the --force option. Deploy seeders (SpecialAccountSeeder, '
                .'SystemAdminSeeder, SystemWalletSeeder) are the idempotent ones production '
                .'bootstrap may run.'
            );
        }
    }
}
