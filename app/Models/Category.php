<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $icon
 * @property string|null $image_url
 * @property string|null $description
 * @property int $order_index
 * @property bool $is_active
 */
class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'icon',
        'image_url',
        'description',
        'order_index',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order_index' => 'integer',
        ];
    }

    /**
     * Always resolve category image URL with the live base URL.
     */
    public function getImageUrlAttribute(?string $value): ?string
    {
        return MediaUrl::resolve($value);
    }

    public function setImageUrlAttribute(?string $value): void
    {
        $this->attributes['image_url'] = MediaUrl::resolve($value);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('order_index');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
