<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunityShop;
use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\LogService;
use App\Services\NotificationService;
use App\Services\GroupBuyLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
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
            ->where('visibility', 'public')
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
     * Public drop campaign discovery feed.
     */
    public function publicIndex(Request $request): JsonResponse
    {
        $authUserId = $request->user('sanctum')?->id;
        $categorySlug = $request->query('category');
        $queryStr = $request->query('q');

        $query = GroupBuyCampaign::with([
            'product.primaryImage',
            'product.category',
            'product.seller',
            'product.specifications.attribute',
            'product.specifications.attributeItem',
            'variant',
            'organizer.profile',
        ])
            ->where('status', 'active')
            ->whereHas('product', fn ($q) => $q->where('status', 'published')->where('visibility', 'public')->where('is_active', true))
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', now()))
            ->where(function ($q) {
                $q->whereNull('end_at')->orWhere('end_at', '>', now());
            });

        if (! empty($categorySlug) && $categorySlug !== 'all') {
            $query->whereHas('product.category', function ($q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            });
        }

        if (! empty($queryStr)) {
            $query->where(function ($q) use ($queryStr) {
                $q->where('title', 'like', "%{$queryStr}%")
                  ->orWhereHas('product', function ($pq) use ($queryStr) {
                      $pq->where('title', 'like', "%{$queryStr}%");
                  });
            });
        }

        $campaigns = $query->orderBy('id', 'desc')->paginate(20);

        $items = $campaigns->getCollection()->map(function ($c) use ($authUserId) {
            return $this->formatCampaign($c, $authUserId);
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'drops' => $items,
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    /**
     * Single drop campaign details by ID or code.
     */
    public function show(Request $request, string $idOrCode): JsonResponse
    {
        $authUserId = $request->user('sanctum')?->id;

        $campaign = GroupBuyCampaign::with([
            'product.primaryImage',
            'product.category',
            'product.seller',
            'product.specifications.attribute',
            'product.specifications.attributeItem',
            'variant',
            'organizer.profile',
        ])
            ->whereNotIn('status', ['draft'])
            ->whereHas('product', fn ($q) => $q->where('status', 'published')->where('visibility', 'public')->where('is_active', true))
            ->where(function ($q) use ($idOrCode) {
                if (is_numeric($idOrCode)) {
                    $q->where('id', (int) $idOrCode);
                } else {
                    $q->where('campaign_code', $idOrCode);
                }
            })
            ->first();

        if (! $campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Drop campaign not found.',
            ], 404);
        }

        $data = $this->formatCampaign($campaign, $authUserId);

        return response()->json([
            'success' => true,
            'data' => $data,
            'drop' => $data,
        ]);
    }

    /**
     * Drops joined by the authenticated user.
     */
    public function joinedDrops(Request $request): JsonResponse
    {
        $user = $request->user();

        $campaignIds = GroupBuyParticipant::where('user_id', $user->id)
            ->currentParticipation()
            ->pluck('campaign_id')
            ->unique()
            ->toArray();

        if (empty($campaignIds)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'drops' => [],
            ]);
        }

        $campaigns = GroupBuyCampaign::with([
            'product.primaryImage',
            'product.category',
            'product.seller',
            'product.specifications.attribute',
            'product.specifications.attributeItem',
            'variant',
            'organizer.profile',
        ])
            ->whereIn('id', $campaignIds)
            ->orderBy('id', 'desc')
            ->get();

        $items = $campaigns->map(function ($c) use ($user) {
            return $this->formatCampaign($c, $user->id);
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'drops' => $items,
        ]);
    }

    /**
     * Toggle reservation interest in a drop for authenticated user.
     */
    public function toggleInterest(Request $request, string $id): JsonResponse
    {
        $request->validate(['is_interested' => 'sometimes|required|boolean']);
        $user = $request->user();

        return DB::transaction(function () use ($request, $id, $user) {

        $campaign = GroupBuyCampaign::with(['product.primaryImage', 'product.category', 'variant', 'organizer.profile'])
            ->where(function ($q) use ($id) {
                if (is_numeric($id)) {
                    $q->where('id', (int) $id);
                } else {
                    $q->where('campaign_code', $id);
                }
            })
            ->lockForUpdate()->firstOrFail();

        $availability = app(GroupBuyLifecycleService::class)->availability($campaign);
        if (! $availability['can_express_interest'] && ! ($request->has('is_interested') && ! $request->boolean('is_interested'))) {
            throw ValidationException::withMessages([
                'drop' => [$availability['unavailability_reason']],
            ]);
        }

        $confirmed = $campaign->activeParticipants()
            ->where('user_id', $user->id)
            ->where('status', 'confirmed')
            ->first();

        if ($confirmed) {
            throw ValidationException::withMessages([
                'drop' => ['You have an active confirmed order for this drop. To cancel, please manage your order in Orders.'],
            ]);
        }

        $reservedOrder = $campaign->activeParticipants()
            ->where('user_id', $user->id)
            ->where('status', 'reserved')
            ->whereNotNull('order_id')
            ->first();

        if ($reservedOrder) {
            throw ValidationException::withMessages([
                'drop' => ['You have an active pending order reservation for this drop. To manage, view Orders or Cart.'],
            ]);
        }

        $existingInterest = GroupBuyParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('status', 'interested')
                  ->orWhere(function ($sub) {
                      $sub->where('status', 'reserved')->whereNull('order_id');
                  });
            })
            ->first();

        $desired = $request->has('is_interested') ? $request->boolean('is_interested') : ! $existingInterest;
        if (! $desired && $existingInterest) {
            GroupBuyParticipant::where('campaign_id', $campaign->id)->where('user_id', $user->id)
                ->whereNull('order_id')->whereIn('status', ['interested', 'reserved'])->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
            $isJoined = false;
            $message = "You have removed your interest from '{$campaign->title}'.";
        } elseif ($desired && ! $existingInterest) {
            GroupBuyParticipant::updateOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'order_id' => null,
                ],
                [
                    'quantity' => 1,
                    'unit_price' => $campaign->group_price,
                    'status' => 'interested',
                    'reserved_at' => now(),
                    'cancelled_at' => null,
                ]
            );
            $isJoined = true;
            $message = "You're in! You expressed interest in '{$campaign->title}'.";
        } else {
            $isJoined = $desired;
            $message = $desired ? 'Your interest is saved. No order has been placed.' : 'Your interest is removed.';
        }

        $this->logService->record(
            event: 'community.drop_interest_toggled',
            outcome: 'success',
            actor: $user,
            subject: $campaign,
            metadata: [
                'campaign_id' => $campaign->id,
                'is_joined' => $isJoined,
            ]
        );

        $campaign->refresh();
        $formatted = $this->formatCampaign($campaign, $user->id);

        return response()->json([
            'success' => true,
            'message' => $message,
            'is_joined' => $isJoined,
            'isJoined' => $isJoined,
            'data' => $formatted,
            'drop' => $formatted,
        ]);
        }, 3);
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
        if (! app(GroupBuyLifecycleService::class)->productIsPublic($product)) {
            throw ValidationException::withMessages(['product_id' => ['Choose an active public catalogue product.']]);
        }
        if (! $product->is_group_drop_enabled) {
            throw ValidationException::withMessages([
                'product_id' => ["The product '{$product->title}' does not permit community group drops."],
            ]);
        }

        $variant = ! empty($validated['product_variant_id'])
            ? ProductVariant::findOrFail($validated['product_variant_id'])
            : null;
        if ($variant && ((int) $variant->product_id !== (int) $product->id || ! $variant->is_active)) {
            throw ValidationException::withMessages(['product_variant_id' => ['Choose an active variant belonging to this product.']]);
        }

        $catalogPrice = $variant ? (float) $variant->effective_price : (float) $product->base_price;
        $groupPrice = (float) $validated['group_price'];

        if ($groupPrice >= $catalogPrice) {
            throw ValidationException::withMessages([
                'group_price' => ["Drop price (৳{$groupPrice}) must be lower than standard catalog price (৳{$catalogPrice})."],
            ]);
        }

        $supplierCost = $variant?->supplier_allocation_price ?? $product->supplier_allocation_price ?? $product->cost_price;
        if ($supplierCost === null) {
            throw ValidationException::withMessages([
                'group_price' => ["Supplier wholesale allocation is not configured for '{$product->title}'. Cannot start community drop."],
            ]);
        }
        $supplierCost = (float) $supplierCost;

        if ($groupPrice < $supplierCost) {
            throw ValidationException::withMessages([
                'group_price' => ["Drop price cannot be lower than supplier base allocation (৳{$supplierCost})."],
            ]);
        }

        // Organizer earns a portion of the spread (50% of the margin between drop price and supplier cost)
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
            $follower = User::find($followerId);
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

        $formatted = $this->formatCampaign($campaign, $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Community group drop started successfully!',
            'data' => $formatted,
            'campaign' => $formatted,
        ], 201);
    }

    /**
     * Standardized campaign transformer for consumer screens.
     */
    protected function formatCampaign(GroupBuyCampaign $c, ?int $authUserId = null): array
    {
        $product = $c->product;

        $interestCount = $c->participants()
            ->where('status', 'interested')
            ->distinct('user_id')
            ->count('user_id');

        $qualifyingParticipants = app(GroupBuyLifecycleService::class)->qualifyingCount($c);

        $target = (int) $c->target_participants;
        $progressPercent = $target > 0 ? min(100, (int) round(($qualifyingParticipants / $target) * 100)) : 0;
        $remainingNeeded = max(0, $target - $qualifyingParticipants);

        $retailPrice = (float) ($c->variant?->effective_price ?? $product?->base_price ?? $c->group_price);
        $groupPrice = (float) $c->group_price;

        $hasExpressedInterest = false;
        $hasOrderReservation = false;
        $hasConfirmedParticipation = false;

        if ($authUserId) {
            $userParticipants = $c->participants()
                ->where('user_id', $authUserId)
                ->currentParticipation()
                ->get();

            $hasExpressedInterest = $userParticipants->contains(fn ($p) => $p->status === 'interested');
            $hasOrderReservation = $userParticipants->contains(fn ($p) => $p->status === 'reserved' && $p->order_id !== null);
            $hasConfirmedParticipation = $userParticipants->contains(fn ($p) => $p->status === 'confirmed');
        }

        $isJoined = $hasExpressedInterest || $hasOrderReservation || $hasConfirmedParticipation;

        $endsIn = null;
        if ($c->end_at) {
            $diff = now()->diff($c->end_at);
            if ($c->end_at->isPast()) {
                $endsIn = 'Ended';
            } elseif ($diff->days > 0) {
                $endsIn = "{$diff->days}d {$diff->h}h";
            } elseif ($diff->h > 0) {
                $endsIn = "{$diff->h}h {$diff->i}m";
            } else {
                $endsIn = "{$diff->i}m";
            }
        }

        $tiers = [
            ['requiredParticipants' => 1, 'price' => $retailPrice],
            ['requiredParticipants' => $target, 'price' => $groupPrice],
        ];

        $curatorName = $c->organizer?->name ?? 'Community Organizer';
        $curatorHandle = $c->organizer?->username ?? 'community';

        $specs = $product?->specifications?->map(fn ($spec) => [
            'id' => $spec->id,
            'attribute_name' => $spec->attribute?->name,
            'attribute_slug' => $spec->attribute?->slug,
            'value' => $spec->attributeItem?->label ?? $spec->custom_value,
            'color_code' => $spec->attributeItem?->color_code,
        ])->values()->all() ?? [];

        return [
            ...app(GroupBuyLifecycleService::class)->publicContext($c),
            'id' => $c->id,
            'campaign_code' => $c->campaign_code,
            'title' => $c->title,
            'description' => $product?->description ?? "Community volume drop for {$c->title}.",
            'specifications' => $specs,
            'category' => $product?->category?->name ?? 'Community Finds',
            'category_slug' => $product?->category?->slug,
            'image' => $product?->primaryImage?->thumbnail_url ?? $product?->primaryImage?->image_url ?? $product?->image,
            'retailPrice' => $retailPrice,
            'retail_price' => $retailPrice,
            'groupPrice' => $groupPrice,
            'group_price' => $groupPrice,
            'currentParticipants' => $qualifyingParticipants,
            'current_participants' => $qualifyingParticipants,
            'qualifying_participants_count' => $qualifyingParticipants,
            'interest_count' => $interestCount,
            'interested_count' => $interestCount,
            'target_participants' => $target,
            'progress_percent' => $progressPercent,
            'remaining_needed' => $remainingNeeded,
            'isJoined' => $isJoined,
            'is_joined' => $isJoined,
            'viewer_state' => [
                'has_expressed_interest' => $hasExpressedInterest,
                'has_order_reservation' => $hasOrderReservation,
                'has_confirmed_participation' => $hasConfirmedParticipation,
            ],
            'endsIn' => $endsIn,
            'ends_in' => $endsIn,
            'end_at' => $c->end_at?->toIso8601String(),
            'status' => $c->status,
            'curator' => [
                'id' => $c->organizer?->id ?? $c->created_by,
                'name' => $curatorName,
                'handle' => $curatorHandle,
                'username' => $curatorHandle,
                'avatar_url' => $c->organizer?->profile?->avatar_url,
            ],
            'recommendation' => "Community volume drop by {$curatorName}. Join to unlock ৳{$groupPrice}!",
            'tiers' => $tiers,
            'product' => $product ? [
                'id' => $product->id,
                'title' => $product->title,
                'slug' => $product->slug,
                'base_price' => (float) $product->base_price,
                'is_active' => (bool) $product->is_active,
                'status' => $product->status,
                'visibility' => $product->visibility,
                'track_inventory' => (bool) $product->track_inventory,
                'stock_quantity' => $product->stock_quantity,
                'min_order_quantity' => $product->min_order_quantity,
                'max_order_quantity' => $product->max_order_quantity,
                'is_cod_available' => (bool) $product->is_cod_available,
                'has_active_variants' => $product->activeVariants()->exists(),
                'description' => $product->description,
                'specifications' => $specs,
                'is_free_shipping' => (bool) $product->is_free_shipping,
                'shipping_charge' => $product->shipping_charge !== null ? (float) $product->shipping_charge : null,
                'is_returnable' => (bool) $product->is_returnable,
                'return_window_days' => $product->return_window_days ?? 7,
                'seller' => $product->seller ? [
                    'id' => $product->seller->id,
                    'store_name' => $product->seller->store_name,
                    'locality' => $product->seller->locality,
                ] : null,
            ] : null,
            'variant' => $c->variant ? [
                'id' => $c->variant->id,
                'name' => $c->variant->name,
                'title' => $c->variant->name,
                'stock_quantity' => $c->variant->stock_quantity,
                'is_active' => (bool) $c->variant->is_active,
                'sku' => $c->variant->sku,
                'effective_price' => (float) $c->variant->effective_price,
            ] : null,
        ];
    }
}
