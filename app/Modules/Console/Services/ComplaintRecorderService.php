<?php

namespace App\Modules\Console\Services;

use App\Models\Complaint;
use App\Modules\Console\Contracts\ComplaintRecorder;

/**
 * Stage 2 C2: Console's implementation of the complaint insert.
 *
 * The insert is the same `Complaint::create()` Ride ran before, on Console's own table. Returning the id keeps
 * the caller's reach narrow - it cannot touch the row again through this contract.
 */
final class ComplaintRecorderService implements ComplaintRecorder
{
    public function record(array $attributes): int
    {
        return (int) Complaint::create($attributes)->id;
    }
}
