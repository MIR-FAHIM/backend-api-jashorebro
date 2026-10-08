<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminGroupBuyController extends Controller
{
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
            'max_participants' => 'nullable|integer|min:2',
            'quantity_limit_per_customer' => 'nullable|integer|min:1',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
            'status' => 'required|in:draft,scheduled,active',
        ]);

        $product = Product::findOrFail($validated['product_id']);

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
        $campaign = GroupBuyCampaign::findOrFail($id);

        // Check if customers have committed
        $hasParticipants = $campaign->activeParticipants()->exists();

        if ($hasParticipants && ($request->has('group_price') || $request->has('target_participants'))) {
            throw ValidationException::withMessages([
                'group_price' => ['Campaign price and target participants cannot be altered after customers have joined.'],
            ]);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'group_price' => 'sometimes|required|numeric|min:1',
            'target_participants' => 'sometimes|required|integer|min:2',
            'max_participants' => 'nullable|integer|min:2',
            'quantity_limit_per_customer' => 'nullable|integer|min:1',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date',
            'status' => 'sometimes|required|in:draft,scheduled,active,succeeded,failed,cancelled',
        ]);

        $campaign->update($validated);

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
        $campaign = GroupBuyCampaign::findOrFail($id);

        $request->validate([
            'cancellation_reason' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($campaign, $request) {
            $campaign->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $request->input('cancellation_reason', 'Cancelled by administrator'),
            ]);

            // Release reservations
            GroupBuyParticipant::where('campaign_id', $campaign->id)
                ->where('status', 'reserved')
                ->update([
                    'status' => 'released',
                    'cancelled_at' => now(),
                ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Campaign cancelled and participant reservations released.',
        ]);
    }
}
