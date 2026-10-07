<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $username
 * @property string $phone
 * @property Carbon|null $phone_verified_at
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $status
 * @property string|null $status_reason
 * @property Carbon|null $last_login_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    protected $fillable = [
        'name',
        'username',
        'phone',
        'phone_verified_at',
        'email',
        'email_verified_at',
        'password',
        'status',
        'status_reason',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Public taste & bio profile.
     */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /**
     * Saved delivery addresses.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    /**
     * Platform administrative/staff roles.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('assigned_by_user_id')
            ->withTimestamps();
    }

    /**
     * Verification & recovery challenges.
     */
    public function authChallenges(): HasMany
    {
        return $this->hasMany(AuthChallenge::class);
    }

    /**
     * Audit logs authored by this user.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }

    /**
     * Role and status helper checks.
     */
    public function hasRole(string $roleName): bool
    {
        return $this->roles->contains('name', $roleName);
    }

    public function isStaff(): bool
    {
        return $this->roles()->exists();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isPhoneVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * Sent friendship requests / relationships.
     */
    public function sentFriendships(): HasMany
    {
        return $this->hasMany(Friendship::class, 'sender_id');
    }

    /**
     * Received friendship requests / relationships.
     */
    public function receivedFriendships(): HasMany
    {
        return $this->hasMany(Friendship::class, 'recipient_id');
    }

    /**
     * IDs of all confirmed friends.
     *
     * @return array<int>
     */
    public function friendIds(): array
    {
        $sent = $this->sentFriendships()
            ->where('status', 'accepted')
            ->pluck('recipient_id')
            ->all();

        $received = $this->receivedFriendships()
            ->where('status', 'accepted')
            ->pluck('sender_id')
            ->all();

        return array_values(array_unique(array_merge($sent, $received)));
    }

    /**
     * Query builder for all confirmed friends.
     */
    public function friendsQuery()
    {
        $friendIds = $this->friendIds();

        return static::query()->whereIn('id', $friendIds);
    }

    /**
     * Find friendship record between this user and another.
     */
    public function friendshipWith(User|int $user): ?Friendship
    {
        $targetId = $user instanceof User ? $user->id : $user;

        return Friendship::where(function ($query) use ($targetId) {
            $query->where('sender_id', $this->id)->where('recipient_id', $targetId);
        })->orWhere(function ($query) use ($targetId) {
            $query->where('sender_id', $targetId)->where('recipient_id', $this->id);
        })->first();
    }

    /**
     * Check if this user is confirmed friends with another.
     */
    public function isFriendsWith(User|int $user): bool
    {
        $targetId = $user instanceof User ? $user->id : $user;

        return in_array($targetId, $this->friendIds(), true);
    }

    /**
     * Determine relationship status with another user:
     * 'self' | 'friends' | 'pending_sent' | 'pending_received' | 'blocked' | 'none'
     */
    public function relationshipStatusWith(User|int $user): string
    {
        $targetId = $user instanceof User ? $user->id : $user;

        if ($this->id === $targetId) {
            return 'self';
        }

        $friendship = $this->friendshipWith($targetId);

        if (! $friendship) {
            return 'none';
        }

        if ($friendship->status === 'accepted') {
            return 'friends';
        }

        if ($friendship->status === 'blocked') {
            return 'blocked';
        }

        if ($friendship->status === 'pending') {
            return $friendship->sender_id === $this->id ? 'pending_sent' : 'pending_received';
        }

        return 'none';
    }

    /**
     * Calculate count of mutual friends with another user.
     */
    public function mutualFriendsCountWith(User|int $user): int
    {
        $target = $user instanceof User ? $user : static::find($user);
        if (! $target) {
            return 0;
        }

        $myFriends = $this->friendIds();
        $theirFriends = $target->friendIds();

        return count(array_intersect($myFriends, $theirFriends));
    }
}

