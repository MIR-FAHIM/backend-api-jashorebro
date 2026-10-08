<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function __construct(
        protected OrderStatusService $orderStatusService
    ) {}

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
            'latestStatusHistory',
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

        if ($channel = $request->input('channel')) {
            if ($channel !== 'all' && \Illuminate\Support\Facades\Schema::hasColumn('orders', 'channel')) {
                $query->where('channel', $channel);
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
     * Show single order details with full administrative timeline.
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::with([
            'user:id,name,phone,username',
            'seller:id,store_name,contact_phone',
            'items.product.primaryImage',
            'items.variant',
            'groupParticipants.campaign',
            'statusHistories.changedByUser:id,name,username',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    /**
     * Paginated status history access for administrators with internal notes.
     */
    public function history(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        $histories = $order->statusHistories()
            ->with('changedByUser:id,name,username')
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
     * Update order status and/or payment status via OrderStatusService.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|required|in:awaiting_group,pending,processing,shipped,delivered,cancelled',
            'payment_status' => 'sometimes|required|in:unpaid,paid,refunded',
            'reason' => 'nullable|string|max:500',
            'internal_note' => 'nullable|string|max:500',
            'event_key' => 'nullable|string|max:100',
        ]);

        $order = Order::findOrFail($id);

        // 1. Process fulfillment status transition through OrderStatusService
        if (isset($validated['status'])) {
            $this->orderStatusService->transition(
                orderOrId: $order,
                toStatus: $validated['status'],
                actorType: 'admin',
                actor: $request->user(),
                reason: $validated['reason'] ?? null,
                internalNote: $validated['internal_note'] ?? null,
                eventKey: $validated['event_key'] ?? null
            );
        }

        // 2. Process separate payment status update
        if (isset($validated['payment_status'])) {
            $order->payment_status = $validated['payment_status'];
            $order->save();
        }

        return response()->json([
            'success' => true,
            'message' => "Order #{$order->order_number} updated successfully.",
            'data' => $order->fresh([
                'items',
                'seller:id,store_name',
                'statusHistories.changedByUser:id,name,username',
            ]),
        ]);
    }
}
