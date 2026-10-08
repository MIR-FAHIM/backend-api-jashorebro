<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    use HasFactory;

    /**
     * History is append-only. No updated_at column exists.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'changed_by_user_id',
        'actor_type', // admin, customer, system
        'reason',
        'internal_note',
        'event_key',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /**
     * Scope to customer view (masks internal notes and sensitive staff identifiers).
     */
    public function scopeForCustomer($query)
    {
        return $query->select([
            'id',
            'order_id',
            'from_status',
            'to_status',
            'actor_type',
            'reason',
            'occurred_at',
            'created_at',
        ]);
    }
}
