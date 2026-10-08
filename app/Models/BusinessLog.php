<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BusinessLog extends Model
{
    use HasFactory;

    /**
     * Business logs are strictly append-only. No updated_at column exists.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'event',
        'category',
        'outcome',
        'actor_user_id',
        'actor_role',
        'subject_type',
        'subject_id',
        'message',
        'metadata',
        'request_id',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * Scope to apply common audit log filters.
     */
    public function scopeFiltered($query, array $filters = [])
    {
        if (! empty($filters['category']) && $filters['category'] !== 'all') {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        if (! empty($filters['outcome']) && $filters['outcome'] !== 'all') {
            $query->where('outcome', $filters['outcome']);
        }

        if (! empty($filters['actor_id'])) {
            $query->where('actor_user_id', $filters['actor_id']);
        }

        if (! empty($filters['actor_role']) && $filters['actor_role'] !== 'all') {
            $query->where('actor_role', $filters['actor_role']);
        }

        if (! empty($filters['subject_type'])) {
            $type = $filters['subject_type'];
            if (! str_contains($type, '\\')) {
                $type = match (strtolower($type)) {
                    'order' => \App\Models\Order::class,
                    'product' => \App\Models\Product::class,
                    'user' => \App\Models\User::class,
                    default => $type,
                };
            }
            $query->where('subject_type', $type);
        }

        if (! empty($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }

        if (! empty($filters['request_id'])) {
            $query->where('request_id', $filters['request_id']);
        }

        if (! empty($filters['start_date'])) {
            $query->where('occurred_at', '>=', $filters['start_date']);
        }

        if (! empty($filters['end_date'])) {
            $query->where('occurred_at', '<=', $filters['end_date']);
        }

        if (! empty($filters['q'])) {
            $search = $filters['q'];
            $query->where(function ($q) use ($search) {
                $q->where('message', 'like', "%{$search}%")
                    ->orWhere('event', 'like', "%{$search}%")
                    ->orWhere('request_id', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        return $query;
    }
}
