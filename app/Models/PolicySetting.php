<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Singleton row (id=1) — see the migration for why. */
class PolicySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'company',
        'app_name',
        'contact_email',
        'contact_phone',
        'contact_address',
        'consent_label',
        'platform_profit_percentage',
        'updated_by',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }
}
