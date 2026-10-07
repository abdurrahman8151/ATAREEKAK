<?php

namespace App\Models;

use App\Models\Concerns\GuardsLazyLoading;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletRequest extends Model
{
    // RV-38: arms Eloquent's lazy-loading guard on every hydrated instance.
    use GuardsLazyLoading;

    protected $fillable = [
        'user_id',
        'wallet_id',
        'type',        // 'charge' | 'withdraw'
        'amount',
        'status',      // 'pending' | 'approved' | 'rejected' | 'cancelled'
        'user_notes',
        'admin_notes',
        'processed_by',
        'processed_by_employee_id',   // T3-4: the employee who actually decided (employees.id)
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * T3-4: the SHADOW user id, kept for history.
     *
     * `processedBy()` is NOT the employee. It points at `users`, and `StaffJwtMiddleware` resolves
     * it through `EmployeeManagementService::ensureShadowUser()` - a mirror row found or created
     * from the employee's EMAIL. If a real customer holds that email, this is that customer. It is
     * nullable and null for requests processed before T3-4. Read `processorEmployee()` for who
     * actually approved or rejected the request.
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** T3-4: the Employee who approved or rejected this request. The real audit actor. */
    public function processorEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'processed_by_employee_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isCharge(): bool
    {
        return $this->type === 'charge';
    }

    public function isWithdraw(): bool
    {
        return $this->type === 'withdraw';
    }
}
