<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPaymentHistory extends Model
{
    public $timestamps = false;
    protected $fillable = ['order_id', 'from_status', 'to_status', 'changed_by_user_id', 'reason', 'internal_note', 'event_key', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
