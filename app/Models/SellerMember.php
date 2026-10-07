<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $seller_id
 * @property int $user_id
 * @property string $role
 * @property array|null $permissions
 */
class SellerMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_id',
        'user_id',
        'role',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
