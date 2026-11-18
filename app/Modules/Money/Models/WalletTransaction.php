<?php

namespace App\Modules\Money\Models;

use App\Models\Concerns\GuardsLazyLoading;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    /**
     * Stage 2 C1: explicit, so moving the class into a module namespace can never silently change
     * which table its rows land in.
     */
    protected $table = 'wallet_transactions';

    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'user_id',
        'type',
        'amount',
        'previous_balance',
        'new_balance',
        'description',
        'transaction_id',
        'status',
        'reference',
        // RV-02 L2 / D1: deterministic idempotency key for ONCE-ONLY postings. Nullable and
        // unique by migration, so a row that is not one of the instrumented postings simply
        // omits it. Never mint this from a clock or a random string - its whole purpose is that
        // the same logical movement recomputes the SAME key and collides on replay.
        'posting_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'previous_balance' => 'decimal:2',
        'new_balance' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the wallet that owns the transaction
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * Get the user that owns the transaction
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
