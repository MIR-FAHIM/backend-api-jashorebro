<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bio',
        'avatar_path',
        'cover_image_path',
        'locality',
        'website_url',
        'preferences',
    ];

    protected $appends = [
        'avatar_url',
        'cover_image_url',
    ];

    protected function casts(): array
    {
        return [
            'preferences' => 'array',
        ];
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return MediaUrl::resolve(null, $this->avatar_path);
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        return MediaUrl::resolve(null, $this->cover_image_path);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
