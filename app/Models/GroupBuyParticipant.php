<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupBuyParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'user_id',
        'order_id',
        'product_variant_id',
        'quantity',
        'unit_price',
        'status', // interested, reserved, confirmed, cancelled, released
        'reserved_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'float',
            'reserved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(GroupBuyCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeCurrentParticipation(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where(fn ($interest) => $interest->where('status', 'interested')->whereNull('order_id'))
                ->orWhere(fn ($orders) => $orders->whereIn('status', ['reserved', 'confirmed'])->whereNotNull('order_id')
                    ->whereHas('order', fn ($order) => $order->where('status', '!=', 'cancelled')->where('payment_status', '!=', 'refunded')));
        });
    }
}
