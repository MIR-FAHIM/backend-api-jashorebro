<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property string $image_url
 * @property string|null $thumbnail_url
 * @property string|null $file_path
 * @property string|null $alt_text
 * @property bool $is_primary
 * @property int $sort_order
 */
class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'variant_id',
        'image_url',
        'file_path',
        'alt_text',
        'is_primary',
        'sort_order',
    ];

    protected $appends = [
        'thumbnail_url',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (ProductImage $image) {
            if ($image->file_path && Storage::disk('public')->exists($image->file_path)) {
                Storage::disk('public')->delete($image->file_path);
            }
        });
    }

    /**
     * Always resolve image URL with the live base URL.
     */
    public function getImageUrlAttribute(?string $value): ?string
    {
        return MediaUrl::resolve($value, $this->file_path);
    }

    public function setImageUrlAttribute(?string $value): void
    {
        $this->attributes['image_url'] = MediaUrl::resolve($value, $this->file_path ?? null);
    }

    /**
     * Fallback thumbnail URL alias pointing to the live image URL.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->image_url;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
