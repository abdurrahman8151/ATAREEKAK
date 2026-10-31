<?php

namespace Tests\Support\Concerns;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\CommittedRowLeakDetector;

/**
 * Fails the test that leaked rows, at the test that leaked them.
 *
 * Registration order matters and is the reason this is a callback rather than a plain
 * tearDown(): `RefreshDatabase` registers its rollback in `beforeApplicationDestroyed` during
 * `parent::setUp()`, and callbacks run in registration order. This trait is called from
 * TestCase::setUp AFTER `parent::setUp()`, so its callback runs AFTER the rollback - the only
 * moment at which "did anything survive?" has a meaningful answer.
 *
 * A class that legitimately commits rows (because it is testing DDL, which implicitly commits)
 * must still clean up after itself. If it truly cannot, it opts out with
 * `skipCommittedRowLeakGuard('reason')`, which is visible and greppable rather than silent.
 */
trait GuardsAgainstCommittedLeaks
{
    private ?string $committedLeakGuardOptOut = null;

    protected function registerCommittedRowLeakGuard(): void
    {
        if (! $this->usesRollbackTrait()) {
            return;
        }

        $this->beforeApplicationDestroyed(function (): void {
            if ($this->committedLeakGuardOptOut !== null) {
                return;
            }

            $leaks = CommittedRowLeakDetector::detect();

            if ($leaks === []) {
                return;
            }

            throw new RuntimeException(
                'COMMITTED-ROW LEAK after '.static::class.'::'.$this->name().': '
                .CommittedRowLeakDetector::describe($leaks)
                .'. This test class uses a rollback trait, so it must leave these tables EMPTY; '
                .'rows survived, which means a statement in the class implicitly committed '
                .'(MySQL DDL does), or a fixture was written outside the transaction, or the '
                .'class cleanup is incomplete. Every later class now runs against a dirty '
                .'database - this is the RV45 failure mode, and it is failed here, at its source.'
            );
        });
    }

    /**
     * Opt out for a class that cannot keep the tables empty, with a stated reason.
     */
    protected function skipCommittedRowLeakGuard(string $reason): void
    {
        $this->committedLeakGuardOptOut = $reason;
    }

    private function usesRollbackTrait(): bool
    {
        foreach (class_uses_recursive(static::class) as $trait) {
            if ($trait === RefreshDatabase::class || $trait === DatabaseTransactions::class) {
                return true;
            }
        }

        return false;
    }
}
