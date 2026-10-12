<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentReaction extends Model
{
    use HasFactory;

    public const TYPE_LIKE = 'like';
    public const TYPE_HELPFUL = 'helpful';
    public const TYPE_AGREE = 'agree';
    public const TYPE_NEED_DETAILS = 'need_details';
    public const TYPE_GOOD_DEAL = 'good_deal';

    public const TYPES = [
        self::TYPE_LIKE,
        self::TYPE_HELPFUL,
        self::TYPE_AGREE,
        self::TYPE_NEED_DETAILS,
        self::TYPE_GOOD_DEAL,
    ];

    protected $fillable = [
        'comment_id',
        'user_id',
        'reaction_type',
    ];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(ProductComment::class, 'comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
