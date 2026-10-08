<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunityShop extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'logo_url',
        'banner_url',
        'status', // draft, active, suspended, archived
        'is_verified',
        'notice',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
        ];
    }

    public function getLogoUrlAttribute(?string $value): ?string
    {
        return MediaUrl::resolve($value);
    }

    public function setLogoUrlAttribute(?string $value): void
    {
        $this->attributes['logo_url'] = MediaUrl::resolve($value);
    }

    public function getBannerUrlAttribute(?string $value): ?string
    {
        return MediaUrl::resolve($value);
    }

    public function setBannerUrlAttribute(?string $value): void
    {
        $this->attributes['banner_url'] = MediaUrl::resolve($value);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(ShopListing::class);
    }

    public function activeListings(): HasMany
    {
        return $this->hasMany(ShopListing::class)->where('is_active', true);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(GroupBuyCampaign::class, 'originating_shop_id');
    }
}
