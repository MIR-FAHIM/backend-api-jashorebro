<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductComment extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_GENERAL = 'general';
    public const TYPE_EXPERIENCE = 'experience';
    public const TYPE_QUESTION = 'question';
    public const TYPE_DEAL = 'deal';
    public const TYPE_ALTERNATIVE = 'alternative';

    public const TYPES = [
        self::TYPE_GENERAL,
        self::TYPE_EXPERIENCE,
        self::TYPE_QUESTION,
        self::TYPE_DEAL,
        self::TYPE_ALTERNATIVE,
    ];

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_HIDDEN = 'hidden';
    public const STATUS_FLAGGED = 'flagged';

    protected $fillable = [
        'product_id',
        'user_id',
        'parent_id',
        'comment_type',
        'content',
        'attachment_url',
        'alternative_product_id',
        'price_suggestion',
        'is_verified_purchase',
        'is_pinned',
        'status',
        'edited_at',
        'reactions_count',
        'likes_count',
        'helpful_count',
        'agree_count',
        'need_details_count',
        'good_deal_count',
        'replies_count',
        'reports_count',
    ];

    protected function casts(): array
    {
        return [
            'is_verified_purchase' => 'boolean',
            'is_pinned' => 'boolean',
            'price_suggestion' => 'float',
            'reactions_count' => 'integer',
            'likes_count' => 'integer',
            'helpful_count' => 'integer',
            'agree_count' => 'integer',
            'need_details_count' => 'integer',
            'good_deal_count' => 'integer',
            'replies_count' => 'integer',
            'reports_count' => 'integer',
            'edited_at' => 'datetime',
        ];
    }

    /**
     * Always resolve attachment URL to live base URL.
     */
    public function getAttachmentUrlAttribute(?string $value): ?string
    {
        return MediaUrl::resolve($value);
    }

    public function setAttachmentUrlAttribute(?string $value): void
    {
        $this->attributes['attachment_url'] = MediaUrl::resolve($value);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProductComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ProductComment::class, 'parent_id')
            ->where('status', self::STATUS_PUBLISHED)
            ->oldest('id');
    }

    public function allReplies(): HasMany
    {
        return $this->hasMany(ProductComment::class, 'parent_id')->oldest('id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class, 'comment_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(CommentReport::class, 'comment_id');
    }

    public function alternativeProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'alternative_product_id');
    }

    /**
     * Scope for publicly published comments.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * Scope for root comments (threads).
     */
    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Check if a given user is the author.
     */
    public function isAuthor(?User $user): bool
    {
        return $user !== null && (int) $this->user_id === (int) $user->id;
    }

    /**
     * Return reaction breakdown map.
     */
    public function reactionBreakdown(): array
    {
        return [
            CommentReaction::TYPE_LIKE => (int) $this->likes_count,
            CommentReaction::TYPE_HELPFUL => (int) $this->helpful_count,
            CommentReaction::TYPE_AGREE => (int) $this->agree_count,
            CommentReaction::TYPE_NEED_DETAILS => (int) $this->need_details_count,
            CommentReaction::TYPE_GOOD_DEAL => (int) $this->good_deal_count,
        ];
    }
}
