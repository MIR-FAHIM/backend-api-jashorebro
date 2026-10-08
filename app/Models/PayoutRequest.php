<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'status', // pending, approved, paid, rejected, cancelled
        'payout_method', // bkash, nagad, bank_transfer, cash_hub
        'account_details',
        'admin_notes',
        'processed_by_admin_id',
        'transaction_reference',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'account_details' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_admin_id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(EarningsLedger::class);
    }
}
