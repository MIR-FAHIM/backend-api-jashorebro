<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $product_id
 * @property string $name
 * @property string|null $sku
 * @property string|null $barcode
 * @property float|null $price_override
 * @property float|null $compare_price
 * @property float|null $group_price
 * @property int $stock_quantity
 * @property array|null $attributes
 * @property int|null $image_id
 * @property bool $is_active
 */
class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'barcode',
        'price_override',
        'compare_price',
        'group_price',
        'supplier_allocation_price',
        'min_selling_price',
        'max_selling_price',
        'recommendation_commission',
        'stock_quantity',
        'attributes',
        'image_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_override' => 'float',
            'compare_price' => 'float',
            'group_price' => 'float',
            'supplier_allocation_price' => 'float',
            'min_selling_price' => 'float',
            'max_selling_price' => 'float',
            'recommendation_commission' => 'float',
            'stock_quantity' => 'integer',
            'attributes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'image_id');
    }

    public function attributeItems(): BelongsToMany
    {
        return $this->belongsToMany(AttributeItem::class, 'product_variant_attribute_items');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get effective price falling back to product base price.
     */
    public function getEffectivePriceAttribute(): float
    {
        return $this->price_override !== null ? (float) $this->price_override : (float) ($this->product->base_price ?? 0);
    }
}
