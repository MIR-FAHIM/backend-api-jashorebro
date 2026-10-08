<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\GroupBuyCampaign;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Forbidden sensitive keys that must NEVER be persisted in notification payloads.
     */
    protected const BLOCKED_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'access_token',
        'plain_text_token',
        'otp',
        'code',
        'secret',
        'pin',
        'cvv',
        'card_number',
        'authorization',
        'cookie',
    ];

    /**
     * Send an in-app notification to a single user.
     */
    public function sendToUser(
        User|int $user,
        string $event,
        string $title,
        string $message,
        ?Model $subject = null,
        ?string $actionUrl = null,
        array $metadata = [],
        string $audience = 'customer',
        ?string $dedupKey = null
    ): ?AppNotification {
        $userId = $user instanceof User ? $user->id : (int) $user;

        if ($userId <= 0) {
            return null;
        }

        // Generate dedup key if none provided
        $resolvedDedupKey = $dedupKey ?? $this->generateDedupKey($event, $userId, $subject);

        // Deduplication check: return existing if already recorded for this event & recipient
        if ($resolvedDedupKey) {
            $existing = AppNotification::where('notifiable_type', User::class)
                ->where('notifiable_id', $userId)
                ->where('dedup_key', $resolvedDedupKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // Sanitize metadata
        $cleanMetadata = $this->sanitizeMetadata($metadata);

        // Resolve action destination path server-side
        $resolvedActionUrl = $actionUrl ?? $this->resolveDefaultActionUrl($subject, $audience);

        // Subject polymorphism
        [$subjectType, $subjectId] = $subject instanceof Model ? [get_class($subject), $subject->getKey()] : [null, null];

        $payload = [
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\' . Str::studly(str_replace('.', '_', $event)) . 'Notification',
            'notifiable_type' => User::class,
            'notifiable_id' => $userId,
            'audience' => in_array($audience, ['admin', 'customer'], true) ? $audience : 'customer',
            'event' => $event,
            'title' => $title,
            'message' => $message,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action_url' => $resolvedActionUrl,
            'dedup_key' => $resolvedDedupKey,
            'data' => [
                'event' => $event,
                'title' => $title,
                'message' => $message,
                'action_url' => $resolvedActionUrl,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'metadata' => $cleanMetadata,
            ],
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Transactional safety: If inside an active DB transaction, defer until commit
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(function () use ($payload) {
                try {
                    // Double check dedup in case of concurrent execution
                    if (! empty($payload['dedup_key'])) {
                        $alreadySent = AppNotification::where('notifiable_type', User::class)
                            ->where('notifiable_id', $payload['notifiable_id'])
                            ->where('dedup_key', $payload['dedup_key'])
                            ->exists();

                        if ($alreadySent) {
                            return;
                        }
                    }

                    AppNotification::create($payload);
                } catch (\Throwable $e) {
                    report($e);
                }
            });

            // Return hydrated notification representation
            return new AppNotification($payload);
        }

        try {
            return AppNotification::create($payload);
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    /**
     * Send notifications to multiple eligible users.
     *
     * @param iterable<User|int> $users
     * @return array<AppNotification>
     */
    public function sendToUsers(
        iterable $users,
        string $event,
        string $title,
        string $message,
        ?Model $subject = null,
        ?string $actionUrl = null,
        array $metadata = [],
        string $audience = 'customer',
        ?string $dedupKeyPrefix = null
    ): array {
        $notifications = [];

        foreach ($users as $user) {
            $userId = $user instanceof User ? $user->id : (int) $user;
            $perUserDedupKey = $dedupKeyPrefix ? "{$dedupKeyPrefix}_user_{$userId}" : null;

            $notif = $this->sendToUser(
                user: $user,
                event: $event,
                title: $title,
                message: $message,
                subject: $subject,
                actionUrl: $actionUrl,
                metadata: $metadata,
                audience: $audience,
                dedupKey: $perUserDedupKey
            );

            if ($notif) {
                $notifications[] = $notif;
            }
        }

        return $notifications;
    }

    /**
     * Send in-app notification to all authorized administrative personnel.
     *
     * @param array<string> $requiredRoles Roles to target (default: 'admin', 'super_admin')
     * @return array<AppNotification>
     */
    public function sendToAdmins(
        string $event,
        string $title,
        string $message,
        ?Model $subject = null,
        ?string $actionUrl = null,
        array $metadata = [],
        array $requiredRoles = ['admin', 'super_admin'],
        ?string $dedupKey = null
    ): array {
        $admins = User::whereHas('roles', fn ($q) => $q->whereIn('name', $requiredRoles))->get();

        if ($admins->isEmpty()) {
            return [];
        }

        return $this->sendToUsers(
            users: $admins,
            event: $event,
            title: $title,
            message: $message,
            subject: $subject,
            actionUrl: $actionUrl,
            metadata: $metadata,
            audience: 'admin',
            dedupKeyPrefix: $dedupKey
        );
    }

    /**
     * Derive deterministic dedup key based on event and affected entity.
     */
    protected function generateDedupKey(string $event, int $userId, ?Model $subject): ?string
    {
        if ($subject instanceof Model) {
            $classBase = strtolower(class_basename($subject));
            return "{$event}_{$classBase}_{$subject->getKey()}_user_{$userId}";
        }

        return null;
    }

    /**
     * Resolve default internal client destination URL based on subject entity and audience.
     */
    protected function resolveDefaultActionUrl(?Model $subject, string $audience): ?string
    {
        if (! $subject) {
            return $audience === 'admin' ? '/admin' : '/';
        }

        if ($subject instanceof Order) {
            return $audience === 'admin' ? '/admin/orders' : "/orders/{$subject->id}";
        }

        if ($subject instanceof Product) {
            return $audience === 'admin' ? '/admin/catalog' : "/products/{$subject->id}";
        }

        if ($subject instanceof GroupBuyCampaign) {
            return $audience === 'admin' ? '/admin/drops' : "/drops/{$subject->id}";
        }

        if ($subject instanceof User) {
            return $audience === 'admin' ? '/admin/users' : "/profile";
        }

        return null;
    }

    /**
     * Sanitize metadata to exclude secrets and credentials.
     */
    protected function sanitizeMetadata(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (in_array($lowerKey, self::BLOCKED_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeMetadata($value);
                continue;
            }

            if (is_scalar($value) || is_null($value)) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
