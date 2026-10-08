<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CommunityCommercialService;
use App\Services\EarningsService;
use App\Services\LogService;
use App\Services\NotificationService;
use App\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(
        protected OrderStatusService $orderStatusService,
        protected CommunityCommercialService $commercialService,
        protected EarningsService $earningsService
    ) {}

    /**
     * Revalidate and quote checkout totals server-side. Never trusts client calculations.
     */
    public function quote(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.purchase_mode' => 'required|in:normal,group_buy',
            'items.*.campaign_id' => 'nullable|exists:group_buy_campaigns,id',
            'items.*.shop_listing_id' => 'nullable|exists:shop_listings,id',
            'items.*.community_shop_id' => 'nullable|exists:community_shops,id',
            'items.*.recommendation_code' => 'nullable|string',
            'items.*.recommender_id' => 'nullable|exists:users,id',
        ]);

        $itemsQuote = [];
        $subtotal = 0.00;
        $maxShippingCharge = 0.00;
        $allFreeShipping = true;
        $buyer = $request->user();

        foreach ($request->input('items') as $line) {
            $product = Product::with('activeVariants')->findOrFail($line['product_id']);
            $variant = ! empty($line['variant_id']) ? ProductVariant::findOrFail($line['variant_id']) : null;
            $quantity = (int) $line['quantity'];
            $mode = $line['purchase_mode'];

            // Stock check
            $availableStock = $variant ? $variant->stock_quantity : $product->stock_quantity;
            if ($product->track_inventory && $availableStock < $quantity) {
                throw ValidationException::withMessages([
                    'items' => ["Insufficient stock for '{$product->title}'. Available: {$availableStock}"],
                ]);
            }

            // Resolve attribution & commercial pricing
            $attrib = $this->commercialService->resolveLineAttribution($line, $product, $variant, $buyer);

            // Price determination
            if ($mode === 'group_buy') {
                if (empty($line['campaign_id'])) {
                    throw ValidationException::withMessages([
                        'items' => ["A valid active drop campaign must be specified for group buy items."],
                    ]);
                }
                $campaign = GroupBuyCampaign::findOrFail($line['campaign_id']);
                if ($campaign->status !== 'active' || $campaign->isExpired()) {
                    throw ValidationException::withMessages([
                        'items' => ["The drop campaign '{$campaign->title}' is no longer active."],
                    ]);
                }
                $unitPrice = (float) $campaign->group_price;
            } else {
                if (! $product->is_normal_purchase_enabled && $attrib['earning_model'] !== 'community_shop') {
                    throw ValidationException::withMessages([
                        'items' => ["Direct purchase is currently disabled for '{$product->title}'."],
                    ]);
                }
                $unitPrice = (float) $attrib['unit_price'];
            }

            $lineTotal = round($unitPrice * $quantity, 2);
            $subtotal += $lineTotal;

            if (! $product->is_free_shipping) {
                $allFreeShipping = false;
                $maxShippingCharge = max($maxShippingCharge, (float) ($product->shipping_charge ?? 60.00));
            }

            $itemsQuote[] = [
                'product_id' => $product->id,
                'title' => $product->title,
                'variant_id' => $variant?->id,
                'variant_name' => $variant?->name,
                'purchase_mode' => $mode,
                'earning_model' => $attrib['earning_model'],
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
                'seller_earning_preview' => $attrib['seller_earning'],
                'commission_preview' => $attrib['commission_amount'],
            ];
        }

        $shippingFee = $allFreeShipping ? 0.00 : ($maxShippingCharge > 0 ? $maxShippingCharge : 60.00);
        $totalAmount = $subtotal + $shippingFee;

        return response()->json([
            'success' => true,
            'data' => [
                'subtotal' => round($subtotal, 2),
                'shipping_fee' => round($shippingFee, 2),
                'discount_amount' => 0.00,
                'total_amount' => round($totalAmount, 2),
                'currency' => 'BDT',
                'items' => $itemsQuote,
            ],
        ]);
    }

    /**
     * Atomically place an order and reserve inventory / group buy participation.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $validated = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.variant_id' => 'nullable|exists:product_variants,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.purchase_mode' => 'required|in:normal,group_buy',
                'items.*.campaign_id' => 'nullable|exists:group_buy_campaigns,id',
                'items.*.shop_listing_id' => 'nullable|exists:shop_listings,id',
                'items.*.community_shop_id' => 'nullable|exists:community_shops,id',
                'items.*.recommendation_code' => 'nullable|string',
                'items.*.recommender_id' => 'nullable|exists:users,id',
                'payment_method' => 'required|in:cod,bkash,nagad',
                'address_id' => 'nullable|integer',
                'shipping_name' => 'required_without:address_id|nullable|string|max:100',
                'shipping_phone' => 'required_without:address_id|nullable|string|max:30',
                'shipping_district' => 'required_without:address_id|nullable|string|max:50',
                'shipping_upazila' => 'required_without:address_id|nullable|string|max:50',
                'shipping_address' => 'required_without:address_id|nullable|string',
                'notes' => 'nullable|string|max:500',
            ]);

            $order = DB::transaction(function () use ($validated, $user) {
                $subtotal = 0.00;
                $maxShippingCharge = 0.00;
                $allFreeShipping = true;
                $sellerId = null;
                $orderItemsData = [];
                $groupParticipantsToCreate = [];
                $campaignsToInspect = [];

                foreach ($validated['items'] as $line) {
                    // Lock product & variant rows to prevent race condition overselling
                    /** @var Product $product */
                    $product = Product::where('id', $line['product_id'])->lockForUpdate()->firstOrFail();
                    $variant = ! empty($line['variant_id'])
                        ? ProductVariant::where('id', $line['variant_id'])->lockForUpdate()->firstOrFail()
                        : null;

                    $quantity = (int) $line['quantity'];
                    $mode = $line['purchase_mode'];
                    $sellerId = $product->seller_id;

                    // Inventory verification & deduction
                    if ($product->track_inventory) {
                        $available = $variant ? $variant->stock_quantity : $product->stock_quantity;
                        if ($available < $quantity) {
                            throw ValidationException::withMessages([
                                'items' => ["The item '{$product->title}' has only {$available} in stock."],
                            ]);
                        }

                        if ($variant) {
                            $variant->decrement('stock_quantity', $quantity);
                            $product->decrement('stock_quantity', $quantity);
                        } else {
                            $product->decrement('stock_quantity', $quantity);
                        }
                    }

                    // Resolve attribution & commercial allocation
                    $attrib = $this->commercialService->resolveLineAttribution($line, $product, $variant, $user);

                    // Determine price & handle group campaign
                    if ($mode === 'group_buy') {
                        $campaign = GroupBuyCampaign::where('id', $line['campaign_id'])->lockForUpdate()->firstOrFail();
                        if ($campaign->status !== 'active' || $campaign->isExpired()) {
                            throw ValidationException::withMessages([
                                'items' => ["The drop campaign '{$campaign->title}' has ended."],
                            ]);
                        }

                        // Enforce limit per customer
                        $existingQty = GroupBuyParticipant::where('campaign_id', $campaign->id)
                            ->where('user_id', $user->id)
                            ->whereIn('status', ['reserved', 'confirmed'])
                            ->sum('quantity');

                        if (($existingQty + $quantity) > $campaign->quantity_limit_per_customer) {
                            throw ValidationException::withMessages([
                                'items' => ["Maximum allowed quantity for this drop is {$campaign->quantity_limit_per_customer} per customer."],
                            ]);
                        }

                        $unitPrice = (float) $campaign->group_price;

                        $groupParticipantsToCreate[] = [
                            'campaign' => $campaign,
                            'variant_id' => $variant?->id,
                            'quantity' => $quantity,
                            'unit_price' => $unitPrice,
                        ];

                        $campaignsToInspect[$campaign->id] = $campaign;
                    } else {
                        if (! $product->is_normal_purchase_enabled && $attrib['earning_model'] !== 'community_shop') {
                            throw ValidationException::withMessages([
                                'items' => ["Direct purchase is currently disabled for '{$product->title}'."],
                            ]);
                        }
                        $unitPrice = (float) $attrib['unit_price'];
                    }

                    $lineTotal = round($unitPrice * $quantity, 2);
                    $subtotal += $lineTotal;

                    if (! $product->is_free_shipping) {
                        $allFreeShipping = false;
                        $maxShippingCharge = max($maxShippingCharge, (float) ($product->shipping_charge ?? 60.00));
                    }

                    $orderItemsData[] = [
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'purchase_mode' => $mode,
                        'group_buy_campaign_id' => $line['campaign_id'] ?? null,
                        'product_title' => $product->title,
                        'product_sku' => $variant?->sku ?? $product->sku,
                        'variant_name' => $variant?->name,
                        'variant_attributes' => $variant?->attributes,
                        'unit_price' => $unitPrice,
                        'quantity' => $quantity,
                        'line_total' => $lineTotal,
                        // Attribution & Commercial snapshot
                        'earning_model' => $attrib['earning_model'],
                        'community_shop_id' => $attrib['community_shop_id'],
                        'shop_listing_id' => $attrib['shop_listing_id'],
                        'recommender_id' => $attrib['recommender_id'],
                        'recommendation_code' => $attrib['recommendation_code'],
                        'beneficiary_user_id' => $attrib['beneficiary_user_id'],
                        'supplier_allocation_price' => $attrib['supplier_allocation_price'],
                        'gross_markup' => $attrib['gross_markup'],
                        'platform_fee' => $attrib['platform_fee'],
                        'seller_earning' => $attrib['seller_earning'],
                        'commission_amount' => $attrib['commission_amount'],
                        'commercial_terms_snapshot' => $attrib['terms_snapshot'],
                    ];
                }

                $shippingFee = $allFreeShipping ? 0.00 : ($maxShippingCharge > 0 ? $maxShippingCharge : 60.00);
                $totalAmount = $subtotal + $shippingFee;

                // Determine initial status based on purchase modes
                $hasActiveGroupBuy = false;
                foreach ($validated['items'] as $line) {
                    if ($line['purchase_mode'] === 'group_buy' && ! empty($line['campaign_id'])) {
                        $camp = $campaignsToInspect[$line['campaign_id']] ?? null;
                        if ($camp && $camp->status !== 'succeeded') {
                            $hasActiveGroupBuy = true;
                        }
                    }
                }
                $initialStatus = $hasActiveGroupBuy ? 'awaiting_group' : 'pending';

                // Generate order number
                $orderNumber = 'JB-ORD-' . date('Ymd') . '-' . strtoupper(Str::random(5));

                // Resolve immutable shipping snapshot
                if (! empty($validated['address_id'])) {
                    $savedAddress = \App\Models\UserAddress::where('user_id', $user->id)->findOrFail($validated['address_id']);
                    $shippingName = $validated['shipping_name'] ?? $savedAddress->recipient_name;
                    $shippingPhone = $validated['shipping_phone'] ?? $savedAddress->recipient_phone;
                    $shippingDistrict = $validated['shipping_district'] ?? $savedAddress->locality_district;
                    $shippingUpazila = $validated['shipping_upazila'] ?? $savedAddress->sub_district_thana;
                    $shippingAddress = $validated['shipping_address'] ?? $savedAddress->street_address;
                } else {
                    $shippingName = $validated['shipping_name'];
                    $shippingPhone = $validated['shipping_phone'];
                    $shippingDistrict = $validated['shipping_district'];
                    $shippingUpazila = $validated['shipping_upazila'];
                    $shippingAddress = $validated['shipping_address'];
                }

                $order = Order::create([
                    'order_number' => $orderNumber,
                    'user_id' => $user->id,
                    'seller_id' => $sellerId ?? 1,
                    'status' => $initialStatus,
                    'payment_status' => 'unpaid',
                    'payment_method' => $validated['payment_method'],
                    'subtotal' => round($subtotal, 2),
                    'shipping_fee' => round($shippingFee, 2),
                    'discount_amount' => 0.00,
                    'total_amount' => round($totalAmount, 2),
                    'shipping_name' => $shippingName,
                    'shipping_phone' => $shippingPhone,
                    'shipping_district' => $shippingDistrict,
                    'shipping_upazila' => $shippingUpazila,
                    'shipping_address' => $shippingAddress,
                    'notes' => $validated['notes'] ?? null,
                ]);

                // Save order items
                foreach ($orderItemsData as $itemData) {
                    $itemData['order_id'] = $order->id;
                    OrderItem::create($itemData);
                }

                // Record pending earnings for community sellers / recommenders
                $this->earningsService->recordPendingEarningsForOrder($order);

                // Save group buy reservations
                foreach ($groupParticipantsToCreate as $gpData) {
                    GroupBuyParticipant::create([
                        'campaign_id' => $gpData['campaign']->id,
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'product_variant_id' => $gpData['variant_id'],
                        'quantity' => $gpData['quantity'],
                        'unit_price' => $gpData['unit_price'],
                        'status' => 'reserved',
                        'reserved_at' => now(),
                    ]);
                }

                // Record initial order status history
                $this->orderStatusService->recordInitialStatus(
                    order: $order,
                    initialStatus: $initialStatus,
                    userId: $user->id,
                    actorType: 'customer',
                    reason: $initialStatus === 'awaiting_group'
                        ? 'Volume drop reservation created; awaiting campaign target unlock.'
                        : 'Order placed successfully by customer.'
                );

                // Record business log inside transaction (rolled back if transaction fails)
                app(LogService::class)->record(
                    event: 'order.created',
                    outcome: 'success',
                    actor: $user,
                    subject: $order,
                    metadata: [
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'total_amount' => (float) $order->total_amount,
                        'item_count' => count($validated['items']),
                        'payment_method' => $order->payment_method,
                    ],
                    message: "Order #{$order->order_number} was placed successfully."
                );

                // In-App Notification: Customer order confirmation
                app(NotificationService::class)->sendToUser(
                    user: $user,
                    event: 'order.created',
                    title: "Order Placed: #{$order->order_number}",
                    message: "Your order #{$order->order_number} has been received and is being processed (BDT {$order->total_amount}).",
                    subject: $order,
                    actionUrl: "/orders/{$order->id}",
                    audience: 'customer',
                    dedupKey: "order_created_cust_{$order->id}"
                );

                // In-App Notification: Notify authorized administrators
                app(NotificationService::class)->sendToAdmins(
                    event: 'order.created_admin',
                    title: "New Order: #{$order->order_number}",
                    message: "New order #{$order->order_number} placed by {$user->name} for BDT {$order->total_amount}.",
                    subject: $order,
                    actionUrl: '/admin/orders',
                    dedupKey: "order_created_admin_{$order->id}"
                );

                // In-App Notification: Group buy reservation notice
                foreach ($groupParticipantsToCreate as $gpData) {
                    $camp = $gpData['campaign'];
                    app(NotificationService::class)->sendToUser(
                        user: $user,
                        event: 'group_buy.joined',
                        title: "Joined Drop: {$camp->title} 🎯",
                        message: "You reserved {$gpData['quantity']} unit(s) in drop '{$camp->title}'. Awaiting target unlock.",
                        subject: $camp,
                        actionUrl: "/orders/{$order->id}",
                        audience: 'customer',
                        dedupKey: "gb_joined_{$camp->id}_{$user->id}_{$order->id}"
                    );
                }

                // Inspect campaigns: If target reached, mark succeeded!
                foreach ($campaignsToInspect as $camp) {
                    $distinctCount = GroupBuyParticipant::where('campaign_id', $camp->id)
                        ->whereIn('status', ['reserved', 'confirmed'])
                        ->distinct('user_id')
                        ->count('user_id');

                    if ($distinctCount >= $camp->target_participants && $camp->status !== 'succeeded') {
                        $camp->update([
                            'status' => 'succeeded',
                            'success_at' => now(),
                        ]);

                        // Confirm reservations
                        GroupBuyParticipant::where('campaign_id', $camp->id)
                            ->where('status', 'reserved')
                            ->update(['status' => 'confirmed']);

                        // Unlock all awaiting orders for this campaign
                        $this->orderStatusService->handleGroupCampaignUnlocked($camp);
                    }
                }

                return $order;
            });
        } catch (\Throwable $e) {
            // Record failure outside of the rolled-back transaction
            app(LogService::class)->record(
                event: 'order.creation_failed',
                outcome: 'failure',
                actor: $user,
                subject: null,
                metadata: [
                    'failure_reason' => $e->getMessage(),
                    'item_count' => count($request->input('items', [])),
                    'payment_method' => $request->input('payment_method'),
                ],
                message: 'Order placement failed: ' . $e->getMessage()
            );

            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Order placed successfully.',
            'data' => $order->fresh([
                'items',
                'seller:id,store_name',
                'statusHistories' => fn ($q) => $q->forCustomer(),
            ]),
        ], 201);
    }

    /**
     * List authenticated customer's orders.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::where('user_id', $request->user()->id)
            ->with(['items', 'seller:id,store_name'])
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders->getCollection(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Show single order details with customer-safe status timeline.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)
            ->with([
                'items',
                'seller:id,store_name,contact_phone',
                'statusHistories' => function ($q) {
                    $q->forCustomer()->orderBy('occurred_at', 'asc')->orderBy('id', 'asc');
                },
            ])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    /**
     * Paginated status history access for authenticated customer.
     */
    public function history(Request $request, int $id): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)->findOrFail($id);

        $histories = $order->statusHistories()
            ->forCustomer()
            ->orderBy('occurred_at', 'asc')
            ->orderBy('id', 'asc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $histories->getCollection(),
            'meta' => [
                'current_page' => $histories->currentPage(),
                'last_page' => $histories->lastPage(),
                'total' => $histories->total(),
            ],
        ]);
    }

    /**
     * Customer order cancellation within cutoff hours.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)->findOrFail($id);

        if (! in_array($order->status, ['pending', 'awaiting_group'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Orders that have begun processing or shipment cannot be cancelled online.',
            ], 422);
        }

        $reason = $request->input('reason', 'Cancelled by customer');

        $this->orderStatusService->transition(
            orderOrId: $order,
            toStatus: 'cancelled',
            actorType: 'customer',
            actor: $request->user(),
            reason: $reason
        );

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully and inventory released.',
            'data' => $order->fresh([
                'items',
                'statusHistories' => fn ($q) => $q->forCustomer(),
            ]),
        ]);
    }
}
