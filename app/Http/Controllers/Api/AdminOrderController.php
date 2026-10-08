<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupBuyParticipant;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOrderController extends Controller
{
    /**
     * List all customer orders with filtering and pagination for admin dispatch.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::query()->with([
            'user:id,name,phone,username',
            'seller:id,store_name',
            'items.product.primaryImage',
            'items.variant',
        ]);

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('shipping_name', 'like', "%{$search}%")
                    ->orWhere('shipping_phone', 'like', "%{$search}%")
                    ->orWhere('shipping_address', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        if ($paymentStatus = $request->input('payment_status')) {
            if ($paymentStatus !== 'all') {
                $query->where('payment_status', $paymentStatus);
            }
        }

        $orders = $query->latest('id')->paginate(20);

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
     * Show single order details for administration.
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::with([
            'user:id,name,phone,username',
            'seller:id,store_name,contact_phone',
            'items.product.primaryImage',
            'items.variant',
            'groupParticipants.campaign',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    /**
     * Update order dispatch or payment status.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|required|in:pending,processing,shipped,delivered,cancelled',
            'payment_status' => 'sometimes|required|in:unpaid,paid,refunded',
            'notes' => 'nullable|string|max:500',
        ]);

        $order = Order::with('items')->findOrFail($id);

        DB::transaction(function () use ($validated, $order, $request) {
            $prevStatus = $order->status;
            $newStatus = $validated['status'] ?? $prevStatus;

            // Handle cancellation transition
            if ($newStatus === 'cancelled' && $prevStatus !== 'cancelled') {
                $order->cancelled_at = now();
                $order->cancellation_reason = $validated['notes'] ?? 'Cancelled by administrator';

                // Release stock back
                foreach ($order->items as $item) {
                    if ($item->product_variant_id) {
                        ProductVariant::where('id', $item->product_variant_id)->increment('stock_quantity', $item->quantity);
                    }
                    Product::where('id', $item->product_id)->increment('stock_quantity', $item->quantity);
                }

                // Release group buy reservations
                GroupBuyParticipant::where('order_id', $order->id)->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);
            }

            if (isset($validated['status'])) {
                $order->status = $validated['status'];
            }
            if (isset($validated['payment_status'])) {
                $order->payment_status = $validated['payment_status'];
            }
            if (isset($validated['notes'])) {
                $order->notes = $validated['notes'];
            }

            $order->save();
        });

        return response()->json([
            'success' => true,
            'message' => "Order #{$order->order_number} status updated successfully.",
            'data' => $order->fresh(['items', 'seller:id,store_name']),
        ]);
    }
}
