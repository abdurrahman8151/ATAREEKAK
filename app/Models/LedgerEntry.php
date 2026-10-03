<?php

namespace App\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leg of a double-entry transfer (decision un3).
 *
 * A transfer is only recorded when its signed legs sum to zero - see `LedgerService::postTransfer`,
 * which refuses to write anything else. A POSITIVE amount is a credit (the wallet received);
 * a NEGATIVE amount is a debit (the wallet gave). The sign is the direction: there is deliberately
 * no separate `direction` column, because two representations of one fact eventually disagree.
 */
class LedgerEntry extends Model
{
    use GuardsLazyLoading;
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'wallet_transaction_id',
        'amount',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }
}
