<?php

namespace App\Services;

use App\Models\EarningsLedger;
use App\Models\Order;
use App\Models\PayoutRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EarningsService
{
    public const MINIMUM_PAYOUT = 50.00;
    public const ELIGIBILITY_POLICY = 'paid_and_delivered_no_hold';

    public function __construct(protected NotificationService $notificationService, protected LogService $logService) {}

    /** New lifecycle events are append-only. Legacy credit statuses remain readable. */
    public function getUserBalances(User|int $userOrId): array
    {
        $userId = $userOrId instanceof User ? $userOrId->id : $userOrId;
        $entries = EarningsLedger::with('order:id,status,payment_status')->where('user_id', $userId)->orderBy('id')->get();
        $credits = $entries->where('type', 'credit_pending')->filter(fn ($entry) => $entry->amount > 0);
        $pending = $released = $reversed = 0;
        $needsReview = false;
        $seenItems = [];
        foreach ($credits as $credit) {
            $lineKey = $credit->order_item_id ? "item:{$credit->order_item_id}" : "ledger:{$credit->id}";
            if (isset($seenItems[$lineKey])) {
                // Do not allow historical duplicate credits to fund new withdrawals.
                $needsReview = true;
                continue;
            }
            $seenItems[$lineKey] = true;
            if (! $credit->order || ! $credit->order_item_id) {
                $needsReview = true;
                continue;
            }
            $events = $entries->filter(fn ($event) => $event->source_ledger_id === $credit->id
                || (! $event->source_ledger_id && $event->order_item_id === $credit->order_item_id));
            $isReversed = $credit->status === 'reversed'
                || $events->contains(fn ($event) => $event->type === 'reversal')
                || $credit->order->status === 'cancelled' || $credit->order->payment_status === 'refunded';
            $wasReleased = $credit->status === 'available' || $events->contains(fn ($event) => $event->type === 'release_available');
            $amount = $this->minor($credit->amount);
            if ($isReversed) {
                $reversed += $amount;
            } elseif ($wasReleased && $this->isEligible($credit->order)) {
                $released += $amount;
            } else {
                $pending += $amount;
            }
        }
        // A reservation and its terminal event describe one payout, not two debits.
        $payouts = PayoutRequest::where('user_id', $userId)->get(['amount', 'status']);
        $reserved = $payouts->whereIn('status', ['pending', 'approved'])->sum(fn ($payout) => $this->minor($payout->amount));
        $paid = $payouts->where('status', 'paid')->sum(fn ($payout) => $this->minor($payout->amount));
        $net = $released - $paid - $reserved;
        return [
            'pending' => $pending / 100, 'available' => max(0, $net) / 100,
            'reserved_for_payout' => $reserved / 100, 'paid' => $paid / 100,
            'reversed' => $reversed / 100, 'total_earned' => $released / 100,
            // Only cash already paid creates recovery; an underfunded reservation is not debt.
            'outstanding_recovery' => max(0, $paid - $released) / 100,
            'payout_shortfall' => max(0, -$net) / 100,
            'minimum_payout' => self::MINIMUM_PAYOUT, 'eligibility_policy' => self::ELIGIBILITY_POLICY,
            'reconciliation_required' => $needsReview,
        ];
    }

    public function recordPendingEarningsForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->lockBeneficiaries($locked);
            foreach ($locked->items as $item) {
                if (! $item->beneficiary_user_id || $item->beneficiary_user_id === $locked->user_id) continue;
                $unit = $item->earning_model === 'recommendation' ? $item->commission_amount : $item->seller_earning;
                $amount = $this->minor($unit) * $item->quantity;
                if ($amount <= 0 || EarningsLedger::where('order_item_id', $item->id)->where('type', 'credit_pending')->exists()) continue;
                $ledger = EarningsLedger::create([
                    'user_id' => $item->beneficiary_user_id, 'order_id' => $locked->id, 'order_item_id' => $item->id,
                    'event_key' => "earning:credit:item:{$item->id}", 'type' => 'credit_pending',
                    'amount' => $amount / 100, 'status' => 'pending',
                    'description' => "Pending {$item->earning_model} earning for Order #{$locked->order_number} ({$item->product_title}); requires paid and delivered.",
                    'occurred_at' => now(),
                ]);
                $this->notifyEarning($ledger, $locked, 'sale_attributed', 'Sale attributed',
                    "৳{$ledger->amount} is pending. It becomes available only after payment and delivery; cancellations and refunds remove eligibility.");
            }
        }, 3);
    }

    /** Call after fulfillment and payment updates, in their shared transaction. */
    public function syncEarningsForOrder(Order $order, ?string $reason = null): void
    {
        if ($order->status === 'cancelled' || $order->payment_status === 'refunded') {
            $this->reverseEarningsForOrder($order, $reason ?? 'Order cancelled or fully refunded');
        } elseif ($this->isEligible($order)) {
            $this->releaseEarningsForOrder($order);
        }
    }

    public function releaseEarningsForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = Order::with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $this->isEligible($locked)) return;
            $this->lockBeneficiaries($locked);
            foreach (EarningsLedger::where('order_id', $locked->id)->where('type', 'credit_pending')->orderBy('id')->get() as $credit) {
                if ($credit->status === 'available' || $this->hasEvent($credit, 'release_available') || $this->isCreditReversed($credit)) continue;
                $ledger = EarningsLedger::create([
                    'user_id' => $credit->user_id, 'order_id' => $credit->order_id, 'order_item_id' => $credit->order_item_id,
                    'source_ledger_id' => $credit->id, 'event_key' => "earning:release:credit:{$credit->id}",
                    'type' => 'release_available', 'amount' => $credit->amount, 'status' => 'available',
                    'description' => "Order #{$locked->order_number} is paid and delivered; pending earning released.", 'occurred_at' => now(),
                ]);
                $this->notifyEarning($ledger, $locked, 'earnings_released', 'Earnings available',
                    "৳{$credit->amount} from paid and delivered Order #{$locked->order_number} is available, subject to any recovery balance.");
            }
        }, 3);
    }

    public function reverseEarningsForOrder(Order $order, string $reason): void
    {
        DB::transaction(function () use ($order, $reason) {
            $locked = Order::with('items')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->lockBeneficiaries($locked);
            foreach (EarningsLedger::where('order_id', $locked->id)->where('type', 'credit_pending')->orderBy('id')->get() as $credit) {
                if ($this->isCreditReversed($credit)) continue;
                $ledger = EarningsLedger::create([
                    'user_id' => $credit->user_id, 'order_id' => $credit->order_id, 'order_item_id' => $credit->order_item_id,
                    'source_ledger_id' => $credit->id, 'event_key' => "earning:reversal:credit:{$credit->id}",
                    'type' => 'reversal', 'amount' => -abs($credit->amount), 'status' => 'reversed',
                    'description' => "Earning removed for Order #{$locked->order_number}: {$reason}", 'occurred_at' => now(),
                ]);
                $this->notifyEarning($ledger, $locked, 'earnings_reversed', 'Earnings reversed',
                    "৳{$credit->amount} for Order #{$locked->order_number} is no longer eligible: {$reason}. Any amount already paid is offset against future earnings.");
            }
        }, 3);
    }

    public function requestPayout(User $user, float $amount, string $method, array $accountDetails, ?string $idempotencyKey = null): PayoutRequest
    {
        if (! is_finite($amount) || $amount < self::MINIMUM_PAYOUT) {
            throw ValidationException::withMessages(['amount' => ['The minimum payout is ৳50.']]);
        }
        $amount = $this->minor($amount) / 100;
        return DB::transaction(function () use ($user, $amount, $method, $accountDetails, $idempotencyKey) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($idempotencyKey) {
                $existing = PayoutRequest::where('user_id', $lockedUser->id)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    if ($this->minor($existing->amount) !== $this->minor($amount) || $existing->payout_method !== $method || $existing->account_details !== $accountDetails) {
                        throw ValidationException::withMessages(['idempotency_key' => ['This request key already belongs to a different withdrawal.']]);
                    }
                    return $existing;
                }
            }
            $balances = $this->getUserBalances($lockedUser);
            if ($balances['reconciliation_required']) {
                throw ValidationException::withMessages(['amount' => ['Your earnings need administrative reconciliation before withdrawal.']]);
            }
            if ($this->minor($amount) > $this->minor($balances['available'])) {
                throw ValidationException::withMessages(['amount' => ["Requested amount exceeds the available balance of ৳{$balances['available']}."]]);
            }
            $payout = PayoutRequest::create([
                'user_id' => $lockedUser->id, 'idempotency_key' => $idempotencyKey, 'amount' => $amount,
                'status' => 'pending', 'payout_method' => $method, 'account_details' => $accountDetails,
            ]);
            EarningsLedger::create([
                'user_id' => $lockedUser->id, 'payout_request_id' => $payout->id,
                'event_key' => "payout:reserve:{$payout->id}", 'type' => 'payout_reserved',
                'amount' => -$amount, 'status' => 'reserved',
                'description' => "Withdrawal #{$payout->id} requested via {$method}; funds reserved, not yet paid.", 'occurred_at' => now(),
            ]);
            $this->logService->record(event: 'payout.requested', outcome: 'success', actor: $lockedUser, subject: $payout,
                metadata: ['payout_id' => $payout->id, 'amount' => $amount, 'method' => $method]);
            return $payout;
        }, 3);
    }

    public function processPayout(PayoutRequest $payout, string $action, User $admin, ?string $txRef = null, ?string $notes = null): PayoutRequest
    {
        if (! $admin->isAdmin() || ! in_array($action, ['paid', 'rejected'], true)) {
            throw ValidationException::withMessages(['action' => ['Only an authorized administrator can pay or reject a withdrawal.']]);
        }
        $txRef = $txRef === null ? null : trim($txRef);
        if ($action === 'paid' && $txRef === '') {
            throw ValidationException::withMessages(['transaction_reference' => ['A transaction reference is required to record an actual payout.']]);
        }
        if ($action === 'paid' && $txRef === null) {
            throw ValidationException::withMessages(['transaction_reference' => ['A transaction reference is required to record an actual payout.']]);
        }
        return DB::transaction(function () use ($payout, $action, $admin, $txRef, $notes) {
            $user = User::whereKey($payout->user_id)->lockForUpdate()->firstOrFail();
            $locked = PayoutRequest::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                if ($locked->status === $action && ($action !== 'paid' || $locked->transaction_reference === $txRef)) return $locked;
                throw ValidationException::withMessages(['action' => ["This withdrawal is already {$locked->status}; its financial outcome cannot be changed."]]);
            }
            $balances = $this->getUserBalances($user);
            if ($action === 'paid' && ($balances['reconciliation_required'] || $balances['payout_shortfall'] > 0)) {
                throw ValidationException::withMessages(['action' => ['This withdrawal is underfunded after an earnings reversal or needs reconciliation. Reject it to release its reservation; do not mark it paid.']]);
            }
            $locked->update(['status' => $action, 'processed_by_admin_id' => $admin->id,
                'transaction_reference' => $action === 'paid' ? $txRef : null, 'admin_notes' => $notes, 'processed_at' => now()]);
            $reservation = EarningsLedger::where('payout_request_id', $locked->id)->whereIn('type', ['payout_reserved', 'payout_paid'])->orderBy('id')->first();
            EarningsLedger::create([
                'user_id' => $user->id, 'payout_request_id' => $locked->id, 'source_ledger_id' => $reservation?->id,
                'event_key' => "payout:{$action}:{$locked->id}", 'type' => $action === 'paid' ? 'payout_paid' : 'payout_rejected',
                'amount' => $action === 'paid' ? -$locked->amount : $locked->amount,
                'status' => $action === 'paid' ? 'paid' : 'available',
                'description' => $action === 'paid' ? "Withdrawal #{$locked->id} recorded as paid (reference: {$txRef})."
                    : "Withdrawal #{$locked->id} rejected; reservation released. " . ($notes ?? ''), 'occurred_at' => now(),
            ]);
            $this->logService->record(event: "payout.{$action}", outcome: 'success', actor: $admin, subject: $locked,
                metadata: ['payout_id' => $locked->id, 'amount' => $locked->amount, 'transaction_reference' => $txRef, 'reason' => $notes]);
            $this->notificationService->sendToUser(user: $user, event: "community.payout_{$action}",
                title: $action === 'paid' ? 'Payout recorded' : 'Withdrawal declined',
                message: $action === 'paid' ? "Your withdrawal of ৳{$locked->amount} was recorded as paid. Reference: {$txRef}."
                    : "Withdrawal #{$locked->id} was declined. Its reservation was released; available funds reflect any reversals. " . ($notes ?? ''),
                subject: $locked, actionUrl: '/earnings', audience: 'customer', dedupKey: "payout_{$action}_{$locked->id}");
            return $locked->fresh();
        }, 3);
    }

    protected function isEligible(Order $order): bool
    {
        return $order->status === 'delivered' && $order->payment_status === 'paid';
    }

    protected function lockBeneficiaries(Order $order): void
    {
        $ids = $order->items->pluck('beneficiary_user_id')->filter()->unique()->sort()->values();
        if ($ids->isNotEmpty()) User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    protected function hasEvent(EarningsLedger $credit, string $type): bool
    {
        return EarningsLedger::where('user_id', $credit->user_id)->where('type', $type)
            ->where(function ($query) use ($credit) {
                $query->where('source_ledger_id', $credit->id);
                if ($credit->order_item_id) $query->orWhere(fn ($legacy) => $legacy->whereNull('source_ledger_id')->where('order_item_id', $credit->order_item_id));
            })->exists();
    }

    protected function isCreditReversed(EarningsLedger $credit): bool
    {
        return $credit->status === 'reversed' || $this->hasEvent($credit, 'reversal');
    }

    protected function minor(float|int|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    protected function notifyEarning(EarningsLedger $ledger, Order $order, string $event, string $title, string $message): void
    {
        $this->notificationService->sendToUser(user: $ledger->user_id, event: "community.{$event}", title: $title, message: $message,
            subject: $order, actionUrl: '/earnings', metadata: ['order_id' => $order->id, 'order_item_id' => $ledger->order_item_id, 'ledger_id' => $ledger->id, 'amount' => $ledger->amount],
            audience: 'customer', dedupKey: "earning_event_{$ledger->id}");
    }
}
