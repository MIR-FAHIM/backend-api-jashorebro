<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'user_id',
        'seller_id',
        'status', // pending, processing, shipped, delivered, cancelled
        'payment_status', // unpaid, paid, refunded
        'payment_method', // cod, bkash, nagad
        'subtotal',
        'shipping_fee',
        'discount_amount',
        'total_amount',
        'shipping_name',
        'shipping_phone',
        'shipping_district',
        'shipping_upazila',
        'shipping_address',
        'notes',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'float',
            'shipping_fee' => 'float',
            'discount_amount' => 'float',
            'total_amount' => 'float',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function groupParticipants(): HasMany
    {
        return $this->hasMany(GroupBuyParticipant::class);
    }
}
