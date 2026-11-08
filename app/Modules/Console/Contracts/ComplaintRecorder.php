<?php

namespace App\Modules\Console\Contracts;

/**
 * Stage 2 C2: open a support complaint without handing the caller the Complaint model.
 *
 * Ride's no-show conflict path auto-creates a `no_show` complaint for the support team. Console owns
 * `complaints`, so Console runs the insert - Ride used to do `Complaint::create([...])` itself, which is a
 * cross-module table write that no relation and no `DB::table` call makes visible.
 *
 * Returns the new complaint's id, which is the only thing the caller used the model for (the log context, the
 * notification payloads and the two "complaint_id" keys). Handing back the model would let the caller read or
 * save rows it does not own, and would put Console's column set in Ride's hands.
 *
 * Attributes are passed through as given: this port exists to move the WRITE, not to redefine the complaint
 * schema or its defaults.
 */
interface ComplaintRecorder
{
    /** Insert one complaint from the given attributes and return its id. */
    public function record(array $attributes): int;
}
