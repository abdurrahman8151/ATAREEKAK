<?php

// ============== App/Models/PushNotificationToken.php ==============

namespace App\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PushNotificationToken extends Model
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'device_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scope for active tokens
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Scope for device type
    public function scopeForDevice($query, $deviceType)
    {
        return $query->where('device_type', $deviceType);
    }
}
