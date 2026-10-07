<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $seller_id
 * @property int $category_id
 * @property string $title
 * @property string $slug
 * @property string|null $short_description
 * @property string|null $description
 * @property float $base_price
 * @property float|null $compare_price
 * @property float|null $cost_price
 * @property string|null $sku
 * @property int $stock_quantity
 * @property bool $is_active
 * @property bool $is_featured
 * @property bool $is_drop_ready
 * @property float $rating_avg
 * @property int $rating_count
 * @property array|null $tags
 */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'seller_id',
        'category_id',
        'title',
        'slug',
        'short_description',
        'description',
        'base_price',
        'compare_price',
        'cost_price',
        'sku',
        'stock_quantity',
        'is_active',
        'is_featured',
        'is_drop_ready',
        'rating_avg',
        'rating_count',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'float',
            'compare_price' => 'float',
            'cost_price' => 'float',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_drop_ready' => 'boolean',
            'rating_avg' => 'float',
            'rating_count' => 'integer',
            'tags' => 'array',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany([
            'is_primary' => 'max',
            'sort_order' => 'min',
        ]);
    }

    /**
     * Helper to get effective discount percentage if compare_price is set.
     */
    public function getDiscountPercentAttribute(): ?int
    {
        if ($this->compare_price && $this->compare_price > $this->base_price) {
            return (int) round((($this->compare_price - $this->base_price) / $this->compare_price) * 100);
        }

        return null;
    }
}
