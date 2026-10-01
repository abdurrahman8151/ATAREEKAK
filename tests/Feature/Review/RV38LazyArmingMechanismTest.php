<?php

namespace Tests\Feature\Review;

use App\Models\Concerns\GuardsLazyLoading;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RV-38 — the reliable lazy-loading arming mechanism (`GuardsLazyLoading`), verified.
 *
 * The defect this replaces (audit §29.5): `Model::preventLazyLoading()` sets a STATIC flag but the
 * guard reads the INSTANCE property, which this framework only copies on MULTI-row hydration
 * (`Builder::hydrate`, `if (count($items) > 1)`). A single-row `find()`/`first()` therefore left it
 * false and the guard NEVER fired — a false green. The first fix (a boot-time `retrieved` listener)
 * was rejected because Laravel's per-test dispatcher reset discards boot-time listeners, so a test
 * that passed alone failed in the full suite.
 *
 * `GuardsLazyLoading` overrides `newInstance()`, which EVERY hydration path funnels through
 * (`newFromBuilder` -> `newInstance([], true)`), so each instance is armed deterministically with no
 * event/dispatcher dependency.
 *
 * These tests turn the static flag on WITHIN the test (restoring it in tearDown) and prove the guard
 * now genuinely fires on a single-row load — which is precisely what the old approach could not do.
 */
class RV38LazyArmingMechanismTest extends TestCase
{
    use RefreshDatabase;

    private ?bool $restoreFlag = null;

    protected function tearDown(): void
    {
        if ($this->restoreFlag !== null) {
            Model::preventLazyLoading($this->restoreFlag);
            $this->restoreFlag = null;
        }

        parent::tearDown();
    }

    private function armTheGuard(): void
    {
        $this->restoreFlag = Model::preventsLazyLoading();
        Model::preventLazyLoading(true);
    }

    /** @test */
    public function every_model_uses_the_arming_trait(): void
    {
        // If a new model omits the trait, the guard silently stays inert for it. Pin the cohort.
        $models = [];
        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $name = basename($file, '.php');
            if (! preg_match('/^class\s+'.preg_quote($name, '/').'\s+extends/m', (string) file_get_contents($file))) {
                continue;
            }
            $models[] = $name;
        }
        $this->assertNotEmpty($models, 'expected Eloquent models under app/Models');

        $usingTrait = [];
        foreach ($models as $name) {
            $fqcn = 'App\\Models\\'.$name;
            if (in_array(GuardsLazyLoading::class, class_uses_recursive($fqcn), true)) {
                $usingTrait[] = $name;
            }
        }

        $this->assertSame(
            [],
            array_values(array_diff($models, $usingTrait)),
            'RV-38: every Eloquent model must `use GuardsLazyLoading` or its lazy guard is inert'
        );
    }

    /** @test */
    public function a_single_row_load_is_armed_and_the_guard_actually_throws(): void
    {
        $this->armTheGuard();

        $user = User::factory()->create();

        // find() is the single-row path the framework left unguarded.
        $found = User::find($user->id);

        $this->assertTrue(
            $found->preventsLazyLoading,
            'RV-38: a single-row User::find() must inherit the lazy guard from the static flag'
        );
        $this->assertFalse($found->relationLoaded('profile'),
            'precondition: profile must not be eager-loaded for this to mean anything');

        $this->expectException(LazyLoadingViolationException::class);

        $found->profile; // a genuine lazy relation load -> must now throw
    }

    /** @test */
    public function a_multi_row_load_is_also_armed(): void
    {
        $this->armTheGuard();

        $created = User::factory()->count(3)->create();
        $ids = $created->pluck('id')->all();

        // Scope to ONLY the rows this test created: the DB may hold other users depending on
        // suite order, so a global User::all() count is order-dependent (this assertion was the
        // one flaky failure — the arming check itself is unaffected).
        $rows = User::whereIn('id', $ids)->get();

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertTrue($row->preventsLazyLoading,
                'RV-38: every hydrated row must carry the lazy guard');
        }
    }

    /** @test */
    public function the_mechanism_is_a_no_op_while_the_guard_is_off(): void
    {
        // Zero behaviour change while lazy prevention is disabled (the committed default).
        $this->restoreFlag = Model::preventsLazyLoading();
        Model::preventLazyLoading(false);

        $user = User::factory()->create();
        $found = User::find($user->id);

        $this->assertFalse($found->preventsLazyLoading,
            'with the static flag off, the guard stays off — no behavioural change');

        // And a lazy relation load still WORKS normally (no exception). The factory creates a
        // Profile, so we assert the relation resolves to an actual Profile, not null.
        $this->assertInstanceOf(Profile::class, $found->profile,
            'with the guard disabled a lazy relation load resolves normally');
    }
}
