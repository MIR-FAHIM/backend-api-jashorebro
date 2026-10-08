<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type', // button, color, select, radio
        'sort_order',
        'is_active',
        'is_filterable',
        'is_required',
        'is_variant', // true = option for variant generation, false = specification
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_filterable' => 'boolean',
            'is_required' => 'boolean',
            'is_variant' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AttributeItem::class)->orderBy('sort_order');
    }

    public function productValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }
}
