<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentReport extends Model
{
    use HasFactory;

    public const REASON_SPAM = 'spam';
    public const REASON_ABUSE = 'abuse';
    public const REASON_HARASSMENT = 'harassment';
    public const REASON_MISLEADING = 'misleading';
    public const REASON_OFF_TOPIC = 'off_topic';
    public const REASON_OTHER = 'other';

    public const REASONS = [
        self::REASON_SPAM,
        self::REASON_ABUSE,
        self::REASON_HARASSMENT,
        self::REASON_MISLEADING,
        self::REASON_OFF_TOPIC,
        self::REASON_OTHER,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_ACTION_TAKEN = 'action_taken';

    protected $fillable = [
        'comment_id',
        'reporter_id',
        'reason',
        'description',
        'status',
        'moderator_id',
        'moderator_notes',
        'action_taken',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(ProductComment::class, 'comment_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }
}
