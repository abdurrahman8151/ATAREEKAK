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
        // Registration order below is load-bearing: these methods are called in the same
        // order the commands were registered in before they were extracted.
        $this->scheduleTokenCleanup($schedule);
        $this->scheduleOtpCleanup($schedule);
        $this->scheduleBookingMaintenance($schedule);
        $this->scheduleLedgerReconciliation($schedule);

        // ── ADMIN PASSWORD ROTATION ──────────────────────────────────────────
        // Uncomment and adjust frequency to your security policy.
        //
        // $schedule->command('admin:rotate-password')
        //     ->monthly()
        //     ->onOneServer()
        //     ->emailOutputOnFailure(config('admin.alert_email'));

        $this->scheduleNoshowResolution($schedule);
    }

    private function scheduleTokenCleanup(Schedule $schedule): void
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
    }

    private function scheduleOtpCleanup(Schedule $schedule): void
    {
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
    }

    private function scheduleBookingMaintenance(Schedule $schedule): void
    {
        // ── BOOKING REQUEST EXPIRY (decision 2) ──────────────────────────────
        // A booking request the driver never answered now lapses once the ride departs,
        // instead of being auto-confirmed (the owner ruled against auto-confirm: it would
        // put a passenger in a car whose driver never agreed, and charge them for it).
        //
        // Every 15 minutes matches the existing no-show resolution job. Worst case a request
        // expires 15 minutes after departure, which is acceptable: the ride has already left,
        // so nothing user-visible depends on the exact minute.
        $schedule->command('bookings:expire-stale')   // ← matches ExpireStaleBookingsCommand
            ->everyFifteenMinutes()
            ->onOneServer()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduled/booking-expiry.log'));
    }

    private function scheduleLedgerReconciliation(Schedule $schedule): void
    {
        // ── LEDGER RECONCILIATION (decision un3) ───────────────────────────────
        // Proactive counterpart to the invariant test: checks on real data that every wallet
        // balance change is explained by its ledger legs. Catches a converted money path moving a
        // balance without posting legs - the failure the per-transfer sum check cannot see.
        // Scheduled because a money bug found a day later is a money bug that has already been paid out.
        $schedule->command('ledger:reconcile')   // ← matches ReconcileLedgerCommand
            ->daily()
            ->at('04:30')
            ->onOneServer()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduled/ledger-reconcile.log'));
    }

    private function scheduleNoshowResolution(Schedule $schedule): void
    {
        // ── NO-SHOW RESOLUTION ───────────────────────────────────────────────
        $schedule->command('noshow:resolve')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduled/noshow-resolve.log'));
    }

    /**
     * Register the commands for the application.
     *
     * $this->load() auto-discovers every class inside Commands/ that extends
     * Command, so you do NOT need to manually list individual commands here.
     * This includes CleanupExpiredOtps, BackfillBookingMoneySnapshot, etc. - they are all
     * picked up automatically the moment the file exists in the directory.
     *
     * Testing/ is loaded separately, and only when the environment is `local` or `testing`
     * state-fabricating tools (Testfullrideflow, Testridecompletionflow,
     * Testridegatedinteractioncommand, TestNotificationCommand, Getloadtesttokens),
     * which must stay runnable locally and in the test suite but must not be
     * invocable on a production box.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        if ($this->app->environment(['local', 'testing'])) {
            $this->load(__DIR__.'/Testing');
        }

        require base_path('routes/console.php');
    }
}
