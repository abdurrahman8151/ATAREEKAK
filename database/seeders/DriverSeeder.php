<?php

namespace Database\Seeders;

use App\Interfaces\VerificationRepositoryInterface;
use App\Models\Photo;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * DriverSeeder
 *
 * Creates 10 verified drivers.
 *
 * Flow for each driver:
 *   1. User::create()
 *      → UserObserver::created() fires automatically:
 *        a. Creates profile row (ProfileRepository::createFromUser)
 *        b. Initialises trust score at 70 (ScoreService::initializeScore)
 *        c. Seeds 3.0 base rating (UserRating via admin rater)
 *   2. Insert Photo rows (license + mechanic_card) so verification checks pass.
 *   3. Set verification_status = 'pending' (required by verifyDriver guard).
 *   4. Call VerificationRepository::verifyDriver()
 *      → sets is_verified_driver = true, verification_status = 'approved'
 *      → calls UserRating::firstOrCreate (finds existing row, no duplicate)
 *   5. Create a Wallet for the driver (needed for e-pay rides).
 *   6. Update profile with vehicle details (type_of_car, color_of_car, etc.)
 */
class DriverSeeder extends Seeder
{
    use ResolvesSeedCredentials;

    // RV-39: wallets.phone_number is UNIQUE. This seeder used to hand every one of its
    // 10 wallets the SAME phone (self::COMM_NUMBER), so a full run crashed on the second
    // Wallet::create with a duplicate-key QueryException — the "10 verified drivers with
    // wallets" flow never completed. The base stays recognisable; the suffix makes each
    // wallet unique. PassengerSeeder uses the ...71 prefix so the two seeders can run in
    // the same database without colliding.
    private const WALLET_PHONE_BASE = '+9639833372';

    private function walletPhone(int $n): string
    {
        return self::WALLET_PHONE_BASE.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    private array $drivers = [
        ['suffix' => '1',  'vehicle' => 'pickup',  'color' => 'silver', 'seats' => 4],
        ['suffix' => '2',  'vehicle' => 'sedan',   'color' => 'black',  'seats' => 4],
        ['suffix' => '3',  'vehicle' => 'suv',     'color' => 'red',    'seats' => 6],
        ['suffix' => '4',  'vehicle' => 'minivan',  'color' => 'silver', 'seats' => 7],
        ['suffix' => '5',  'vehicle' => 'suv',     'color' => 'silver', 'seats' => 6],
        ['suffix' => '6',  'vehicle' => 'sedan',   'color' => 'black',  'seats' => 4],
        ['suffix' => '7',  'vehicle' => 'suv',     'color' => 'white',  'seats' => 6],
        ['suffix' => '8',  'vehicle' => 'sedan',   'color' => 'black',  'seats' => 4],
        ['suffix' => '9',  'vehicle' => 'pickup',  'color' => 'white',  'seats' => 4],
        ['suffix' => '10', 'vehicle' => 'pickup',  'color' => 'red',    'seats' => 4],
    ];

    public function __construct(
        private VerificationRepositoryInterface $verificationRepo
    ) {}

    public function run(): void
    {
        // T3-9: same key as PassengerSeeder, so one db:seed run yields ONE
        // login password for all seeded accounts; never a committed literal.
        $password = $this->seedPassword('SEED_USER_PASSWORD');

        foreach ($this->drivers as $data) {
            $n = $data['suffix'];

            // ── 1. Create user ──────────────────────────────────────────────
            // Observer fires here: profile + score + 3.0 base rating seeded.
            $user = User::create([
                'first_name' => "Driver{$n}",
                'last_name' => 'Test',
                'email' => "driver{$n}@syride.test",
                'password' => Hash::make($password),
                'gender' => 'M',
                'address' => 'دمشق',
                'status' => 1,
                'email_verified_at' => now(),
                'verification_status' => 'none',
            ]);

            // ── 2. Insert document photo rows ───────────────────────────────
            // Paths are placeholders — files don't need to exist for the DB.
            // These satisfy the photo-based driver checks in the admin panel.
            foreach (['license', 'mechanic_card'] as $docType) {
                Photo::create([
                    'user_id' => $user->id,
                    'type' => $docType,
                    'path' => "verifications/{$docType}/driver{$n}_placeholder.jpg",
                ]);
            }

            // ── 3. Set pending (verifyDriver() guards against non-pending) ──
            $user->update(['verification_status' => 'pending']);

            // ── 4. Approve through the repository ──────────────────────────
            // Sets is_verified_driver = true, verification_status = 'approved'.
            // Also calls UserRating::firstOrCreate → finds the existing 3.0
            // rating seeded in step 1 and does nothing (no duplicate).
            $this->verificationRepo->verifyDriver($user->id);

            // ── 5. Create driver wallet ─────────────────────────────────────
            $wallet = Wallet::create([
                'user_id' => $user->id,
                'phone_number' => $this->walletPhone((int) $n),
                'balance' => 10000, // 10,000 SYP starting balance for testing
            ]);

            // Link wallet to user
            $user->update(['wallet_id' => $wallet->id]);

            // ── 6. Update profile with vehicle details ──────────────────────
            // Profile was already created by the observer; we just fill vehicle info.
            $user->profile()->update([
                'type_of_car' => $data['vehicle'],
                'color_of_car' => $data['color'],
                'number_of_seats' => $data['seats'],
                'radio' => false,
                'smoking' => false,
            ]);

            $this->command->info("  ✅  Driver{$n} created → verified → wallet ready.");
        }

        $this->reportPassword('Driver password (all 10 accounts)', 'SEED_USER_PASSWORD');
        $this->command->info('✅  All 10 drivers seeded with 3.0 base rating.');
    }
}
