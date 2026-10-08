<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupBuyCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_code',
        'product_id',
        'product_variant_id',
        'title',
        'status', // draft, scheduled, active, succeeded, failed, cancelled
        'group_price',
        'target_participants',
        'max_participants',
        'quantity_limit_per_customer',
        'start_at',
        'end_at',
        'success_at',
        'cancelled_at',
        'cancellation_reason',
        'created_by',
        'organizer_user_id',
        'originating_shop_id',
        'organizer_commission_per_unit',
        'supplier_allocation_price',
    ];

    protected function casts(): array
    {
        return [
            'group_price' => 'float',
            'organizer_commission_per_unit' => 'float',
            'supplier_allocation_price' => 'float',
            'target_participants' => 'integer',
            'max_participants' => 'integer',
            'quantity_limit_per_customer' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'success_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_user_id');
    }

    public function originatingShop(): BelongsTo
    {
        return $this->belongsTo(CommunityShop::class, 'originating_shop_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(GroupBuyParticipant::class, 'campaign_id');
    }

    public function activeParticipants(): HasMany
    {
        return $this->hasMany(GroupBuyParticipant::class, 'campaign_id')
            ->whereIn('status', ['reserved', 'confirmed']);
    }

    /**
     * Count distinct customers with valid reservations.
     */
    public function getDistinctParticipantsCountAttribute(): int
    {
        return (int) $this->activeParticipants()->distinct('user_id')->count('user_id');
    }

    public function getRemainingNeededAttribute(): int
    {
        return max(0, $this->target_participants - $this->distinct_participants_count);
    }

    public function getProgressPercentAttribute(): int
    {
        if ($this->target_participants <= 0) return 100;
        return (int) min(100, round(($this->distinct_participants_count / $this->target_participants) * 100));
    }

    public function isExpired(): bool
    {
        return $this->end_at !== null && $this->end_at->isPast();
    }
}
