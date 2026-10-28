<?php

namespace App\Services;

use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\OrderPaymentHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\LogService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderStatusService
{
    /**
     * Permitted state transitions and authorized actor types.
     * History is append-only. Corrections require a documented transition.
     */
    public const ALLOWED_TRANSITIONS = [
        null => [
            'pending' => ['system', 'customer', 'admin'],
            'awaiting_group' => ['system', 'customer', 'admin'],
        ],
        'awaiting_group' => [
            'pending' => ['system', 'admin'],
            'cancelled' => ['customer', 'admin', 'system'],
        ],
        'pending' => [
            'processing' => ['admin'],
            'cancelled' => ['customer', 'admin', 'system'],
        ],
        'processing' => [
            'shipped' => ['admin'],
            'pending' => ['admin'], // Permitted admin correction with required reason
            'cancelled' => ['admin', 'system'],
        ],
        'shipped' => [
            'delivered' => ['admin'],
            'processing' => ['admin'], // Permitted admin correction with required reason
            'cancelled' => ['admin', 'system'],
        ],
        'delivered' => [
            // Terminal state
        ],
        'cancelled' => [
            // Terminal state
        ],
    ];

    /**
     * Record the initial order status history entry when an order is created.
     */
    public function recordInitialStatus(
        Order $order,
        string $initialStatus,
        ?int $userId = null,
        string $actorType = 'customer',
        ?string $reason = null,
        ?string $eventKey = null
    ): OrderStatusHistory {
        $eventKey = $eventKey ?? "order_{$order->id}_init_{$initialStatus}";

        // Idempotency check
        $existing = OrderStatusHistory::where('order_id', $order->id)
            ->where('event_key', $eventKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        return OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => $initialStatus,
            'changed_by_user_id' => $userId,
            'actor_type' => $actorType,
            'reason' => $reason ?? ($initialStatus === 'awaiting_group'
                ? 'Order placed for volume drop; awaiting campaign target unlock.'
                : 'Order placed successfully by customer.'),
            'internal_note' => null,
            'event_key' => $eventKey,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Transition an order to a new status with validation, locking, inventory management, and history logging.
     *
     * @throws ValidationException
     */
    public function transition(
        Order|int $orderOrId,
        string $toStatus,
        string $actorType,
        ?User $actor = null,
        ?string $reason = null,
        ?string $internalNote = null,
        ?string $eventKey = null
    ): OrderStatusHistory {
        $orderId = $orderOrId instanceof Order ? $orderOrId->id : $orderOrId;

        // Idempotency: If this exact event key was already processed, return existing
        if ($eventKey) {
            $existing = OrderStatusHistory::where('event_key', $eventKey)->first();
            if ($existing && ((int) $existing->order_id !== (int) $orderId || $existing->to_status !== $toStatus)) {
                throw ValidationException::withMessages(['event_key' => ['This event key already belongs to a different order change.']]);
            }
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use (
            $orderId,
            $toStatus,
            $actorType,
            $actor,
            $reason,
            $internalNote,
            $eventKey
        ) {
            $this->lockOrderCampaigns($orderId);
            // Lock the order record to prevent race conditions
            $order = Order::with('items')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $fromStatus = $order->status;
            if ($eventKey) {
                $existing = OrderStatusHistory::where('event_key', $eventKey)->first();
                if ($existing) {
                    if ((int) $existing->order_id !== (int) $orderId || $existing->to_status !== $toStatus) {
                        throw ValidationException::withMessages(['event_key' => ['This event key already belongs to a different order change.']]);
                    }
                    return $existing;
                }
            }

            // If already at target status and no eventKey was provided, check for duplicate submission
            if ($fromStatus === $toStatus) {
                $lastHistory = OrderStatusHistory::where('order_id', $order->id)
                    ->latest('id')
                    ->first();

                if ($lastHistory && $lastHistory->to_status === $toStatus) {
                    return $lastHistory;
                }
            }

            // Validate transition rules
            $this->validateTransition($fromStatus, $toStatus, $actorType, $actor, $reason);
            if ($fromStatus === 'awaiting_group' && $toStatus !== 'cancelled' && ! $this->allCampaignsSucceeded($order)) {
                throw ValidationException::withMessages(['status' => ['This conditional order must wait until every Price Drop in the order has reached its target. It cannot be advanced manually.']]);
            }

            // Execute inventory & group reservation adjustments for cancellation
            if ($toStatus === 'cancelled' && $fromStatus !== 'cancelled') {
                $this->handleCancellationSideEffects($order, $reason);
            }

            // Generate deterministic event key if none provided
            $resolvedEventKey = $eventKey ?? "order_{$order->id}_{$fromStatus}_to_{$toStatus}_" . time();

            // Insert append-only history record
            $history = OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changed_by_user_id' => $actor?->id,
                'actor_type' => $actorType,
                'reason' => $reason ?? $this->defaultReasonForStatus($toStatus, $actorType),
                'internal_note' => $internalNote,
                'event_key' => $resolvedEventKey,
                'occurred_at' => now(),
            ]);

            // Update order current status
            $order->status = $toStatus;
            $order->save();

            // Record audit business log within the same transaction
            $isCancelled = ($toStatus === 'cancelled');
            app(LogService::class)->record(
                event: $isCancelled ? 'order.cancelled' : 'order.status_changed',
                outcome: 'success',
                actor: $actor,
                subject: $order,
                metadata: [
                    'order_number' => $order->order_number,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'actor_type' => $actorType,
                    'order_status_history_id' => $history->id,
                    'reason' => $history->reason,
                ],
                message: $isCancelled
                    ? "Order #{$order->order_number} was cancelled by {$actorType}."
                    : "Order #{$order->order_number} status updated from {$fromStatus} to {$toStatus} by {$actorType}."
            );

            // In-App Notification: Customer & Admin notifications
            if ($isCancelled) {
                // Notify customer
                app(NotificationService::class)->sendToUser(
                    user: $order->user_id,
                    event: 'order.cancelled',
                    title: "Order #{$order->order_number} Cancelled",
                    message: "Your order #{$order->order_number} has been cancelled. " . ($history->reason ?? ''),
                    subject: $order,
                    actionUrl: "/orders/{$order->id}",
                    audience: 'customer',
                    dedupKey: "order_notif_cancel_cust_{$order->id}"
                );

                // Notify admins
                app(NotificationService::class)->sendToAdmins(
                    event: 'order.cancelled_admin',
                    title: "Order #{$order->order_number} Cancelled",
                    message: "Order #{$order->order_number} was cancelled by {$actorType}. Inventory has been released.",
                    subject: $order,
                    actionUrl: '/admin/orders',
                    dedupKey: "order_notif_cancel_admin_{$order->id}"
                );
            } else {
                // Notify customer of progression
                app(NotificationService::class)->sendToUser(
                    user: $order->user_id,
                    event: 'order.status_updated',
                    title: "Order #{$order->order_number} Update",
                    message: "Your order status is now " . ucfirst(str_replace('_', ' ', $toStatus)) . ". " . ($history->reason ?? ''),
                    subject: $order,
                    actionUrl: "/orders/{$order->id}",
                    audience: 'customer',
                    dedupKey: "order_notif_status_{$order->id}_{$fromStatus}_to_{$toStatus}"
                );
            }

            app(EarningsService::class)->syncEarningsForOrder($order, $history->reason);

            return $history;
        }, 3);
    }

    /** Fulfillment and payment changes either commit together, or both roll back. */
    public function updateByAdmin(Order|int $orderOrId, array $updates, User $admin): Order
    {
        if (! $admin->isAdmin()) {
            throw ValidationException::withMessages(['status' => ['Only authorized administrative staff can update an order.']]);
        }
        $orderId = $orderOrId instanceof Order ? $orderOrId->id : $orderOrId;
        return DB::transaction(function () use ($orderId, $updates, $admin) {
            $this->lockOrderCampaigns($orderId);
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            if (isset($updates['payment_status']) && $updates['payment_status'] !== $order->payment_status) {
                $from = $order->payment_status;
                $to = $updates['payment_status'];
                if (! (($from === 'unpaid' && $to === 'paid') || ($from === 'paid' && $to === 'refunded'))) {
                    throw ValidationException::withMessages(['payment_status' => ['Payment may move from unpaid to paid, then to fully refunded. Paid cannot revert to unpaid; a full refund is terminal. Partial refunds are not supported.']]);
                }
                if ($to === 'refunded' && trim((string) ($updates['reason'] ?? '')) === '') {
                    throw ValidationException::withMessages(['reason' => ['Record the reason for the completed full refund. This control does not send money.']]);
                }
                $order->update(['payment_status' => $to]);
                OrderPaymentHistory::create([
                    'order_id' => $order->id, 'from_status' => $from, 'to_status' => $to,
                    'changed_by_user_id' => $admin->id, 'reason' => $updates['reason'] ?? null,
                    'internal_note' => $updates['internal_note'] ?? null,
                    'event_key' => "payment:order:{$order->id}:{$from}:{$to}", 'occurred_at' => now(),
                ]);
                app(LogService::class)->record(event: 'order.payment_status_changed', outcome: 'success', actor: $admin, subject: $order,
                    metadata: ['order_id' => $order->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $updates['reason'] ?? null]);
            }
            if (isset($updates['status'])) {
                $this->transition($order, $updates['status'], 'admin', $admin, $updates['reason'] ?? null,
                    $updates['internal_note'] ?? null, $updates['event_key'] ?? null);
            }
            $order->refresh();
            app(EarningsService::class)->syncEarningsForOrder($order, $updates['reason'] ?? null);
            return $order;
        }, 3);
    }

    /**
     * Validate whether a transition is permitted for the given actor.
     */
    public function canTransition(string $fromStatus, string $toStatus, string $actorType): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];
        if (! isset($allowed[$toStatus])) {
            return false;
        }

        return in_array($actorType, $allowed[$toStatus], true);
    }

    /**
     * Internal validator with detailed exception messages.
     */
    protected function validateTransition(
        string $fromStatus,
        string $toStatus,
        string $actorType,
        ?User $actor,
        ?string $reason
    ): void {
        if (! $this->canTransition($fromStatus, $toStatus, $actorType)) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot transition order status from '{$fromStatus}' to '{$toStatus}' as actor '{$actorType}'.",
                ],
            ]);
        }

        // Additional actor permission checks
        if ($actorType === 'admin') {
            if (! $actor || ! $actor->isAdmin()) {
                throw ValidationException::withMessages([
                    'status' => ['Only authorized administrative staff can execute this status transition.'],
                ]);
            }

            // Regressions (corrections) require an explicit explanation
            if (($fromStatus === 'processing' && $toStatus === 'pending') ||
                ($fromStatus === 'shipped' && $toStatus === 'processing')) {
                if (empty(trim((string) $reason))) {
                    throw ValidationException::withMessages([
                        'reason' => ['An explanation reason is required when correcting or regressing an order status.'],
                    ]);
                }
            }
        }
    }

    /**
     * Handle inventory restocking and group reservation cancellations.
     */
    protected function handleCancellationSideEffects(Order $order, ?string $reason): void
    {
        $order->cancelled_at = now();
        $order->cancellation_reason = $reason ?? 'Order cancelled';

        // Restock inventory for all items in the order
        foreach ($order->items as $item) {
            if ($item->product_variant_id) {
                ProductVariant::where('id', $item->product_variant_id)
                    ->increment('stock_quantity', $item->quantity);
            }
            Product::where('id', $item->product_id)
                ->increment('stock_quantity', $item->quantity);
        }

        // Release group campaign reservations
        GroupBuyParticipant::where('order_id', $order->id)->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Default human-readable message for customers.
     */
    protected function defaultReasonForStatus(string $status, string $actorType): string
    {
        return match ($status) {
            'awaiting_group' => 'Order reserved for volume drop; awaiting campaign target unlock.',
            'pending' => 'Order is confirmed and awaiting merchant fulfillment queue.',
            'processing' => 'Order is being prepared and packed by the merchant.',
            'shipped' => 'Order has been dispatched with delivery courier / local hub.',
            'delivered' => 'Package has been delivered to customer.',
            'cancelled' => $actorType === 'customer'
                ? 'Order cancelled by customer.'
                : 'Order cancelled by administrator.',
            default => "Order status updated to {$status}.",
        };
    }

    /**
     * Automatically transition awaiting_group orders to pending when a drop campaign reaches target.
     */
    public function handleGroupCampaignUnlocked(GroupBuyCampaign $campaign): int
    {
        if ($campaign->status !== 'succeeded') return 0;
        $participants = $campaign->activeParticipants()
            ->with('order')
            ->get();

        $count = 0;
        $unlockedUsers = [];
        foreach ($participants as $participant) {
            $order = $participant->order;
            if ($order && $order->status === 'awaiting_group' && $this->allCampaignsSucceeded($order)) {
                $this->transition(
                    orderOrId: $order,
                    toStatus: 'pending',
                    actorType: 'system',
                    actor: null,
                    reason: "Volume drop '{$campaign->title}' reached target quota! Order moved to pending fulfillment.",
                    internalNote: "Automated transition triggered by group campaign #{$campaign->id} target unlock.",
                    eventKey: "campaign_{$campaign->id}_unlocked_order_{$order->id}"
                );
                $count++;
                $unlockedUsers[] = $participant->user_id;
            }
        }

        // In-App Notification: Notify all campaign participants that drop unlocked
        $participantUserIds = array_values(array_unique($unlockedUsers));
        if (! empty($participantUserIds)) {
            app(NotificationService::class)->sendToUsers(
                users: $participantUserIds,
                event: 'group_buy.unlocked',
                title: "Drop Goal Unlocked! 🎉",
                message: "The volume drop for '{$campaign->title}' hit its target of {$campaign->target_participants} participants! Your order is now confirmed.",
                subject: $campaign,
                actionUrl: '/orders',
                audience: 'customer',
                dedupKeyPrefix: "gb_unlocked_{$campaign->id}"
            );
        }

        // In-App Notification: Notify admins that target was achieved
        app(NotificationService::class)->sendToAdmins(
            event: 'group_buy.target_reached_admin',
            title: "Drop Goal Achieved: {$campaign->title}",
            message: "Campaign '{$campaign->title}' reached its goal of {$campaign->target_participants} participants! Orders unlocked.",
            subject: $campaign,
            actionUrl: '/admin/drops',
            dedupKey: "gb_unlocked_admin_{$campaign->id}"
        );

        return $count;
    }

    /**
     * Automatically cancel awaiting_group orders when a drop campaign expires or is cancelled.
     */
    public function handleGroupCampaignExpired(GroupBuyCampaign $campaign, ?string $reason = null): int
    {
        if (! in_array($campaign->status, ['failed', 'cancelled'], true)) return 0;
        $participants = GroupBuyParticipant::where('campaign_id', $campaign->id)
            ->where('status', 'reserved')
            ->whereNotNull('order_id')
            ->with('order')
            ->get();

        $count = 0;
        $cancelledUsers = [];
        foreach ($participants as $participant) {
            $order = $participant->order;
            if ($order && $order->status === 'awaiting_group') {
                $order->loadMissing('items');
                $hasOtherLines = $order->items->contains(fn ($item) => $item->purchase_mode !== 'group_buy'
                    || (int) $item->group_buy_campaign_id !== (int) $campaign->id);
                if ($hasOtherLines) {
                    app(LogService::class)->record(event: 'group_buy.legacy_mixed_order_needs_review', outcome: 'warning',
                        subject: $order, metadata: ['order_id' => $order->id, 'campaign_id' => $campaign->id],
                        message: 'An old mixed order needs manual review. Automatic Price Drop expiry did not cancel unrelated items.');
                    continue;
                }
                $customerReason = $reason ?? "Price Drop '{$campaign->title}' ended before reaching its target. Order cancelled and stock reservation released.";
                if ($order->payment_status === 'paid') $customerReason .= ' Payment was recorded as paid. A separate refund review is required; this action does not send a refund.';
                $this->transition(
                    orderOrId: $order,
                    toStatus: 'cancelled',
                    actorType: 'system',
                    actor: null,
                    reason: $customerReason,
                    internalNote: "Automated cancellation triggered by campaign #{$campaign->id} expiry/cancellation.",
                    eventKey: "campaign_{$campaign->id}_expired_order_{$order->id}"
                );
                $count++;
                $cancelledUsers[] = $participant->user_id;
            }
        }

        // In-App Notification: Notify affected participants
        $participantUserIds = array_values(array_unique($cancelledUsers));
        if (! empty($participantUserIds)) {
            app(NotificationService::class)->sendToUsers(
                users: $participantUserIds,
                event: 'group_buy.cancelled',
                title: "Volume Drop Update: {$campaign->title}",
                message: "The Price Drop '{$campaign->title}' ended before its target. Your conditional order was cancelled and stock released. If payment was recorded, a separate refund review is required; no refund is sent by this action.",
                subject: $campaign,
                actionUrl: '/orders',
                audience: 'customer',
                dedupKeyPrefix: "gb_expired_{$campaign->id}"
            );
        }

        return $count;
    }

    protected function lockOrderCampaigns(int $orderId): void
    {
        $ids = OrderItem::where('order_id', $orderId)->where('purchase_mode', 'group_buy')
            ->whereNotNull('group_buy_campaign_id')->pluck('group_buy_campaign_id')->unique()->sort();
        if ($ids->isNotEmpty()) GroupBuyCampaign::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    protected function allCampaignsSucceeded(Order $order): bool
    {
        $order->loadMissing('items');
        $groupItems = $order->items->where('purchase_mode', 'group_buy');
        if ($groupItems->isEmpty() || $groupItems->contains(fn ($item) => ! $item->group_buy_campaign_id)) return false;
        $ids = $groupItems->pluck('group_buy_campaign_id')->unique();
        return GroupBuyCampaign::whereIn('id', $ids)->where('status', 'succeeded')->count() === $ids->count();
    }
}
