<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedFeedback extends Model
{
    use HasFactory;

    protected $table = 'feed_feedbacks';

    public const ACTION_HIDE = 'hide';
    public const ACTION_LESS_LIKE_THIS = 'less_like_this';
    public const ACTION_MUTE_AUTHOR = 'mute_author';
    public const ACTION_MUTE_TOPIC = 'mute_topic';

    protected $fillable = [
        'user_id',
        'feed_item_id',
        'content_type',
        'source_id',
        'action',
        'target_author_id',
        'reason',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targetAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_author_id');
    }
}
