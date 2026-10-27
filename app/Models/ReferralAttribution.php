<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralAttribution extends Model
{
    protected $fillable = [
        'token_hash',
        'visitor_token_hash',
        'product_id',
        'user_pick_id',
        'recommender_id',
        'buyer_user_id',
        'visited_at',
        'expires_at',
        'locked_at',
        'policy_version',
    ];

    protected $hidden = ['token_hash', 'visitor_token_hash'];

    protected function casts(): array
    {
        return [
            'visited_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
        ];
    }

    public function pick(): BelongsTo
    {
        return $this->belongsTo(UserPick::class, 'user_pick_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recommender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recommender_id');
    }
}
