<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property string $name
 * @property string|null $sku
 * @property float|null $price_override
 * @property int $stock_quantity
 * @property array|null $attributes
 * @property bool $is_active
 */
class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'price_override',
        'stock_quantity',
        'attributes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_override' => 'float',
            'stock_quantity' => 'integer',
            'attributes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
