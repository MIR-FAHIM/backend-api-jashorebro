<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedImpression extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'feed_impressions';

    protected $fillable = [
        'user_id',
        'session_id',
        'event_type',
        'feed_item_id',
        'content_type',
        'source_id',
        'tab',
        'position',
        'ranking_version',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
