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
        // Community Commerce Attribution & Financial Allocations
        'earning_model', // none, community_shop, recommendation, group_drop
        'community_shop_id',
        'shop_listing_id',
        'recommender_id',
        'recommendation_code',
        'beneficiary_user_id',
        'supplier_allocation_price',
        'gross_markup',
        'platform_fee',
        'seller_earning',
        'commission_amount',
        'commercial_terms_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'variant_attributes' => 'array',
            'unit_price' => 'float',
            'quantity' => 'integer',
            'line_total' => 'float',
            'supplier_allocation_price' => 'float',
            'gross_markup' => 'float',
            'platform_fee' => 'float',
            'seller_earning' => 'float',
            'commission_amount' => 'float',
            'commercial_terms_snapshot' => 'array',
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

    public function communityShop(): BelongsTo
    {
        return $this->belongsTo(CommunityShop::class, 'community_shop_id');
    }

    public function shopListing(): BelongsTo
    {
        return $this->belongsTo(ShopListing::class, 'shop_listing_id');
    }

    public function recommender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recommender_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }
}
