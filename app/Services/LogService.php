<?php

namespace App\Services;

use App\Models\BusinessLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LogService
{
    /**
     * Forbidden sensitive keys that must NEVER be persisted in metadata.
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
        'credit_card',
        'authorization',
        'cookie',
    ];

    /**
     * Centralized recording of business audit logs.
     *
     * @param string $event Event identifier (e.g. 'order.created', 'auth.login.succeeded')
     * @param string $outcome 'success' or 'failure'
     * @param User|int|null $actor The user initiating the action, or null for anonymous
     * @param Model|null $subject The primary domain entity affected (Order, Product, User, etc.)
     * @param array $metadata Sanitized structured attributes
     * @param string|null $message Human-readable narrative of the event
     */
    public function record(
        string $event,
        string $outcome,
        User|int|null $actor = null,
        ?Model $subject = null,
        array $metadata = [],
        ?string $message = null
    ): ?BusinessLog {
        try {
            $category = $this->resolveCategory($event);
            [$actorUserId, $actorRole] = $this->resolveActor($actor);
            [$subjectType, $subjectId] = $this->resolveSubject($subject, $metadata);
            $requestId = $this->resolveRequestId();

            // Deduplication: prevent duplicate logs for identical event in the same request or retry
            $existing = BusinessLog::where('request_id', $requestId)
                ->where('event', $event)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->first();

            if ($existing) {
                return $existing;
            }

            // Sanitize metadata to strictly exclude credentials and raw payloads
            $cleanMetadata = $this->sanitizeMetadata($metadata, $subject);
            $cleanMessage = $message ?? $this->defaultMessage($event, $outcome, $subject, $cleanMetadata);

            return BusinessLog::create([
                'event' => $event,
                'category' => $category,
                'outcome' => $outcome,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'message' => $cleanMessage,
                'metadata' => $cleanMetadata,
                'request_id' => $requestId,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent() ? Str::limit(request()->userAgent(), 255) : null,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Report to system error log without breaking the customer flow
            report($e);
            return null;
        }
    }

    /**
     * Derive category from the event namespace.
     */
    protected function resolveCategory(string $event): string
    {
        if (str_starts_with($event, 'auth.')) {
            return 'authentication';
        }
        if (str_starts_with($event, 'order.')) {
            return 'order';
        }
        if (str_starts_with($event, 'pos.')) {
            return 'order';
        }
        if (str_starts_with($event, 'product.')) {
            return 'product';
        }
        if (str_starts_with($event, 'address.')) {
            return 'address';
        }

        return explode('.', $event)[0] ?? 'general';
    }

    /**
     * Resolve actor ID and captured role.
     */
    protected function resolveActor(User|int|null $actor): array
    {
        if ($actor instanceof User) {
            $role = $actor->roles->pluck('name')->first()
                ?? ($actor->isAdmin() ? 'admin' : 'customer');
            return [$actor->id, $role];
        }

        if (is_numeric($actor)) {
            $user = User::with('roles')->find($actor);
            if ($user) {
                $role = $user->roles->pluck('name')->first()
                    ?? ($user->isAdmin() ? 'admin' : 'customer');
                return [$user->id, $role];
            }
            return [(int) $actor, 'user'];
        }

        if (auth()->check()) {
            $user = auth()->user();
            $role = $user->roles->pluck('name')->first()
                ?? ($user->isAdmin() ? 'admin' : 'customer');
            return [$user->id, $role];
        }

        return [null, 'anonymous'];
    }

    /**
     * Resolve polymorphic subject reference.
     */
    protected function resolveSubject(?Model $subject, array $metadata): array
    {
        if ($subject instanceof Model) {
            return [get_class($subject), $subject->getKey()];
        }

        if (! empty($metadata['order_id'])) {
            return [Order::class, (int) $metadata['order_id']];
        }

        if (! empty($metadata['product_id'])) {
            return [Product::class, (int) $metadata['product_id']];
        }

        if (! empty($metadata['address_id'])) {
            return [\App\Models\UserAddress::class, (int) $metadata['address_id']];
        }

        return [null, null];
    }

    /**
     * Retrieve correlation or request ID.
     */
    protected function resolveRequestId(): string
    {
        $id = request()->attributes->get('request_id')
            ?? request()->header('X-Request-ID');

        if (! empty($id) && is_string($id)) {
            return $id;
        }

        return (string) Str::uuid();
    }

    /**
     * Strict allowlist & sanitization of metadata values.
     */
    protected function sanitizeMetadata(array $data, ?Model $subject = null): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            // Block sensitive keys
            if (in_array($lowerKey, self::BLOCKED_KEYS, true)) {
                continue;
            }

            // Strip nested arrays containing sensitive keys
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeMetadata($value);
                continue;
            }

            if (is_scalar($value) || is_null($value)) {
                $sanitized[$key] = $value;
            }
        }

        // Contextual snapshots for Orders
        if ($subject instanceof Order) {
            $sanitized['order_number'] = $subject->order_number;
            $sanitized['status'] = $subject->status;
            $sanitized['total_amount'] = (float) $subject->total_amount;
        }

        // Contextual snapshots for Products
        if ($subject instanceof Product) {
            $sanitized['product_id'] = $subject->id;
            $sanitized['sku'] = $subject->sku;
            $sanitized['title'] = $subject->title;
            $sanitized['status'] = $subject->status;
        }

        // Contextual snapshots for Addresses (safe metadata only: no street address or sensitive details)
        if ($subject instanceof \App\Models\UserAddress) {
            $sanitized['address_id'] = $subject->id;
            $sanitized['label'] = $subject->label;
            $sanitized['district'] = $subject->locality_district;
            $sanitized['is_default'] = $subject->is_default;
        }

        return $sanitized;
    }

    /**
     * Standard human-readable event message.
     */
    protected function defaultMessage(string $event, string $outcome, ?Model $subject, array $metadata): string
    {
        return match ($event) {
            'auth.login.succeeded' => 'User logged in successfully.',
            'auth.login.failed' => 'Login attempt failed: ' . ($metadata['failure_reason'] ?? 'Invalid credentials.'),
            'auth.registration.succeeded' => 'New user registered successfully.',
            'auth.registration.failed' => 'User registration failed: ' . ($metadata['failure_reason'] ?? 'Validation error.'),
            'auth.logout.succeeded' => 'User session logged out.',

            'order.created' => 'Order #' . ($metadata['order_number'] ?? ($subject instanceof Order ? $subject->order_number : 'N/A')) . ' was placed successfully.',
            'order.status_changed' => 'Order #' . ($metadata['order_number'] ?? 'N/A') . ' transitioned from ' . ($metadata['from_status'] ?? 'initial') . ' to ' . ($metadata['to_status'] ?? 'unknown') . '.',
            'order.cancelled' => 'Order #' . ($metadata['order_number'] ?? 'N/A') . ' was cancelled and inventory released.',
            'order.creation_failed' => 'Order placement failed: ' . ($metadata['failure_reason'] ?? 'Validation error.'),

            'product.created' => "Product '{$metadata['title']}' created.",
            'product.updated' => "Product '{$metadata['title']}' updated.",
            'product.published' => "Product '{$metadata['title']}' published to catalog.",
            'product.archived' => "Product '{$metadata['title']}' archived.",
            'product.creation_failed' => 'Product creation failed: ' . ($metadata['failure_reason'] ?? 'Validation error.'),

            'address.created' => "Delivery address '{$metadata['label']}' added.",
            'address.created_by_admin' => "Delivery address '{$metadata['label']}' added by admin for customer #{$metadata['customer_id']}.",
            'address.updated' => "Delivery address '{$metadata['label']}' updated.",
            'address.deleted' => "Delivery address '{$metadata['label']}' removed.",
            'address.default_changed' => "Default delivery address updated to '{$metadata['label']}'.",

            'pos.order_created' => "POS Order #" . ($metadata['order_number'] ?? 'N/A') . " created by admin.",
            'pos.payment_confirmed' => "POS payment of BDT " . ($metadata['amount'] ?? '0') . " confirmed via " . ($metadata['payment_method'] ?? 'cash') . ".",
            'pos.discount_applied' => "Manual discount of BDT " . ($metadata['discount_amount'] ?? '0') . " applied to Order #" . ($metadata['order_number'] ?? 'N/A') . ".",

            default => "Business event '{$event}' recorded with outcome '{$outcome}'.",
        };
    }
}
