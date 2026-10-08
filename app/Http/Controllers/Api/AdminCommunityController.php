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
        $query = PayoutRequest::with(['user:id,name,phone,email,username', 'processedByAdmin:id,name']);

        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        $payouts = $query->latest('id')->paginate(20);

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

        if ($payout->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => "This payout request is already {$payout->status}.",
            ], 422);
        }

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
        $itemsQuery = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.status', ['processing', 'shipped', 'delivered']);

        if ($from = $request->input('from')) {
            $itemsQuery->where('orders.created_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $itemsQuery->where('orders.created_at', '<=', $to);
        }

        $totals = $itemsQuery->select(
            DB::raw('count(distinct orders.id) as total_orders'),
            DB::raw('sum(order_items.line_total) as gross_merchandise_value'),
            DB::raw('sum(order_items.supplier_allocation_price * order_items.quantity) as total_supplier_allocation'),
            DB::raw('sum(order_items.gross_markup * order_items.quantity) as total_gross_markup'),
            DB::raw('sum(order_items.platform_fee * order_items.quantity) as total_platform_fees'),
            DB::raw('sum(order_items.seller_earning * order_items.quantity) as total_community_seller_earnings'),
            DB::raw('sum(order_items.commission_amount * order_items.quantity) as total_recommendation_commissions')
        )->first();

        // Payout totals
        $payoutsTotal = PayoutRequest::where('status', 'paid')->sum('amount');
        $payoutsPending = PayoutRequest::where('status', 'pending')->sum('amount');

        return response()->json([
            'success' => true,
            'data' => [
                'total_orders' => (int) ($totals->total_orders ?? 0),
                'gross_merchandise_value' => round((float) ($totals->gross_merchandise_value ?? 0), 2),
                'total_supplier_allocation' => round((float) ($totals->total_supplier_allocation ?? 0), 2),
                'total_gross_markup' => round((float) ($totals->total_gross_markup ?? 0), 2),
                'total_platform_fees' => round((float) ($totals->total_platform_fees ?? 0), 2),
                'total_community_seller_earnings' => round((float) ($totals->total_community_seller_earnings ?? 0), 2),
                'total_recommendation_commissions' => round((float) ($totals->total_recommendation_commissions ?? 0), 2),
                'payouts_completed' => round((float) $payoutsTotal, 2),
                'payouts_pending_review' => round((float) $payoutsPending, 2),
            ],
        ]);
    }
}
