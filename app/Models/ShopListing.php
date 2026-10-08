<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopListing extends Model
{
    use HasFactory;

    protected $fillable = [
        'community_shop_id',
        'product_id',
        'caption',
        'selling_price',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'float',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(CommunityShop::class, 'community_shop_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
