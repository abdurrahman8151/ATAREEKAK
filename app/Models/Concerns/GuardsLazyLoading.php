<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * RV-38 — reliable arming for Eloquent's lazy-loading guard.
 *
 * THE PROBLEM THIS SOLVES. `Model::preventLazyLoading()` sets a STATIC flag, but the guard in
 * `HasAttributes::getRelationValue()` reads the INSTANCE property
 * `$model->preventsLazyLoading`, which is declared `public $preventsLazyLoading = false` on
 * Model. This framework version copies the static flag onto the instance only inside
 * `Builder::hydrate()` AND only when `count($items) > 1` — so a single-row load
 * (`find()`, `first()`) leaves the instance flag FALSE and the guard NEVER fires. The flag
 * reads as enabled while doing nothing: a false green (recorded in audit §29.5).
 *
 * WHY NOT A `retrieved` EVENT LISTENER (the obvious first attempt): `fireModelEvent('retrieved')`
 * goes through the event dispatcher, and Laravel's `tearDownTheTestEnvironment()` REPLACES the
 * dispatcher between tests, discarding any boot-time listener. A ratchet that passes when its file
 * runs alone but fails inside the full suite is worse than none.
 *
 * WHY NOT `booted()`: it fires ONCE PER CLASS (guarded by `static::$booted[static::class]`), on the
 * first instance ever constructed — so only that one instance would ever be armed.
 *
 * WHY THIS WORKS. `newFromBuilder()` calls `$this->newInstance([], true)`, so EVERY hydration path
 * — `find()`, `first()`, `get()`, relation loading — funnels through `newInstance()`. Overriding it
 * arms each freshly-constructed instance from the static flag, deterministically, with no event or
 * dispatcher dependency (so it survives the test harness) and without touching vendor code.
 *
 * It is a NO-OP while the lazy guard is off (copies `false` over `false`); enabling
 * `Model::preventLazyLoading()` in AppServiceProvider becomes a genuine one-line flip.
 */
trait GuardsLazyLoading
{
    /**
     * Override the factory hook every hydrated instance passes through, seeding the
     * lazy-loading guard from the static flag.
     */
    public function newInstance($attributes = [], $exists = false)
    {
        /** @var EloquentModel $model */
        $model = parent::newInstance($attributes, $exists);

        $model->preventsLazyLoading = EloquentModel::preventsLazyLoading();

        return $model;
    }
}
