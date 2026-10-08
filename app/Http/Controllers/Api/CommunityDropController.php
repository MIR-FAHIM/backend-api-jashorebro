<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunityShop;
use App\Models\GroupBuyCampaign;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\LogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunityDropController extends Controller
{
    public function __construct(
        protected LogService $logService,
        protected NotificationService $notificationService
    ) {}

    /**
     * List products eligible for community-initiated drops.
     */
    public function eligibleProducts(Request $request): JsonResponse
    {
        $products = Product::with(['primaryImage', 'activeVariants'])
            ->where('status', 'published')
            ->where('is_group_drop_enabled', true)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Drops organized by the authenticated user.
     */
    public function myDrops(Request $request): JsonResponse
    {
        $user = $request->user();

        $campaigns = GroupBuyCampaign::with(['product.primaryImage', 'variant'])
            ->withCount('participants')
            ->where('organizer_user_id', $user->id)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $campaigns,
        ]);
    }

    /**
     * Start a new community drop campaign.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $shop = CommunityShop::where('user_id', $user->id)->first();

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'product_variant_id' => 'nullable|exists:product_variants,id',
            'title' => 'required|string|max:150',
            'group_price' => 'required|numeric|min:1',
            'target_participants' => 'required|integer|min:2|max:100',
            'max_participants' => 'nullable|integer|gte:target_participants',
            'quantity_limit_per_customer' => 'nullable|integer|min:1|max:10',
            'duration_hours' => 'required|integer|min:1|max:168', // up to 7 days
        ]);

        $product = Product::findOrFail($validated['product_id']);
        if (! $product->is_group_drop_enabled) {
            throw ValidationException::withMessages([
                'product_id' => ["The product '{$product->title}' does not permit community group drops."],
            ]);
        }

        $variant = ! empty($validated['product_variant_id'])
            ? ProductVariant::findOrFail($validated['product_variant_id'])
            : null;

        $catalogPrice = $variant ? (float) $variant->effective_price : (float) $product->base_price;
        $groupPrice = (float) $validated['group_price'];

        if ($groupPrice >= $catalogPrice) {
            throw ValidationException::withMessages([
                'group_price' => ["Drop price (৳{$groupPrice}) must be lower than standard catalog price (৳{$catalogPrice})."],
            ]);
        }

        $supplierCost = (float) ($product->supplier_allocation_price ?? $product->cost_price ?? round($catalogPrice * 0.75, 2));
        if ($groupPrice < $supplierCost) {
            throw ValidationException::withMessages([
                'group_price' => ["Drop price cannot be lower than supplier base allocation (৳{$supplierCost})."],
            ]);
        }

        // Organizer earns a portion of the spread (e.g. 50% of the margin between drop price and supplier cost)
        $margin = max(0.00, $groupPrice - $supplierCost);
        $organizerCommission = round($margin * 0.5, 2);

        $startAt = now();
        $endAt = now()->addHours((int) $validated['duration_hours']);
        $campaignCode = 'DRP-C' . strtoupper(Str::random(5));

        $campaign = GroupBuyCampaign::create([
            'campaign_code' => $campaignCode,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'title' => $validated['title'],
            'status' => 'active',
            'group_price' => $groupPrice,
            'target_participants' => (int) $validated['target_participants'],
            'max_participants' => (int) ($validated['max_participants'] ?? $validated['target_participants'] * 2),
            'quantity_limit_per_customer' => (int) ($validated['quantity_limit_per_customer'] ?? 2),
            'start_at' => $startAt,
            'end_at' => $endAt,
            'created_by' => $user->id,
            'organizer_user_id' => $user->id,
            'originating_shop_id' => $shop?->id,
            'organizer_commission_per_unit' => $organizerCommission,
            'supplier_allocation_price' => $supplierCost,
        ]);

        $this->logService->record(
            event: 'community.drop_created',
            outcome: 'success',
            actor: $user,
            subject: $campaign,
            metadata: [
                'campaign_id' => $campaign->id,
                'code' => $campaign->campaign_code,
                'group_price' => $campaign->group_price,
            ]
        );

        // Notify organizer's followers
        $followers = $user->followers()->pluck('follower_id');
        foreach ($followers as $followerId) {
            $follower = \App\Models\User::find($followerId);
            if ($follower) {
                $this->notificationService->sendToUser(
                    user: $follower,
                    event: 'community.new_drop',
                    title: "New Drop by {$user->name}!",
                    message: "Join {$user->name}'s community drop for '{$product->title}' at ৳{$campaign->group_price}!",
                    subject: $campaign,
                    actionUrl: "/drops/{$campaign->id}",
                    audience: 'customer',
                    dedupKey: "notif_drop_{$campaign->id}_to_{$followerId}"
                );
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Community group drop started successfully!',
            'data' => $campaign->load(['product.primaryImage', 'variant']),
        ], 201);
    }
}
