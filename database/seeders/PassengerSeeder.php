<?php

namespace Database\Seeders;

use App\Interfaces\VerificationRepositoryInterface;
use App\Models\Photo;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * PassengerSeeder
 *
 * Creates 10 verified passengers.
 *
 * Flow for each passenger:
 *   1. User::create()
 *      → UserObserver::created() fires automatically:
 *        a. Creates profile row
 *        b. Initialises trust score at 70
 *        c. Seeds 3.0 base rating (UserRating via admin rater)
 *   2. Insert Photo rows (face_id + back_id) so verification checks pass.
 *   3. Set verification_status = 'pending'.
 *   4. Call VerificationRepository::verifyPassenger()
 *      → sets is_verified_passenger = true, verification_status = 'approved'
 *   5. Create a Wallet for the passenger (needed to pay for e-pay rides).
 */
class PassengerSeeder extends Seeder
{
    use ResolvesSeedCredentials;

    // RV-39: same defect as DriverSeeder - one UNIQUE phone reused across all 10
    // wallets made the second Wallet::create throw. This base (...73) does not
    // overlap DriverSeeder's ...72 base, so both seeders share a database safely.
    private const WALLET_PHONE_BASE = '+9639833373';

    private function walletPhone(int $n): string
    {
        return self::WALLET_PHONE_BASE.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    private array $passengers = [
        ['suffix' => '1',  'gender' => 'M'],
        ['suffix' => '2',  'gender' => 'F'],
        ['suffix' => '3',  'gender' => 'M'],
        ['suffix' => '4',  'gender' => 'F'],
        ['suffix' => '5',  'gender' => 'M'],
        ['suffix' => '6',  'gender' => 'F'],
        ['suffix' => '7',  'gender' => 'M'],
        ['suffix' => '8',  'gender' => 'F'],
        ['suffix' => '9',  'gender' => 'M'],
        ['suffix' => '10', 'gender' => 'F'],
    ];

    public function __construct(
        private VerificationRepositoryInterface $verificationRepo
    ) {}

    public function run(): void
    {
        // T3-9: single password for all seeded test accounts, from
        // SEED_USER_PASSWORD or randomly generated for this run (reported below).
        $password = $this->seedPassword('SEED_USER_PASSWORD');

        foreach ($this->passengers as $data) {
            $n = $data['suffix'];

            // ── 1. Create user ──────────────────────────────────────────────
            // Observer fires: profile + score + 3.0 base rating.
            $user = User::create([
                'first_name' => "Passenger{$n}",
                'last_name' => 'Test',
                'email' => "passenger{$n}@syride.test",
                'password' => Hash::make($password),
                'gender' => $data['gender'],
                'address' => 'دمشق',
                'status' => 1,
                'email_verified_at' => now(),
                'verification_status' => 'none',
            ]);

            // ── 2. Insert document photo rows ───────────────────────────────
            foreach (['face_id', 'back_id'] as $docType) {
                Photo::create([
                    'user_id' => $user->id,
                    'type' => $docType,
                    'path' => "verifications/{$docType}/passenger{$n}_placeholder.jpg",
                ]);
            }

            // ── 3. Set pending ──────────────────────────────────────────────
            $user->update(['verification_status' => 'pending']);

            // ── 4. Approve through the repository ──────────────────────────
            // Sets is_verified_passenger = true, verification_status = 'approved'.
            $this->verificationRepo->verifyPassenger($user->id);

            // ── 5. Create passenger wallet ──────────────────────────────────
            $wallet = Wallet::create([
                'user_id' => $user->id,
                'phone_number' => $this->walletPhone((int) $n),
                'balance' => 50000, // 50,000 SYP starting balance for testing
            ]);

            $user->update(['wallet_id' => $wallet->id]);

            $this->command->info("  ✅  Passenger{$n} created → verified → wallet ready.");
        }

        $this->reportPassword('Passenger password (all 10 accounts)', 'SEED_USER_PASSWORD');
        $this->command->info('✅  All 10 passengers seeded with 3.0 base rating.');
    }
}
