<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\LogService;
use App\Services\NotificationService;
use App\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminPosController extends Controller
{
    public function __construct(
        protected OrderStatusService $orderStatusService,
        protected LogService $logService,
        protected NotificationService $notificationService
    ) {}

    /**
     * Search products for POS terminal by title, SKU, or barcode.
     */
    public function products(Request $request): JsonResponse
    {
        $query = Product::query()
            ->where('status', 'published')
            ->where('is_normal_purchase_enabled', true)
            ->with([
                'primaryImage',
                'category:id,name',
                'activeVariants' => function ($q) {
                    $q->where('is_active', true);
                },
            ]);

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('activeVariants', function ($vq) use ($search) {
                        $vq->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                    });
            });
        }

        if ($categoryId = $request->input('category_id')) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->latest('id')->limit(40)->get();

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Search existing registered customers by name, phone, or email.
     */
    public function customers(Request $request): JsonResponse
    {
        $query = User::query()->select(['id', 'name', 'phone', 'email', 'username', 'created_at']);

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $customers = $query->latest('id')->limit(30)->get();

        return response()->json([
            'success' => true,
            'data' => $customers,
        ]);
    }

    /**
     * Fetch saved addresses belonging to a specific customer.
     */
    public function customerAddresses(Request $request, int $customerId): JsonResponse
    {
        $customer = User::findOrFail($customerId);

        $addresses = UserAddress::where('user_id', $customer->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $addresses,
        ]);
    }

    /**
     * Create a new delivery address on behalf of a customer.
     */
    public function storeCustomerAddress(Request $request, int $customerId): JsonResponse
    {
        $admin = $request->user();
        $customer = User::findOrFail($customerId);

        $validated = $request->validate([
            'recipient_name' => 'required|string|max:100',
            'recipient_phone' => 'required|string|max:30',
            'country' => 'nullable|string|max:100',
            'district' => 'required_without:locality_district|nullable|string|max:100',
            'locality_district' => 'nullable|string|max:100',
            'upazila' => 'required_without:sub_district_thana|nullable|string|max:100',
            'sub_district_thana' => 'nullable|string|max:100',
            'street_address' => 'required|string|max:500',
            'landmark' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'delivery_instructions' => 'nullable|string|max:500',
            'label' => 'nullable|string|max:50',
            'is_default' => 'nullable|boolean',
        ]);

        $district = $validated['district'] ?? $validated['locality_district'] ?? 'Jashore';
        $upazila = $validated['upazila'] ?? $validated['sub_district_thana'] ?? 'Jashore Sadar';
        $country = $validated['country'] ?? 'Bangladesh';
        $label = ! empty($validated['label']) ? trim($validated['label']) : 'Home';

        $address = DB::transaction(function () use ($customer, $admin, $validated, $district, $upazila, $country, $label, $request) {
            $existingCount = UserAddress::where('user_id', $customer->id)->count();
            $isDefault = ($existingCount === 0) ? true : $request->boolean('is_default', false);

            if ($isDefault) {
                UserAddress::where('user_id', $customer->id)->update(['is_default' => false]);
            }

            $newAddress = UserAddress::create([
                'user_id' => $customer->id,
                'label' => $label,
                'recipient_name' => $validated['recipient_name'],
                'recipient_phone' => $validated['recipient_phone'],
                'country' => $country,
                'locality_district' => $district,
                'sub_district_thana' => $upazila,
                'street_address' => $validated['street_address'],
                'landmark' => $validated['landmark'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'delivery_instructions' => $validated['delivery_instructions'] ?? null,
                'is_default' => $isDefault,
            ]);

            $this->logService->record(
                event: 'address.created_by_admin',
                outcome: 'success',
                actor: $admin,
                subject: $newAddress,
                metadata: [
                    'customer_id' => $customer->id,
                    'address_id' => $newAddress->id,
                    'label' => $newAddress->label,
                    'district' => $newAddress->locality_district,
                    'is_default' => $newAddress->is_default,
                ]
            );

            return $newAddress;
        });

        return response()->json([
            'success' => true,
            'message' => 'Delivery address saved for customer.',
            'data' => $address,
        ], 201);
    }

    /**
     * Compute a real-time decimal-safe quote for the POS cart.
     */
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:users,id',
            'address_id' => 'nullable|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_reason' => 'nullable|string|max:255',
            'shipping_fee' => 'nullable|numeric|min:0',
        ]);

        $itemsQuote = [];
        $subtotal = 0.00;

        foreach ($validated['items'] as $line) {
            $product = Product::with('activeVariants')->findOrFail($line['product_id']);

            if ($product->status !== 'published' || ! $product->is_normal_purchase_enabled) {
                throw ValidationException::withMessages([
                    'items' => ["The product '{$product->title}' is not eligible for sale."],
                ]);
            }

            $hasActiveVariants = $product->activeVariants->isNotEmpty();
            if ($hasActiveVariants && empty($line['variant_id'])) {
                throw ValidationException::withMessages([
                    'items' => ["A variant selection is required for '{$product->title}'."],
                ]);
            }

            $variant = ! empty($line['variant_id'])
                ? ProductVariant::where('product_id', $product->id)->findOrFail($line['variant_id'])
                : null;

            $quantity = (int) $line['quantity'];
            $availableStock = $variant ? $variant->stock_quantity : $product->stock_quantity;

            if ($product->track_inventory && $availableStock < $quantity) {
                throw ValidationException::withMessages([
                    'items' => ["Insufficient stock for '{$product->title}'" . ($variant ? " ({$variant->name})" : "") . ". Available: {$availableStock}."],
                ]);
            }

            $unitPrice = $variant ? (float) $variant->effective_price : (float) $product->base_price;
            $lineTotal = round($unitPrice * $quantity, 2);
            $subtotal += $lineTotal;

            $itemsQuote[] = [
                'product_id' => $product->id,
                'title' => $product->title,
                'sku' => $variant?->sku ?? $product->sku,
                'variant_id' => $variant?->id,
                'variant_name' => $variant?->name,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
                'stock_available' => $availableStock,
            ];
        }

        $subtotal = round($subtotal, 2);
        $discountAmount = round((float) ($validated['discount_amount'] ?? 0.00), 2);

        if ($discountAmount > $subtotal) {
            throw ValidationException::withMessages([
                'discount_amount' => ['Discount cannot exceed subtotal amount.'],
            ]);
        }

        if ($discountAmount > 0 && empty($validated['discount_reason'])) {
            throw ValidationException::withMessages([
                'discount_reason' => ['An explanation is required when applying a manual discount.'],
            ]);
        }

        $shippingFee = round((float) ($validated['shipping_fee'] ?? 0.00), 2);
        $taxAmount = 0.00;
        $totalAmount = max(0.00, round($subtotal - $discountAmount + $shippingFee + $taxAmount, 2));

        return response()->json([
            'success' => true,
            'data' => [
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'discount_reason' => $validated['discount_reason'] ?? null,
                'shipping_fee' => $shippingFee,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'currency' => 'BDT',
                'items' => $itemsQuote,
            ],
        ]);
    }

    /**
     * Atomically complete a Point of Sale order with inventory reservation.
     */
    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();

        $validated = $request->validate([
            'idempotency_key' => 'nullable|string|max:100',
            'customer_id' => 'required|exists:users,id',
            'address_id' => 'nullable|integer',
            'shipping_name' => 'nullable|string|max:100',
            'shipping_phone' => 'nullable|string|max:30',
            'shipping_district' => 'nullable|string|max:50',
            'shipping_upazila' => 'nullable|string|max:50',
            'shipping_address' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'payment_method' => 'required|in:cash,cod,bkash,nagad',
            'amount_received' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_reason' => 'nullable|string|max:255',
            'shipping_fee' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $idempotencyKey = $validated['idempotency_key'] ?? $request->header('X-Idempotency-Key');

        // Prevent duplicate order creation on retried requests
        if ($idempotencyKey && Schema::hasColumn('orders', 'idempotency_key')) {
            $existing = Order::where('idempotency_key', $idempotencyKey)
                ->with(['user', 'items', 'latestStatusHistory'])
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'Order already created (idempotency key recognized).',
                    'data' => $existing,
                ]);
            }
        }

        $customer = User::findOrFail($validated['customer_id']);

        // Resolve immutable shipping snapshot and verify address ownership
        if (! empty($validated['address_id'])) {
            $savedAddress = UserAddress::where('user_id', $customer->id)->findOrFail($validated['address_id']);
            $shippingName = $savedAddress->recipient_name;
            $shippingPhone = $savedAddress->recipient_phone;
            $shippingDistrict = $savedAddress->locality_district;
            $shippingUpazila = $savedAddress->sub_district_thana ?? 'Jashore Sadar';
            $shippingAddress = $savedAddress->street_address;
        } else {
            $shippingName = $validated['shipping_name'] ?? $customer->name;
            $shippingPhone = $validated['shipping_phone'] ?? $customer->phone ?? '01700000000';
            $shippingDistrict = $validated['shipping_district'] ?? 'Jashore';
            $shippingUpazila = $validated['shipping_upazila'] ?? 'Jashore Sadar';
            $shippingAddress = $validated['shipping_address'] ?? 'Counter Pickup (JashoreBro Store)';
        }

        $order = DB::transaction(function () use ($validated, $customer, $admin, $idempotencyKey, $shippingName, $shippingPhone, $shippingDistrict, $shippingUpazila, $shippingAddress) {
            $subtotal = 0.00;
            $sellerId = null;
            $orderItemsData = [];

            // Group multiple selections of the same product/variant
            $consolidatedItems = [];
            foreach ($validated['items'] as $line) {
                $key = $line['product_id'] . '_' . ($line['variant_id'] ?? 'none');
                if (! isset($consolidatedItems[$key])) {
                    $consolidatedItems[$key] = $line;
                } else {
                    $consolidatedItems[$key]['quantity'] += $line['quantity'];
                }
            }

            foreach ($consolidatedItems as $line) {
                /** @var Product $product */
                $product = Product::where('id', $line['product_id'])->lockForUpdate()->firstOrFail();

                if ($product->status !== 'published' || ! $product->is_normal_purchase_enabled) {
                    throw ValidationException::withMessages([
                        'items' => ["The item '{$product->title}' is not available for sale."],
                    ]);
                }

                $hasActiveVariants = $product->activeVariants()->where('is_active', true)->exists();
                if ($hasActiveVariants && empty($line['variant_id'])) {
                    throw ValidationException::withMessages([
                        'items' => ["Please select a variant for '{$product->title}'."],
                    ]);
                }

                $variant = ! empty($line['variant_id'])
                    ? ProductVariant::where('id', $line['variant_id'])->where('product_id', $product->id)->lockForUpdate()->firstOrFail()
                    : null;

                $quantity = (int) $line['quantity'];
                $sellerId = $product->seller_id;

                // Inventory verification & deduction
                if ($product->track_inventory) {
                    $available = $variant ? $variant->stock_quantity : $product->stock_quantity;
                    if ($available < $quantity) {
                        throw ValidationException::withMessages([
                            'items' => ["The item '{$product->title}'" . ($variant ? " ({$variant->name})" : "") . " only has {$available} left in stock."],
                        ]);
                    }

                    if ($variant) {
                        $variant->decrement('stock_quantity', $quantity);
                        $product->decrement('stock_quantity', $quantity);
                    } else {
                        $product->decrement('stock_quantity', $quantity);
                    }
                }

                $unitPrice = $variant ? (float) $variant->effective_price : (float) $product->base_price;
                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineTotal;

                $orderItemsData[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'purchase_mode' => 'normal',
                    'product_title' => $product->title,
                    'product_sku' => $variant?->sku ?? $product->sku,
                    'variant_name' => $variant?->name,
                    'variant_attributes' => $variant?->attributes,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                ];
            }

            $subtotal = round($subtotal, 2);
            $discountAmount = round((float) ($validated['discount_amount'] ?? 0.00), 2);

            if ($discountAmount > $subtotal) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount cannot exceed subtotal amount.'],
                ]);
            }

            if ($discountAmount > 0 && empty($validated['discount_reason'])) {
                throw ValidationException::withMessages([
                    'discount_reason' => ['Reason is required when applying a manual discount.'],
                ]);
            }

            $shippingFee = round((float) ($validated['shipping_fee'] ?? 0.00), 2);
            $taxAmount = 0.00;
            $totalAmount = max(0.00, round($subtotal - $discountAmount + $shippingFee + $taxAmount, 2));

            // Payment verification & change calculation
            $paymentMethod = $validated['payment_method'];
            $amountReceived = null;
            $changeAmount = null;
            $paymentStatus = 'unpaid';

            if ($paymentMethod === 'cash') {
                $amountReceived = round((float) ($validated['amount_received'] ?? 0.00), 2);
                if ($amountReceived < $totalAmount) {
                    throw ValidationException::withMessages([
                        'amount_received' => ["Cash received (BDT {$amountReceived}) is less than total payable (BDT {$totalAmount})."],
                    ]);
                }
                $changeAmount = round($amountReceived - $totalAmount, 2);
                $paymentStatus = 'paid';
            } elseif (in_array($paymentMethod, ['bkash', 'nagad'], true)) {
                $amountReceived = $totalAmount;
                $changeAmount = 0.00;
                $paymentStatus = 'paid';
            } else {
                // COD is unpaid until dispatch delivery
                $paymentStatus = 'unpaid';
            }

            $orderNumber = 'JB-POS-' . date('Ymd') . '-' . strtoupper(Str::random(5));

            $orderPayload = [
                'order_number' => $orderNumber,
                'user_id' => $customer->id,
                'seller_id' => $sellerId ?? 1,
                'status' => 'pending',
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentMethod,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'shipping_name' => $shippingName,
                'shipping_phone' => $shippingPhone,
                'shipping_district' => $shippingDistrict,
                'shipping_upazila' => $shippingUpazila,
                'shipping_address' => $shippingAddress,
                'notes' => $validated['notes'] ?? null,
            ];

            // Conditionally add enhanced POS columns if present in schema
            if (Schema::hasColumn('orders', 'channel')) {
                $orderPayload['channel'] = 'pos';
            }
            if (Schema::hasColumn('orders', 'created_by_admin_id')) {
                $orderPayload['created_by_admin_id'] = $admin->id;
            }
            if (Schema::hasColumn('orders', 'tax_amount')) {
                $orderPayload['tax_amount'] = $taxAmount;
            }
            if (Schema::hasColumn('orders', 'amount_received') && $amountReceived !== null) {
                $orderPayload['amount_received'] = $amountReceived;
            }
            if (Schema::hasColumn('orders', 'change_amount') && $changeAmount !== null) {
                $orderPayload['change_amount'] = $changeAmount;
            }
            if (Schema::hasColumn('orders', 'discount_reason') && ! empty($validated['discount_reason'])) {
                $orderPayload['discount_reason'] = $validated['discount_reason'];
            }
            if (Schema::hasColumn('orders', 'idempotency_key') && ! empty($idempotencyKey)) {
                $orderPayload['idempotency_key'] = $idempotencyKey;
            }

            $newOrder = Order::create($orderPayload);

            foreach ($orderItemsData as $itemData) {
                $itemData['order_id'] = $newOrder->id;
                OrderItem::create($itemData);
            }

            // Record initial order status history
            $this->orderStatusService->recordInitialStatus(
                order: $newOrder,
                initialStatus: 'pending',
                userId: $admin->id,
                actorType: 'admin',
                reason: 'POS Counter Sale created by Admin ' . $admin->name
            );

            // If immediate paid sale, transition status to processing
            if ($paymentStatus === 'paid') {
                $this->orderStatusService->transition(
                    order: $newOrder,
                    toStatus: 'processing',
                    actor: $admin,
                    reason: "In-store payment completed via {$paymentMethod}."
                );
            }

            // Record audit logs
            $this->logService->record(
                event: 'pos.order_created',
                outcome: 'success',
                actor: $admin,
                subject: $newOrder,
                metadata: [
                    'order_id' => $newOrder->id,
                    'order_number' => $newOrder->order_number,
                    'customer_id' => $customer->id,
                    'channel' => 'pos',
                    'total_amount' => $newOrder->total_amount,
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                ]
            );

            if ($paymentStatus === 'paid') {
                $this->logService->record(
                    event: 'pos.payment_confirmed',
                    outcome: 'success',
                    actor: $admin,
                    subject: $newOrder,
                    metadata: [
                        'order_id' => $newOrder->id,
                        'order_number' => $newOrder->order_number,
                        'amount' => $totalAmount,
                        'payment_method' => $paymentMethod,
                        'amount_received' => $amountReceived,
                        'change_amount' => $changeAmount,
                    ]
                );
            }

            if ($discountAmount > 0) {
                $this->logService->record(
                    event: 'pos.discount_applied',
                    outcome: 'success',
                    actor: $admin,
                    subject: $newOrder,
                    metadata: [
                        'order_id' => $newOrder->id,
                        'order_number' => $newOrder->order_number,
                        'discount_amount' => $discountAmount,
                        'discount_reason' => $validated['discount_reason'] ?? '',
                    ]
                );
            }

            // Notify customer about the completed POS order
            DB::afterCommit(function () use ($customer, $newOrder) {
                $this->notificationService->sendToUser(
                    user: $customer,
                    event: 'order.created',
                    title: "Order Placed: #{$newOrder->order_number}",
                    message: "Your in-store purchase #{$newOrder->order_number} for BDT {$newOrder->total_amount} has been recorded.",
                    subject: $newOrder,
                    actionUrl: "/orders/{$newOrder->id}",
                    metadata: [
                        'order_id' => $newOrder->id,
                        'order_number' => $newOrder->order_number,
                        'channel' => 'pos',
                        'total_amount' => (float) $newOrder->total_amount,
                    ],
                    audience: 'customer',
                    dedupKey: "pos_order_created_notif_{$newOrder->id}"
                );
            });

            return $newOrder;
        });

        return response()->json([
            'success' => true,
            'message' => 'POS sale completed successfully.',
            'data' => $order->fresh(['user', 'items', 'statusHistories', 'createdByAdmin']),
        ], 201);
    }

    /**
     * Retrieve order details and receipt data by ID.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $order = Order::with(['user', 'items.product.primaryImage', 'statusHistories', 'createdByAdmin'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }
}
