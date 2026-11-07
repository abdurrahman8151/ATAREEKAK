<?php

namespace App\Modules\Money\Services;

use App\Models\WalletTransaction;
use App\Modules\Money\Contracts\WalletTransactionRecorder;

/**
 * Stage 2 C1: the one insert Identity performs on Money's behalf.
 *
 * Body is the previous call, unchanged - `WalletTransaction::create($attributes)` - so the row, its
 * defaults and its timestamps are exactly what the caller used to get.
 *
 * The `WalletTransaction` import is still the kernel path because the model moves later in this phase,
 * in the commit that also retires `User::wallet()`; when it moves, only this line changes.
 */
final class WalletTransactionRecorderService implements WalletTransactionRecorder
{
    public function record(array $attributes): mixed
    {
        return WalletTransaction::create($attributes);
    }
}
