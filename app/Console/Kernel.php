<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * Key scheduling rules applied throughout:
     *
     *  onOneServer()         — When you have multiple Octane nodes / workers,
     *                          this uses a Redis atomic lock so only ONE server
     *                          runs the command. Requires CACHE_DRIVER=redis.
     *                          Without this, every node runs it simultaneously.
     *
     *  withoutOverlapping()  — If the previous run hasn't finished yet (e.g. a
     *                          slow DB cleanup), skip this invocation rather than
     *                          running two instances in parallel. Prevents table locks.
     *
     *  runInBackground()     — Forks the command to a child process so the
     *                          scheduler process itself isn't blocked. Important
     *                          for commands that take more than a few seconds.
     *
     *  appendOutputTo()      — Keeps a rotating log so you can debug failures
     *                          without diving into Laravel's main log.
     */
    protected function schedule(Schedule $schedule): void
    {
        // ── TOKEN CLEANUP ────────────────────────────────────────────────────
        // User JWT refresh tokens — runs daily is fine since these are long-lived.
        $schedule->command('tokens:cleanup')
            ->daily()
            ->at('03:00')              // Low-traffic window (3 AM)
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduled/tokens-cleanup.log'));

        // Staff JWT refresh tokens — same cadence, stagger by 15 min to avoid
        // simultaneous DB pressure.
        $schedule->command('staff-tokens:cleanup')
            ->daily()
            ->at('03:15')
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduled/staff-tokens-cleanup.log'));

        // ── OTP CLEANUP ──────────────────────────────────────────────────────
        // OTPs expire after a few minutes but rows stay in the DB forever if
        // never cleaned. In a ride-sharing app with thousands of daily signups
        // this table grows fast and slows down OTP verification lookups.
        // Every 30 minutes keeps the table small without hammering the DB.
        $schedule->command('otp:cleanup')   // ← matches CleanupExpiredOtps $signature
        ->everyThirtyMinutes()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduled/otps-cleanup.log'));

        // ── ADMIN PASSWORD ROTATION ──────────────────────────────────────────
        // Uncomment and adjust frequency to your security policy.
        //
        // $schedule->command('admin:rotate-password')
        //     ->monthly()
        //     ->onOneServer()
        //     ->emailOutputOnFailure(config('admin.alert_email'));

        // ── NO-SHOW RESOLUTION ───────────────────────────────────────────────
        $schedule->command('noshow:resolve')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduled/noshow-resolve.log'));

        // ── DRIVER COMMITMENT STREAK BONUS ──────────────────────────────────────
        // Awards +2/+4/+6 pts for 7/14/30 cancellation-free days. Once a day is
        // enough — the milestone check is date-based, not time-of-day sensitive.
        $schedule->command('score:driver-commitment-streaks')
            ->daily()
            ->at('04:00')
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduled/driver-commitment-streaks.log'));
    }

    /**
     * Register the commands for the application.
     *
     * $this->load() auto-discovers every class inside Commands/ that extends
     * Command, so you do NOT need to manually list individual commands here.
     * This includes GetLoadTestTokens, CleanupExpiredOtps, etc. — they are all
     * picked up automatically the moment the file exists in the directory.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
