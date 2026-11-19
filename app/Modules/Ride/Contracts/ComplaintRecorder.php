<?php

namespace App\Modules\Ride\Contracts;

/**
 * Stage 3 A1: open a support complaint without handing the caller the Complaint model.
 *
 * Ride's no-show conflict path auto-creates a `no_show` complaint for the support team. Console owns
 * `complaints`, so Console runs the insert - Ride must never do `Complaint::create([...])` itself, because that
 * is a cross-module table write that no relation and no `DB::table` call makes visible.
 *
 * CONSUMER-DECLARED ON PURPOSE (the `ProfilePhotoDirectory` pattern, one direction over). The contract describes
 * what RIDE needs - "record this complaint and tell me its id" - and Console, which owns the table, implements
 * and binds it. That is what removes the `Ride -> Console` edge - and with it the `Console <-> Ride` cycle -
 * without moving a single row, endpoint or service: the runtime call path, the implementation, the insert and the
 * returned id are all unchanged. The alternative shape (Console declares the port, Ride names it) is the
 * direction that created the edge in the first place.
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
