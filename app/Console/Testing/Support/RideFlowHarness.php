<?php

namespace App\Console\Testing\Support;

use App\Models\User;

/**
 * The five command helpers the ride-flow scenarios drive: the scenario runner, the assertion
 * reporter, the scenario factory, and the two lookups they read back afterwards.
 *
 * Implemented by Testfullrideflow, which owns the console output, the fixtures and the sequence
 * counter. Batch 227: the scenarios call through this interface instead of sharing the command's
 * scope through a trait, so the command's helpers and the scenario bodies are separate units.
 */
interface RideFlowHarness
{
    public function runScenario(string $name, callable $fn): void;

    public function check(string $label, mixed $expected, mixed $actual): void;

    public function makeScenario(string $payment): array;

    public function setupWallets(User $driver, User $passenger, float $escrowAmount): array;

    public function score(User $user): int;
}
