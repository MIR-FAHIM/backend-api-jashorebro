<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Models\Product;
use App\Services\OrderStatusService;
use App\Services\GroupBuyLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminGroupBuyController extends Controller
{
    public function __construct(
        protected OrderStatusService $orderStatusService
    ) {}

    /**
     * List all drop campaigns with progress metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $query = GroupBuyCampaign::with([
            'product:id,title,slug,base_price,seller_id',
            'product.seller:id,store_name',
            'product.primaryImage',
            'variant:id,name,sku,price_override',
        ]);

        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        if ($prodId = $request->input('product_id')) {
            $query->where('product_id', $prodId);
        }

        $campaigns = $query->latest('id')->paginate(20);

        // Append computed attributes to each campaign
        $campaigns->getCollection()->transform(function (GroupBuyCampaign $c) {
            $c->distinct_participants_count = $c->distinct_participants_count;
            $c->remaining_needed = $c->remaining_needed;
            $c->progress_percent = $c->progress_percent;
            return $c;
        });

        return response()->json([
            'success' => true,
            'data' => $campaigns->getCollection(),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    /**
     * Create a new group buy campaign.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'product_variant_id' => 'nullable|exists:product_variants,id',
            'title' => 'required|string|max:255',
            'group_price' => 'required|numeric|min:1',
            'target_participants' => 'required|integer|min:2',
            'max_participants' => 'nullable|integer|min:2|gte:target_participants',
            'quantity_limit_per_customer' => 'nullable|integer|min:1',
            'start_at' => 'nullable|date',
            'end_at' => 'required|date|after:now|after:start_at',
            'status' => 'required|in:draft,scheduled,active',
        ]);

        $product = Product::findOrFail($validated['product_id']);
        if (! app(GroupBuyLifecycleService::class)->productIsPublic($product)) {
            throw ValidationException::withMessages(['product_id' => ['Choose an active public catalogue product.']]);
        }
        if (! empty($validated['product_variant_id'])) {
            $variant = $product->activeVariants()->find($validated['product_variant_id']);
            if (! $variant) throw ValidationException::withMessages(['product_variant_id' => ['Choose an active variant belonging to this product.']]);
        }
        $validated['start_at'] = $validated['start_at'] ?? now();
        $catalogPrice = isset($variant) ? $variant->effective_price : $product->base_price;
        if ((float) $validated['group_price'] >= (float) $catalogPrice) {
            throw ValidationException::withMessages(['group_price' => ['The Price Drop price must be lower than the ordinary catalogue price.']]);
        }

        if (! $product->is_group_buy_enabled) {
            // Enable group buy flag on product if creating campaign
            $product->update(['is_group_buy_enabled' => true]);
        }

        $code = 'GRP-' . strtoupper(Str::random(6));
        while (GroupBuyCampaign::where('campaign_code', $code)->exists()) {
            $code = 'GRP-' . strtoupper(Str::random(6));
        }

        $validated['campaign_code'] = $code;
        $validated['created_by'] = $request->user()->id;

        $campaign = GroupBuyCampaign::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Group-buy campaign created successfully.',
            'data' => $campaign->load(['product.primaryImage', 'variant']),
        ], 201);
    }

    /**
     * Inspect campaign details and participants.
     */
    public function show(int $id): JsonResponse
    {
        $campaign = GroupBuyCampaign::with([
            'product.seller',
            'product.primaryImage',
            'variant',
            'participants.user:id,name,phone,username',
            'participants.variant',
            'participants.order:id,order_number,status,payment_status',
        ])->findOrFail($id);

        $campaign->distinct_participants_count = $campaign->distinct_participants_count;
        $campaign->remaining_needed = $campaign->remaining_needed;
        $campaign->progress_percent = $campaign->progress_percent;

        return response()->json([
            'success' => true,
            'data' => $campaign,
        ]);
    }

    /**
     * Update campaign rules. Rules are frozen once customers have joined.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'group_price' => 'sometimes|required|numeric|min:1',
            'target_participants' => 'sometimes|required|integer|min:2',
            'max_participants' => 'nullable|integer|min:2',
            'quantity_limit_per_customer' => 'nullable|integer|min:1',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date',
            'status' => 'sometimes|required|in:draft,scheduled,active',
        ]);

        $campaign = DB::transaction(function () use ($validated, $id) {
            $campaign = GroupBuyCampaign::whereKey($id)->lockForUpdate()->firstOrFail();
            if (in_array($campaign->status, ['succeeded', 'failed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['campaign' => ['Concluded Price Drops cannot be reopened or repriced.']]);
            }
            $hasOrders = $campaign->participants()->whereNotNull('order_id')->exists();
            foreach (['group_price', 'target_participants', 'max_participants', 'quantity_limit_per_customer', 'start_at', 'end_at', 'status'] as $field) {
                if ($hasOrders && array_key_exists($field, $validated)) {
                    $value = $validated[$field];
                    $current = $campaign->getAttribute($field);
                    if ($current instanceof \DateTimeInterface) $current = $current->format('Y-m-d H:i:s');
                    if (in_array($field, ['start_at', 'end_at'], true) && $value !== null) $value = \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i:s');
                    if ((string) $value !== (string) $current) {
                        throw ValidationException::withMessages([$field => ['Price, target, customer limits, schedule and lifecycle are fixed after the first accepted order. Use the cancellation action before success when necessary.']]);
                    }
                }
            }
            $campaign->fill($validated);
            if (! $campaign->end_at || ($campaign->start_at && $campaign->end_at->lessThanOrEqualTo($campaign->start_at))) {
                throw ValidationException::withMessages(['end_at' => ['Provide a deadline after the start time.']]);
            }
            if ($campaign->max_participants !== null && $campaign->max_participants < $campaign->target_participants) {
                throw ValidationException::withMessages(['max_participants' => ['The customer limit cannot be below the target.']]);
            }
            $catalogPrice = $campaign->variant?->effective_price ?? $campaign->product?->base_price;
            if ((float) $campaign->group_price >= (float) $catalogPrice) {
                throw ValidationException::withMessages(['group_price' => ['The Price Drop price must be lower than the ordinary catalogue price.']]);
            }
            $campaign->save();
            return $campaign;
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Campaign updated successfully.',
            'data' => $campaign,
        ]);
    }

    /**
     * Cancel campaign and release participant reservations.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['cancellation_reason' => 'required|string|max:500']);
        app(GroupBuyLifecycleService::class)->cancelCampaign($id, trim($validated['cancellation_reason']));

        return response()->json([
            'success' => true,
            'message' => 'Campaign cancelled and participant reservations released.',
        ]);
    }
}
