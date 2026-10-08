<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $brand
 * @property string $title
 * @property string $slug
 * @property string|null $short_description
 * @property string|null $description
 * @property string|null $sku
 * @property string|null $barcode
 * @property string $status // draft, published, archived
 * @property string $visibility // public, hidden
 * @property string $currency // BDT
 * @property float $base_price
 * @property float|null $compare_price
 * @property float|null $cost_price
 * @property int $stock_quantity
 * @property bool $track_inventory
 * @property int $low_stock_threshold
 * @property int $min_order_quantity
 * @property int|null $max_order_quantity
 * @property bool $is_normal_purchase_enabled
 * @property bool $is_group_buy_enabled
 * @property bool $is_featured
 * @property bool $is_new_arrival
 * @property bool $is_bestseller
 * @property bool $is_inventory_tracked
 * @property bool $is_cod_available
 * @property bool $is_free_shipping
 * @property bool $is_returnable
 * @property int $return_window_days
 * @property bool $is_cancelable
 * @property int $cancellation_cutoff_hours
 * @property float|null $weight_kg
 * @property array|null $dimensions
 * @property float $shipping_charge
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property bool $is_active
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
        'brand',
        'title',
        'slug',
        'short_description',
        'description',
        'sku',
        'barcode',
        'status',
        'visibility',
        'currency',
        'base_price',
        'compare_price',
        'cost_price',
        'stock_quantity',
        'track_inventory',
        'low_stock_threshold',
        'min_order_quantity',
        'max_order_quantity',
        'is_normal_purchase_enabled',
        'is_group_buy_enabled',
        'is_featured',
        'is_new_arrival',
        'is_bestseller',
        'is_inventory_tracked',
        'is_cod_available',
        'is_free_shipping',
        'is_returnable',
        'return_window_days',
        'is_cancelable',
        'cancellation_cutoff_hours',
        'weight_kg',
        'dimensions',
        'shipping_charge',
        'created_by',
        'updated_by',
        'is_active',
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
            'track_inventory' => 'boolean',
            'low_stock_threshold' => 'integer',
            'min_order_quantity' => 'integer',
            'max_order_quantity' => 'integer',
            'is_normal_purchase_enabled' => 'boolean',
            'is_group_buy_enabled' => 'boolean',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_inventory_tracked' => 'boolean',
            'is_cod_available' => 'boolean',
            'is_free_shipping' => 'boolean',
            'is_returnable' => 'boolean',
            'return_window_days' => 'integer',
            'is_cancelable' => 'boolean',
            'cancellation_cutoff_hours' => 'integer',
            'weight_kg' => 'float',
            'dimensions' => 'array',
            'shipping_charge' => 'float',
            'is_active' => 'boolean',
            'is_drop_ready' => 'boolean',
            'rating_avg' => 'float',
            'rating_count' => 'integer',
            'tags' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Keep backward-compatible flags in sync with published status and group buy settings
        static::saving(function (Product $product) {
            $isPublished = ($product->status ?? 'draft') === 'published';
            $isPublic = ($product->visibility ?? 'public') === 'public';

            $product->is_active = $isPublished && $isPublic;
            $product->is_drop_ready = (bool) ($product->is_group_buy_enabled && $isPublished);
            $product->is_inventory_tracked = (bool) $product->track_inventory;
        });
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function activeVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->where('is_active', true);
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

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function specifications(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class)
            ->where('is_variant_option', false)
            ->with(['attribute:id,name,slug,type', 'attributeItem:id,label,value,color_code']);
    }

    public function groupBuyCampaigns(): HasMany
    {
        return $this->hasMany(GroupBuyCampaign::class);
    }

    public function activeCampaign(): HasOne
    {
        return $this->hasOne(GroupBuyCampaign::class)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('end_at')->orWhere('end_at', '>', now());
            })
            ->latest();
    }

    /**
     * Scope for publicly browsable products.
     */
    public function scopePubliclyAvailable(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where('visibility', 'public')
            ->where('is_active', true);
    }

    /**
     * Effective aggregate stock quantity.
     */
    public function getEffectiveStockAttribute(): int
    {
        if ($this->variants()->exists()) {
            return (int) $this->variants()->where('is_active', true)->sum('stock_quantity');
        }

        return (int) $this->stock_quantity;
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
