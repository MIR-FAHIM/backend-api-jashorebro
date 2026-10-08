<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'label',
        'recipient_name',
        'recipient_phone',
        'country',
        'street_address',
        'locality_district',
        'sub_district_thana',
        'landmark',
        'postal_code',
        'delivery_instructions',
        'is_default',
    ];

    protected $appends = [
        'district',
        'upazila',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * District alias accessor for locality_district.
     */
    public function getDistrictAttribute(): ?string
    {
        return $this->attributes['locality_district'] ?? null;
    }

    /**
     * District alias mutator.
     */
    public function setDistrictAttribute(?string $value): void
    {
        $this->attributes['locality_district'] = $value;
    }

    /**
     * Upazila alias accessor for sub_district_thana.
     */
    public function getUpazilaAttribute(): ?string
    {
        return $this->attributes['sub_district_thana'] ?? null;
    }

    /**
     * Upazila alias mutator.
     */
    public function setUpazilaAttribute(?string $value): void
    {
        $this->attributes['sub_district_thana'] = $value;
    }

    /**
     * Scope to retrieve the user's default address.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope to filter addresses belonging to a specific user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
