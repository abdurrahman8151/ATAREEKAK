<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',            // 'Primary Escrow' | 'SyCash' | null for user wallets
        'user_id',         // nullable — system wallets have no owner
        'wallet_number',
        'balance',
        'cash_ride_debt',  // deferred cash ride creation fees owed to platform
        'phone_number',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'cash_ride_debt' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Wallet $wallet) {
            if (empty($wallet->wallet_number)) {
                $wallet->wallet_number = self::generateWalletNumber();
            }
        });
    }

    private static function generateWalletNumber(): string
    {
        do {
            $number = '';
            for ($i = 0; $i < 16; $i++) {
                $number .= mt_rand(0, 9);
            }
        } while (self::where('wallet_number', $number)->exists());

        return $number;
    }

    /** True for Primary Escrow and SyCash wallets. */
    public function isSystemWallet(): bool
    {
        return $this->user_id === null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest();
    }
}
