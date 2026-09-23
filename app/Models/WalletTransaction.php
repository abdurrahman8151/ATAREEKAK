<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'user_id',
        'processed_by_employee_id',
        'type',
        'amount',
        'previous_balance',
        'new_balance',
        'description',
        'transaction_id',
        'status',
        'reference'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'previous_balance' => 'decimal:2',
        'new_balance' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get the wallet that owns the transaction
     */
    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * Get the user that owns the transaction
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The staff employee who performed an admin-initiated transaction, if any.
     */
    public function processedByEmployee()
    {
        return $this->belongsTo(Employee::class, 'processed_by_employee_id');
    }
}
