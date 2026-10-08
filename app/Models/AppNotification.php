<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Notifications\DatabaseNotification;

class AppNotification extends DatabaseNotification
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'notifications';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The data type of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'type',
        'notifiable_type',
        'notifiable_id',
        'audience',
        'event',
        'title',
        'message',
        'subject_type',
        'subject_id',
        'action_url',
        'dedup_key',
        'data',
        'read_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Polymorphic relation to the domain subject (Order, Product, Campaign, etc.)
     */
    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * Scope query to a specific audience context (customer or admin).
     */
    public function scopeForAudience(Builder $query, ?string $audience): Builder
    {
        if (! empty($audience) && $audience !== 'all') {
            return $query->where('audience', $audience);
        }

        return $query;
    }

    /**
     * Scope query to only unread notifications.
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope query to only read notifications.
     */
    public function scopeRead(Builder $query): Builder
    {
        return $query->whereNotNull('read_at');
    }
}
