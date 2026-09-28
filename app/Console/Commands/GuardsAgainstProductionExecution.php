<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Marks a console command as a development/test-only tool that MUST NOT run in
 * production.
 *
 * AF-4 (app-future audit): the image ships five commands that fabricate state —
 * Testfullrideflow (creates rides and moves real escrow money), Testridecompletionflow,
 * TestRideGatedInteractionCommand, TestNotificationCommand, and Getloadtesttokens
 * (mints load-test JWTs for any user id). They are reached only by an operator or
 * the scheduler, and a production box that can run `php artisan test:full-ride-flow`
 * or `get:load-test-tokens` is a way to forge rides and bypass login.
 *
 * Hiding them from `artisan list` is cosmetic — the actual fix is refusing to
 * execute when app.env is production. The guard lives at execute() rather than
 * handle() because Symfony always routes through execute(), so it protects every
 * command that uses this trait regardless of its handle() signature, and because
 * one command (Getloadtesttokens) has its own constructor we would otherwise have
 * to edit.
 */
trait GuardsAgainstProductionExecution
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (app()->environment('production')) {
            /** @var Command $this */
            $this->components->error(
                'This command is disabled in production (debug/test tooling that '.
                'creates or forges application state). Allowed environments: any '.
                'non-production one.'
            );

            return self::FAILURE;
        }

        return parent::execute($input, $output);
    }
}
