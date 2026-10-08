<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'attribute_id',
        'label',
        'value',
        'sort_order',
        'is_active',
        'color_code',
        'image_url',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getImageUrlAttribute(?string $value): ?string
    {
        return MediaUrl::resolve($value);
    }

    public function setImageUrlAttribute(?string $value): void
    {
        $this->attributes['image_url'] = MediaUrl::resolve($value);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
