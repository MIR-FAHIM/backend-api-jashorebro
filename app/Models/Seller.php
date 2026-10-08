<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $store_name
 * @property string $slug
 * @property string|null $tagline
 * @property string|null $description
 * @property string|null $logo_url
 * @property string|null $banner_url
 * @property string $contact_phone
 * @property string|null $contact_email
 * @property string $district
 * @property string|null $upazila
 * @property string|null $address
 * @property string|null $trade_license_number
 * @property string $status
 * @property Carbon|null $verified_at
 * @property float $rating_avg
 * @property int $rating_count
 */
class Seller extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'store_name',
        'slug',
        'tagline',
        'description',
        'logo_url',
        'banner_url',
        'contact_phone',
        'contact_email',
        'district',
        'upazila',
        'address',
        'trade_license_number',
        'status',
        'verified_at',
        'rating_avg',
        'rating_count',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'rating_avg' => 'float',
            'rating_count' => 'integer',
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

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(SellerMember::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
