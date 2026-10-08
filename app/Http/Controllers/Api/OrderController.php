<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
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
        ]);

        $itemsQuote = [];
        $subtotal = 0.00;
        $maxShippingCharge = 0.00;
        $allFreeShipping = true;

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
                if (! $product->is_normal_purchase_enabled) {
                    throw ValidationException::withMessages([
                        'items' => ["Direct purchase is currently disabled for '{$product->title}'."],
                    ]);
                }
                $unitPrice = $variant ? (float) $variant->effective_price : (float) $product->base_price;
            }

            $lineTotal = $unitPrice * $quantity;
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
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
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
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.purchase_mode' => 'required|in:normal,group_buy',
            'items.*.campaign_id' => 'nullable|exists:group_buy_campaigns,id',
            'payment_method' => 'required|in:cod,bkash,nagad',
            'shipping_name' => 'required|string|max:100',
            'shipping_phone' => 'required|string|max:30',
            'shipping_district' => 'required|string|max:50',
            'shipping_upazila' => 'required|string|max:50',
            'shipping_address' => 'required|string',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = $request->user();

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
                    $unitPrice = $variant ? (float) $variant->effective_price : (float) $product->base_price;
                }

                $lineTotal = $unitPrice * $quantity;
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
                ];
            }

            $shippingFee = $allFreeShipping ? 0.00 : ($maxShippingCharge > 0 ? $maxShippingCharge : 60.00);
            $totalAmount = $subtotal + $shippingFee;

            // Generate order number
            $orderNumber = 'JB-ORD-' . date('Ymd') . '-' . strtoupper(Str::random(5));

            $order = Order::create([
                'order_number' => $orderNumber,
                'user_id' => $user->id,
                'seller_id' => $sellerId ?? 1,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'payment_method' => $validated['payment_method'],
                'subtotal' => round($subtotal, 2),
                'shipping_fee' => round($shippingFee, 2),
                'discount_amount' => 0.00,
                'total_amount' => round($totalAmount, 2),
                'shipping_name' => $validated['shipping_name'],
                'shipping_phone' => $validated['shipping_phone'],
                'shipping_district' => $validated['shipping_district'],
                'shipping_upazila' => $validated['shipping_upazila'],
                'shipping_address' => $validated['shipping_address'],
                'notes' => $validated['notes'] ?? null,
            ]);

            // Save order items
            foreach ($orderItemsData as $itemData) {
                $itemData['order_id'] = $order->id;
                OrderItem::create($itemData);
            }

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

            // Inspect campaigns: If target reached, mark succeeded!
            foreach ($campaignsToInspect as $camp) {
                $distinctCount = GroupBuyParticipant::where('campaign_id', $camp->id)
                    ->whereIn('status', ['reserved', 'confirmed'])
                    ->distinct('user_id')
                    ->count('user_id');

                if ($distinctCount >= $camp->target_participants) {
                    $camp->update([
                        'status' => 'succeeded',
                        'success_at' => now(),
                    ]);

                    // Confirm reservations
                    GroupBuyParticipant::where('campaign_id', $camp->id)
                        ->where('status', 'reserved')
                        ->update(['status' => 'confirmed']);
                }
            }

            return $order;
        });

        return response()->json([
            'success' => true,
            'message' => 'Order placed successfully.',
            'data' => $order->load(['items', 'seller:id,store_name']),
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
     * Show single order details for authenticated customer.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)
            ->with(['items', 'seller:id,store_name,contact_phone'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    /**
     * Customer order cancellation within cutoff hours.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)->findOrFail($id);

        if ($order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Orders that have begun processing or shipment cannot be cancelled online.',
            ], 422);
        }

        DB::transaction(function () use ($order) {
            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'Cancelled by customer',
            ]);

            // Release inventory
            foreach ($order->items as $item) {
                if ($item->product_variant_id) {
                    ProductVariant::where('id', $item->product_variant_id)->increment('stock_quantity', $item->quantity);
                }
                Product::where('id', $item->product_id)->increment('stock_quantity', $item->quantity);
            }

            // Release group reservations if any
            GroupBuyParticipant::where('order_id', $order->id)->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully and inventory released.',
        ]);
    }
}
