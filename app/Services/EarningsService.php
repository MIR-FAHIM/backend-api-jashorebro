<?php

namespace App\Services;

use App\Models\EarningsLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayoutRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EarningsService
{
    public function __construct(
        protected NotificationService $notificationService,
        protected LogService $logService
    ) {}

    /**
     * Compute real balances from the append-only ledger.
     */
    public function getUserBalances(User|int $userOrId): array
    {
        $userId = $userOrId instanceof User ? $userOrId->id : $userOrId;

        // Pending earnings: credit_pending entries not yet released or reversed
        $pending = (float) EarningsLedger::where('user_id', $userId)
            ->where('status', 'pending')
            ->sum('amount');

        // Released available earnings
        $released = (float) EarningsLedger::where('user_id', $userId)
            ->where('status', 'available')
            ->sum('amount');

        // Reserved for pending payouts
        $reserved = (float) EarningsLedger::where('user_id', $userId)
            ->where('status', 'reserved')
            ->sum(DB::raw('abs(amount)'));

        // Completed paid payouts
        $paid = (float) EarningsLedger::where('user_id', $userId)
            ->where('status', 'paid')
            ->sum(DB::raw('abs(amount)'));

        // Reversals (refunds/cancellations)
        $reversed = (float) EarningsLedger::where('user_id', $userId)
            ->where('status', 'reversed')
            ->sum(DB::raw('abs(amount)'));

        // Net available = released - reserved - paid - reversed
        // Or if ledger stores positive credits and negative debits:
        $netAvailable = max(0.00, round($released - $reserved - $paid - $reversed, 2));

        $outstandingRecovery = 0.00;
        if (($released - $reserved - $paid - $reversed) < 0) {
            $outstandingRecovery = round(abs($released - $reserved - $paid - $reversed), 2);
        }

        $totalEarned = round($released + $paid, 2);

        return [
            'pending' => round($pending, 2),
            'available' => $netAvailable,
            'reserved_for_payout' => round($reserved, 2),
            'paid' => round($paid, 2),
            'reversed' => round($reversed, 2),
            'total_earned' => $totalEarned,
            'outstanding_recovery' => $outstandingRecovery,
        ];
    }

    /**
     * Record pending earnings for an attributed order when order is placed.
     */
    public function recordPendingEarningsForOrder(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if (! $item->beneficiary_user_id) {
                continue;
            }

            // Calculate total line earning
            $unitEarning = $item->earning_model === 'recommendation'
                ? (float) $item->commission_amount
                : (float) $item->seller_earning;

            $totalEarning = round($unitEarning * (int) $item->quantity, 2);

            if ($totalEarning <= 0.00) {
                continue;
            }

            // Check if already recorded (idempotency)
            $existing = EarningsLedger::where('order_item_id', $item->id)
                ->where('type', 'credit_pending')
                ->first();

            if ($existing) {
                continue;
            }

            EarningsLedger::create([
                'user_id' => $item->beneficiary_user_id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'type' => 'credit_pending',
                'amount' => $totalEarning,
                'status' => 'pending',
                'description' => "Pending {$item->earning_model} earning for Order #{$order->order_number} ({$item->product_title})",
                'occurred_at' => now(),
            ]);

            // Notify beneficiary of attributed sale
            $beneficiary = User::find($item->beneficiary_user_id);
            if ($beneficiary) {
                $this->notificationService->sendToUser(
                    user: $beneficiary,
                    event: 'community.sale_attributed',
                    title: "New Sale Attributed!",
                    message: "A sale of '{$item->product_title}' (৳{$totalEarning} pending earnings) was attributed to your {$item->earning_model}.",
                    subject: $order,
                    actionUrl: "/profile",
                    metadata: [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'earning_model' => $item->earning_model,
                        'earning_amount' => $totalEarning,
                    ],
                    audience: 'customer',
                    dedupKey: "sale_attrib_{$order->id}_{$item->id}"
                );
            }
        }
    }

    /**
     * Release pending earnings to available balance when order is delivered.
     */
    public function releaseEarningsForOrder(Order $order): void
    {
        $pendingLedgers = EarningsLedger::where('order_id', $order->id)
            ->where('status', 'pending')
            ->get();

        foreach ($pendingLedgers as $ledger) {
            $ledger->update(['status' => 'available']);

            $beneficiary = User::find($ledger->user_id);
            if ($beneficiary) {
                $this->notificationService->sendToUser(
                    user: $beneficiary,
                    event: 'community.earnings_released',
                    title: "Earnings Available!",
                    message: "৳{$ledger->amount} from Order #{$order->order_number} has completed delivery and is now available for withdrawal.",
                    subject: $order,
                    actionUrl: "/profile",
                    metadata: [
                        'order_id' => $order->id,
                        'amount' => $ledger->amount,
                    ],
                    audience: 'customer',
                    dedupKey: "earnings_release_{$ledger->id}"
                );
            }
        }
    }

    /**
     * Reverse earnings when an order is cancelled or refunded.
     */
    public function reverseEarningsForOrder(Order $order, string $reason): void
    {
        $ledgers = EarningsLedger::where('order_id', $order->id)
            ->whereIn('status', ['pending', 'available'])
            ->get();

        foreach ($ledgers as $ledger) {
            $originalAmount = $ledger->amount;
            $ledger->update(['status' => 'reversed']);

            EarningsLedger::create([
                'user_id' => $ledger->user_id,
                'order_id' => $order->id,
                'order_item_id' => $ledger->order_item_id,
                'type' => 'reversal',
                'amount' => -$originalAmount,
                'status' => 'reversed',
                'description' => "Reversed earnings for Order #{$order->order_number}: {$reason}",
                'occurred_at' => now(),
            ]);

            $beneficiary = User::find($ledger->user_id);
            if ($beneficiary) {
                $this->notificationService->sendToUser(
                    user: $beneficiary,
                    event: 'community.earnings_reversed',
                    title: "Earnings Reversed",
                    message: "৳{$originalAmount} for Order #{$order->order_number} was reversed due to: {$reason}",
                    subject: $order,
                    actionUrl: "/profile",
                    metadata: [
                        'order_id' => $order->id,
                        'reversed_amount' => $originalAmount,
                        'reason' => $reason,
                    ],
                    audience: 'customer',
                    dedupKey: "earnings_rev_{$ledger->id}"
                );
            }
        }
    }

    /**
     * Request a withdrawal payout from available earnings.
     *
     * @throws ValidationException
     */
    public function requestPayout(User $user, float $amount, string $method, array $accountDetails): PayoutRequest
    {
        $amount = round($amount, 2);
        if ($amount <= 0.00) {
            throw ValidationException::withMessages([
                'amount' => ['Payout amount must be greater than 0.'],
            ]);
        }

        $balances = $this->getUserBalances($user);
        if ($amount > $balances['available']) {
            throw ValidationException::withMessages([
                'amount' => ["Requested amount (৳{$amount}) exceeds your available balance (৳{$balances['available']})."],
            ]);
        }

        return DB::transaction(function () use ($user, $amount, $method, $accountDetails) {
            $payout = PayoutRequest::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'status' => 'pending',
                'payout_method' => $method,
                'account_details' => $accountDetails,
            ]);

            // Reserve the funds in append-only ledger
            EarningsLedger::create([
                'user_id' => $user->id,
                'payout_request_id' => $payout->id,
                'type' => 'payout_reserved',
                'amount' => -$amount,
                'status' => 'reserved',
                'description' => "Withdrawal request #{$payout->id} placed via {$method}",
                'occurred_at' => now(),
            ]);

            $this->logService->record(
                event: 'payout.requested',
                outcome: 'success',
                actor: $user,
                subject: $payout,
                metadata: [
                    'payout_id' => $payout->id,
                    'amount' => $amount,
                    'method' => $method,
                ]
            );

            return $payout;
        });
    }

    /**
     * Process a withdrawal payout by an authorized admin.
     */
    public function processPayout(
        PayoutRequest $payout,
        string $action, // paid, rejected
        User $admin,
        ?string $txRef = null,
        ?string $notes = null
    ): PayoutRequest {
        return DB::transaction(function () use ($payout, $action, $admin, $txRef, $notes) {
            $user = $payout->user;

            if ($action === 'paid') {
                $payout->update([
                    'status' => 'paid',
                    'processed_by_admin_id' => $admin->id,
                    'transaction_reference' => $txRef,
                    'admin_notes' => $notes,
                    'processed_at' => now(),
                ]);

                // Update ledger from reserved to paid
                EarningsLedger::where('payout_request_id', $payout->id)
                    ->where('status', 'reserved')
                    ->update(['status' => 'paid', 'type' => 'payout_paid']);

                $this->notificationService->sendToUser(
                    user: $user,
                    event: 'community.payout_completed',
                    title: "Payout Dispatched!",
                    message: "Your payout of ৳{$payout->amount} via {$payout->payout_method} has been processed (Tx: {$txRef}).",
                    subject: $payout,
                    actionUrl: "/profile",
                    metadata: [
                        'payout_id' => $payout->id,
                        'amount' => $payout->amount,
                        'tx_ref' => $txRef,
                    ],
                    audience: 'customer',
                    dedupKey: "payout_paid_{$payout->id}"
                );
            } elseif ($action === 'rejected') {
                $payout->update([
                    'status' => 'rejected',
                    'processed_by_admin_id' => $admin->id,
                    'admin_notes' => $notes,
                    'processed_at' => now(),
                ]);

                // Refund reserved amount back to available balance in ledger
                EarningsLedger::where('payout_request_id', $payout->id)
                    ->where('status', 'reserved')
                    ->delete();

                $this->notificationService->sendToUser(
                    user: $user,
                    event: 'community.payout_rejected',
                    title: "Payout Request Declined",
                    message: "Your payout request of ৳{$payout->amount} was declined. Reason: {$notes}. Funds returned to your available balance.",
                    subject: $payout,
                    actionUrl: "/profile",
                    metadata: [
                        'payout_id' => $payout->id,
                        'amount' => $payout->amount,
                        'reason' => $notes,
                    ],
                    audience: 'customer',
                    dedupKey: "payout_rej_{$payout->id}"
                );
            }

            return $payout->fresh();
        });
    }
}
