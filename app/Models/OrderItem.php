<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'purchase_mode', // normal, group_buy
        'group_buy_campaign_id',
        'product_title',
        'product_sku',
        'variant_name',
        'variant_attributes',
        'unit_price',
        'quantity',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'variant_attributes' => 'array',
            'unit_price' => 'float',
            'quantity' => 'integer',
            'line_total' => 'float',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(GroupBuyCampaign::class, 'group_buy_campaign_id');
    }
}
