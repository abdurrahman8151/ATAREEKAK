<?php

/**
 * GetLoadTestTokens — Artisan Command (fixed for actual schema)
 *
 * SCHEMA FIXES vs original:
 *   ❌ where('role', 'passenger')         → ✅ where('is_verified_passenger', 1)
 *   ❌ where('role', 'driver')            → ✅ where('is_verified_driver', 1)
 *   ❌ whereNotNull('phone_verified_at')  → ✅ where('status', 1)
 *   ❌ displays $user->phone              → ✅ displays $user->email
 *
 * SETUP:
 *   1. Copy to: app/Console/Commands/GetLoadTestTokens.php
 *   2. Run: php artisan loadtest:tokens
 *   3. Copy output into syride-breakpoint-test.js
 *
 * Usage:
 *   php artisan loadtest:tokens                   (5 passengers, 2 drivers)
 *   php artisan loadtest:tokens --count=20        (20 passengers, ~7 drivers)
 *   php artisan loadtest:tokens --export=env      (shell export statements for CI)
 *   php artisan loadtest:tokens --export=json     (JSON — pipe to a file)
 */

namespace App\Console\Commands;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Console\Command;

class Getloadtesttokens extends Command
{
    protected $signature   = 'loadtest:tokens {--count=5} {--export=table}';
    protected $description = 'Generate JWT tokens for seeded test users (for k6 load testing)';

    public function __construct(private JwtService $jwtService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $count  = (int) $this->option('count');
        $export = $this->option('export');

        $this->warn('⚠️  FOR LOAD TESTING ONLY — do not use in production');
        $this->newLine();

        // ── Passengers ────────────────────────────────────────────────────────
        // Your schema uses is_verified_passenger flag, not a role column.
        // status = 1 means active (not banned/disabled).
        $passengers = User::where('is_verified_passenger', 1)
            ->where('status', 1)
            ->take($count)
            ->get();

        // ── Drivers ───────────────────────────────────────────────────────────
        $drivers = User::where('is_verified_driver', 1)
            ->where('status', 1)
            ->take(max(2, (int)($count / 3)))
            ->get();

        // ── Fallback: if no verified passengers exist yet ─────────────────────
        if ($passengers->isEmpty()) {
            $this->warn('No is_verified_passenger=1 users found. Falling back to any active user.');
            $this->warn('For a real test, seed or manually set is_verified_passenger=1 on some users.');
            $this->newLine();

            $passengers = User::where('status', 1)
                ->whereNull('banned_at')
                ->take($count)
                ->get();
        }

        if ($passengers->isEmpty()) {
            $this->error('No active users found at all. Check: SELECT id, status FROM users LIMIT 5;');
            return 1;
        }

        if ($drivers->isEmpty()) {
            $this->warn('No verified drivers found — DRIVER_TOKENS will be empty.');
            $this->warn('Set is_verified_driver=1 on at least 2 users for driver-side tests.');
        }

        // ── Generate tokens ───────────────────────────────────────────────────
        $passengerTokens = $passengers->map(function (User $user) {
            return [
                'id'    => $user->id,
                'email' => $user->email,
                'type'  => 'passenger',
                'token' => $this->jwtService->generateTokenPair($user)['access_token'],
            ];
        });

        $driverTokens = $drivers->map(function (User $user) {
            return [
                'id'    => $user->id,
                'email' => $user->email,
                'type'  => 'driver',
                'token' => $this->jwtService->generateTokenPair($user)['access_token'],
            ];
        });

        $allTokens = $passengerTokens->merge($driverTokens);

        // ── Output ────────────────────────────────────────────────────────────
        if ($export === 'table') {
            $this->table(
                ['ID', 'Email', 'Type', 'Token (first 50 chars)'],
                $allTokens->map(fn($t) => [
                    $t['id'],
                    $t['email'],
                    $t['type'],
                    substr($t['token'], 0, 50) . '...',
                ])->toArray()
            );

            $this->newLine();
            $this->info('── Paste this into syride-breakpoint-test.js ──────────────────');
            $this->newLine();

            $pTokens = $passengerTokens->pluck('token')->map(fn($t) => "    '$t'")->implode(",\n");
            $dTokens = $driverTokens->pluck('token')->map(fn($t) => "    '$t'")->implode(",\n");

            $this->line('const PASSENGER_TOKENS = [');
            $this->line($pTokens ?: "    // no passenger tokens — see warning above");
            $this->line('];');
            $this->newLine();
            $this->line('const DRIVER_TOKENS = [');
            $this->line($dTokens ?: "    // no driver tokens — see warning above");
            $this->line('];');
            $this->newLine();

            $this->info('── Also paste these USER_IDs for /api/profile/{userId} tests ─');
            $ids = $passengers->pluck('id')->implode(', ');
            $this->line("const USER_IDS = [$ids];");

        } elseif ($export === 'env') {
            foreach ($passengerTokens as $i => $t) {
                $n = $i + 1;
                $this->line("export PASSENGER_TOKEN_{$n}={$t['token']}");
            }
            foreach ($driverTokens as $i => $t) {
                $n = $i + 1;
                $this->line("export DRIVER_TOKEN_{$n}={$t['token']}");
            }
            $this->line('export BASE_URL=http://localhost:8080');

        } elseif ($export === 'json') {
            $this->line(json_encode($allTokens->toArray(), JSON_PRETTY_PRINT));
        }

        $this->newLine();
        $this->info("Generated {$passengerTokens->count()} passenger + {$driverTokens->count()} driver tokens.");
        $this->warn('Tokens expire based on JWT_TTL (' . config('jwt.ttl', '?') . ' min). Re-run if they expire.');
        $this->newLine();

        // ── Helpful DB query for ride IDs (needed by the k6 test) ─────────────
        $this->info('── Also run this to get real RIDE_IDS for the test ───────────');
        $this->line('docker exec syride_mysql mysql -uroot -psecret 4th_year_project_db \\');
        $this->line("  -e \"SELECT id, status FROM rides WHERE status='active' LIMIT 20;\"");

        return 0;
    }
}
