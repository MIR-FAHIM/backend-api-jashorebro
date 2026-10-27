<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunityShop;
use App\Models\EarningsLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayoutRequest;
use App\Services\EarningsService;
use App\Services\LogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminCommunityController extends Controller
{
    public function __construct(
        protected EarningsService $earningsService,
        protected LogService $logService,
        protected NotificationService $notificationService
    ) {}

    /**
     * List all community shops with filters.
     */
    public function shops(Request $request): JsonResponse
    {
        $query = CommunityShop::with(['user:id,name,phone,email,username'])
            ->withCount(['listings', 'activeListings']);

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        $shops = $query->latest('id')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $shops->getCollection(),
            'meta' => [
                'current_page' => $shops->currentPage(),
                'last_page' => $shops->lastPage(),
                'total' => $shops->total(),
            ],
        ]);
    }

    /**
     * Update shop status (activate, suspend, verify).
     */
    public function updateShopStatus(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        $shop = CommunityShop::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:active,suspended,archived,draft',
            'is_verified' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $prevStatus = $shop->status;
        $shop->status = $validated['status'];
        if (isset($validated['is_verified'])) {
            $shop->is_verified = $validated['is_verified'];
        }
        $shop->save();

        $this->logService->record(
            event: 'shop.status_changed',
            outcome: 'success',
            actor: $admin,
            subject: $shop,
            metadata: [
                'shop_id' => $shop->id,
                'from_status' => $prevStatus,
                'to_status' => $shop->status,
                'reason' => $validated['reason'] ?? null,
            ]
        );

        // Notify shop owner
        $this->notificationService->sendToUser(
            user: $shop->user_id,
            event: 'community.shop_status_updated',
            title: "Shop Status: " . ucfirst($shop->status),
            message: "Your shop '{$shop->name}' status is now {$shop->status}." . ($validated['reason'] ? " Note: {$validated['reason']}" : ''),
            subject: $shop,
            actionUrl: '/my-shop',
            audience: 'customer',
            dedupKey: "notif_shop_status_{$shop->id}_{$shop->status}"
        );

        return response()->json([
            'success' => true,
            'message' => 'Shop status updated successfully.',
            'data' => $shop,
        ]);
    }

    /**
     * List all payout requests for administrative review.
     */
    public function payouts(Request $request): JsonResponse
    {
        $query = PayoutRequest::with(['user:id,name,phone,email,username', 'processedByAdmin:id,name', 'ledgers.sourceLedger']);

        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        $payouts = $query->latest('id')->paginate(20);
        $balances = [];
        $payouts->getCollection()->each(function ($payout) use (&$balances) {
            $balance = $balances[$payout->user_id] ??= $this->earningsService->getUserBalances($payout->user_id);
            $pending = $payout->status === 'pending';
            $review = $balance['reconciliation_required'];
            $underfunded = $balance['payout_shortfall'] > 0;
            $payout->setAttribute('account_balances', $balance);
            $payout->setAttribute('reconciliation_required', $review);
            $payout->setAttribute('can_pay', $pending && ! $review && ! $underfunded);
            $payout->setAttribute('funding_status', ! $pending ? 'processed' : ($review ? 'reconciliation_required' : ($underfunded ? 'underfunded' : 'funded')));
            $payout->setAttribute('funding_reason', ! $pending ? 'This withdrawal has already been processed.'
                : ($review ? 'Account history requires administrative reconciliation.'
                    : ($underfunded ? 'Current eligible earnings no longer cover paid and pending withdrawals. Reject the request to release its reservation.'
                        : 'Current paid-and-delivered earnings cover all paid and pending withdrawals.')));
        });

        return response()->json([
            'success' => true,
            'data' => $payouts->getCollection(),
            'meta' => [
                'current_page' => $payouts->currentPage(),
                'last_page' => $payouts->lastPage(),
                'total' => $payouts->total(),
            ],
        ]);
    }

    /**
     * Process a withdrawal payout request.
     */
    public function processPayout(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        $payout = PayoutRequest::findOrFail($id);

        $validated = $request->validate([
            'action' => 'required|in:paid,rejected',
            'transaction_reference' => 'required_if:action,paid|nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $processed = $this->earningsService->processPayout(
            payout: $payout,
            action: $validated['action'],
            admin: $admin,
            txRef: $validated['transaction_reference'] ?? null,
            notes: $validated['notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => "Payout request {$validated['action']} successfully.",
            'data' => $processed,
        ]);
    }

    /**
     * Comprehensive settlement and financial reporting connecting supplier allocation, community earnings, and platform fees.
     */
    public function settlementReport(Request $request): JsonResponse
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        if ($request->filled('from') && $request->filled('to')) {
            $request->validate(['to' => 'after_or_equal:from']);
        }
        $itemsQuery = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.status', 'delivered')->where('orders.payment_status', 'paid');

        if ($from = $request->input('from')) {
            $itemsQuery->whereDate('orders.created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $itemsQuery->whereDate('orders.created_at', '<=', $to);
        }

        $totals = $itemsQuery->select(
            DB::raw('count(distinct orders.id) as total_orders'),
            DB::raw('sum(order_items.line_total) as gross_merchandise_value'),
            DB::raw('sum(order_items.supplier_allocation_price * order_items.quantity) as total_supplier_allocation'),
            DB::raw('sum(order_items.gross_markup * order_items.quantity) as total_gross_markup'),
            DB::raw('sum(order_items.platform_fee * order_items.quantity) as total_platform_fees'),
            DB::raw("sum(case when order_items.earning_model = 'community_shop' then order_items.seller_earning * order_items.quantity else 0 end) as total_community_seller_earnings"),
            DB::raw("sum(case when order_items.earning_model = 'group_drop' then order_items.seller_earning * order_items.quantity else 0 end) as total_drop_commissions"),
            DB::raw('sum(order_items.commission_amount * order_items.quantity) as total_recommendation_commissions')
        )->first();

        $pendingOrders = Order::whereNotIn('status', ['cancelled'])->where('payment_status', '!=', 'refunded')
            ->where(fn ($query) => $query->where('status', '!=', 'delivered')->orWhere('payment_status', '!=', 'paid'));
        $paidPayouts = PayoutRequest::where('status', 'paid');
        $pendingPayouts = PayoutRequest::where('status', 'pending');
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if ($date = $request->input($field)) {
                $pendingOrders->whereDate('created_at', $operator, $date);
                $paidPayouts->whereDate('processed_at', $operator, $date);
                $pendingPayouts->whereDate('created_at', $operator, $date);
            }
        }
        // Current balances are all-time, independently of the report's order/payout interval.
        $balanceTotals = ['pending' => 0, 'available' => 0, 'reserved_for_payout' => 0, 'outstanding_recovery' => 0];
        $reviewAccounts = 0;
        $userIds = EarningsLedger::select('user_id')->union(PayoutRequest::select('user_id'))->pluck('user_id')->unique();
        foreach ($userIds as $userId) {
            $balance = $this->earningsService->getUserBalances((int) $userId);
            foreach (array_keys($balanceTotals) as $key) $balanceTotals[$key] += $balance[$key];
            if ($balance['reconciliation_required']) $reviewAccounts++;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_orders' => (int) ($totals->total_orders ?? 0),
                'gross_merchandise_value' => round((float) ($totals->gross_merchandise_value ?? 0), 2),
                'total_supplier_allocation' => round((float) ($totals->total_supplier_allocation ?? 0), 2),
                'total_gross_markup' => round((float) ($totals->total_gross_markup ?? 0), 2),
                'total_platform_fees' => round((float) ($totals->total_platform_fees ?? 0), 2),
                'total_community_seller_earnings' => round((float) ($totals->total_community_seller_earnings ?? 0), 2),
                'total_drop_commissions' => round((float) ($totals->total_drop_commissions ?? 0), 2),
                'total_recommendation_commissions' => round((float) ($totals->total_recommendation_commissions ?? 0), 2),
                'payouts_completed' => round((float) $paidPayouts->sum('amount'), 2),
                'payouts_pending_review' => round((float) $pendingPayouts->sum('amount'), 2),
                'pending_orders' => $pendingOrders->count(),
                'total_pending_balances' => round($balanceTotals['pending'], 2),
                'total_available_balances' => round($balanceTotals['available'], 2),
                'total_reserved_balances' => round($balanceTotals['reserved_for_payout'], 2),
                'total_recovery_balances' => round($balanceTotals['outstanding_recovery'], 2),
                'accounts_requiring_reconciliation' => $reviewAccounts,
                'eligibility_policy' => EarningsService::ELIGIBILITY_POLICY,
                'balance_scope' => 'current_all_time',
                'report_scope' => 'paid_delivered_orders_by_order_date_payouts_by_processed_date_pending_requests_by_request_date',
            ],
        ]);
    }
}
