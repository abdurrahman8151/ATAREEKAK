<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Score\ScoreService;
use Illuminate\Console\Command;

/**
 * Awards drivers the cancellation-free commitment bonus (+2 / +4 / +6 pts for
 * 7 / 14 / 30 days without a cancellation or no-show).
 *
 * Run daily by the scheduler. Safe to run manually / more often — milestones
 * are tracked per driver on user_scores.last_streak_milestone_days so each
 * one is only ever paid out once per streak.
 *
 * Run manually for testing:
 *   php artisan score:driver-commitment-streaks
 */
class AwardDriverCommitmentStreaks extends Command
{
    protected $signature   = 'score:driver-commitment-streaks';
    protected $description = 'Award drivers bonus score points for going 7/14/30 days without a cancellation';

    public function handle(ScoreService $scoreService): int
    {
        $this->info('Evaluating driver commitment streaks...');

        $count = 0;

        User::where('is_verified_driver', true)
            ->chunkById(200, function ($drivers) use ($scoreService, &$count) {
                foreach ($drivers as $driver) {
                    $scoreService->evaluateDriverCommitmentStreak($driver);
                    $count++;
                }
            });

        $this->info("Evaluated commitment streaks for {$count} driver(s).");

        return 0;
    }
}
