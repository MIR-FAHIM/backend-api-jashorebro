<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunityShop;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShopListing;
use App\Models\User;
use App\Services\CommunityCommercialService;
use App\Services\LogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommunityShopController extends Controller
{
    public function __construct(
        protected CommunityCommercialService $commercialService,
        protected LogService $logService,
        protected NotificationService $notificationService
    ) {}

    /**
     * Get the authenticated user's community shop.
     */
    public function myShop(Request $request): JsonResponse
    {
        $user = $request->user();
        $shop = CommunityShop::withCount(['listings', 'activeListings'])
            ->where('user_id', $user->id)
            ->first();

        if (! $shop) {
            return response()->json([
                'success' => true,
                'data' => null,
                'has_shop' => false,
            ]);
        }

        $followerCount = $user->followers()->count();
        $totalSalesCount = OrderItem::where('community_shop_id', $shop->id)->count();

        return response()->json([
            'success' => true,
            'has_shop' => true,
            'data' => array_merge($shop->toArray(), [
                'follower_count' => $followerCount,
                'total_sales_count' => $totalSalesCount,
            ]),
        ]);
    }

    /**
     * Onboard/create a personal community shop.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $existing = CommunityShop::where('user_id', $user->id)->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'name' => ['You already operate a community shop.'],
            ]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:100|alpha_dash|unique:community_shops,slug',
            'description' => 'nullable|string|max:1000',
            'logo_url' => 'nullable|string',
            'banner_url' => 'nullable|string',
        ]);

        $shop = CommunityShop::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'slug' => Str::slug($validated['slug']),
            'description' => $validated['description'] ?? null,
            'logo_url' => $validated['logo_url'] ?? null,
            'banner_url' => $validated['banner_url'] ?? null,
            'status' => 'active',
            'is_verified' => false,
        ]);

        $this->logService->record(
            event: 'shop.created',
            outcome: 'success',
            actor: $user,
            subject: $shop,
            metadata: [
                'shop_id' => $shop->id,
                'slug' => $shop->slug,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Community shop created successfully!',
            'data' => $shop,
        ], 201);
    }

    /**
     * Update shop settings.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $shop = CommunityShop::where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string|max:1000',
            'logo_url' => 'nullable|string',
            'banner_url' => 'nullable|string',
            'notice' => 'nullable|string|max:500',
        ]);

        $shop->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Shop details updated.',
            'data' => $shop,
        ]);
    }

    /**
     * Get listings in user's shop with real commercial breakdown.
     */
    public function listings(Request $request): JsonResponse
    {
        $user = $request->user();
        $shop = CommunityShop::where('user_id', $user->id)->firstOrFail();

        $listings = ShopListing::with(['product.primaryImage', 'product.activeVariants'])
            ->where('community_shop_id', $shop->id)
            ->orderBy('display_order', 'asc')
            ->orderBy('id', 'desc')
            ->get();

        // Attach commercial allocation breakdown for each listing
        $data = $listings->map(function ($listing) use ($shop, $user) {
            $product = $listing->product;
            $allocation = $this->commercialService->calculateShopItemAllocation(
                product: $product,
                variant: null,
                requestedPrice: (float) $listing->selling_price,
                shop: $shop,
                buyer: $user
            );

            return array_merge($listing->toArray(), [
                'commercial_breakdown' => $allocation,
            ]);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Add a product to the user's personal shop ("Add to My Shop").
     */
    public function storeListing(Request $request): JsonResponse
    {
        $user = $request->user();
        $shop = CommunityShop::where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'selling_price' => 'required|numeric|min:1',
            'caption' => 'nullable|string|max:1000',
        ]);

        $product = Product::with('activeVariants')->findOrFail($validated['product_id']);

        if (! $product->is_community_shop_enabled) {
            throw ValidationException::withMessages([
                'product_id' => ["The product '{$product->title}' is not eligible for community shop listings."],
            ]);
        }

        $existing = ShopListing::where('community_shop_id', $shop->id)
            ->where('product_id', $product->id)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'product_id' => ['This product is already listed in your shop.'],
            ]);
        }

        // Validate price bounds and calculate allocation
        $allocation = $this->commercialService->calculateShopItemAllocation(
            product: $product,
            variant: null,
            requestedPrice: (float) $validated['selling_price'],
            shop: $shop,
            buyer: $user
        );

        $listing = ShopListing::create([
            'community_shop_id' => $shop->id,
            'product_id' => $product->id,
            'caption' => $validated['caption'] ?? null,
            'selling_price' => (float) $validated['selling_price'],
            'is_active' => true,
            'display_order' => (int) ShopListing::where('community_shop_id', $shop->id)->count(),
        ]);

        $this->logService->record(
            event: 'shop.listing_added',
            outcome: 'success',
            actor: $user,
            subject: $listing,
            metadata: [
                'shop_id' => $shop->id,
                'product_id' => $product->id,
                'selling_price' => $listing->selling_price,
            ]
        );

        // Notify followers of new shop listing
        $followers = $user->followers()->pluck('follower_id');
        foreach ($followers as $followerId) {
            $follower = User::find($followerId);
            if ($follower) {
                $this->notificationService->sendToUser(
                    user: $follower,
                    event: 'community.new_shop_listing',
                    title: "{$shop->name} added a new item!",
                    message: "{$product->title} is now available in {$shop->name} for ৳{$listing->selling_price}.",
                    subject: $listing,
                    actionUrl: "/shop/{$shop->slug}",
                    audience: 'customer',
                    dedupKey: "notif_listing_{$listing->id}_to_{$followerId}"
                );
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Product listed in your shop successfully.',
            'data' => array_merge($listing->load(['product.primaryImage'])->toArray(), [
                'commercial_breakdown' => $allocation,
            ]),
        ], 201);
    }

    /**
     * Update listing pricing or caption.
     */
    public function updateListing(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $shop = CommunityShop::where('user_id', $user->id)->firstOrFail();
        $listing = ShopListing::where('community_shop_id', $shop->id)->findOrFail($id);

        $validated = $request->validate([
            'selling_price' => 'sometimes|required|numeric|min:1',
            'caption' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'display_order' => 'nullable|integer|min:0',
        ]);

        if (isset($validated['selling_price'])) {
            $product = $listing->product;
            $this->commercialService->calculateShopItemAllocation(
                product: $product,
                variant: null,
                requestedPrice: (float) $validated['selling_price'],
                shop: $shop,
                buyer: $user
            );
        }

        $listing->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Listing updated successfully.',
            'data' => $listing->fresh(['product.primaryImage']),
        ]);
    }

    /**
     * Remove a listing from the shop.
     */
    public function destroyListing(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $shop = CommunityShop::where('user_id', $user->id)->firstOrFail();
        $listing = ShopListing::where('community_shop_id', $shop->id)->findOrFail($id);

        $listing->delete();

        return response()->json([
            'success' => true,
            'message' => 'Listing removed from shop.',
        ]);
    }

    /**
     * Public storefront page by slug.
     */
    public function showPublic(string $slug): JsonResponse
    {
        $shop = CommunityShop::with(['user:id,name,username'])
            ->where('slug', $slug)
            ->whereIn('status', ['active', 'verified'])
            ->firstOrFail();

        $followerCount = $shop->user->followers()->count();

        $listings = ShopListing::with(['product.primaryImage', 'product.activeVariants'])
            ->where('community_shop_id', $shop->id)
            ->where('is_active', true)
            ->orderBy('display_order', 'asc')
            ->get();

        // Filter and enrich with live catalog status
        $items = $listings->map(function ($l) {
            $product = $l->product;
            $isAvailable = $product && $product->status === 'published' && $product->is_active;
            if ($product && $product->track_inventory && $product->stock_quantity <= 0) {
                $isAvailable = false;
            }

            return [
                'id' => $l->id,
                'product_id' => $product->id,
                'title' => $product->title,
                'slug' => $product->slug,
                'caption' => $l->caption,
                'selling_price' => (float) $l->selling_price,
                'catalog_base_price' => (float) $product->base_price,
                'is_available' => $isAvailable,
                'image' => $product->primaryImage?->thumbnail_url ?? null,
                'variants' => $product->activeVariants,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'shop' => [
                    'id' => $shop->id,
                    'name' => $shop->name,
                    'slug' => $shop->slug,
                    'description' => $shop->description,
                    'logo_url' => $shop->logo_url,
                    'banner_url' => $shop->banner_url,
                    'notice' => $shop->notice,
                    'is_verified' => $shop->is_verified,
                    'owner' => [
                        'id' => $shop->user->id,
                        'name' => $shop->user->name,
                        'username' => $shop->user->username,
                    ],
                    'follower_count' => $followerCount,
                    'fulfillment_guarantee' => 'Fulfilled, packed, and delivered directly across Jashore by JashoreBro Platform.',
                ],
                'listings' => $items,
            ],
        ]);
    }

    /**
     * Privacy-safe seller sales dashboard.
     * Excludes buyer names, phone numbers, delivery addresses, and internal notes.
     */
    public function sales(Request $request): JsonResponse
    {
        $user = $request->user();
        $shop = CommunityShop::where('user_id', $user->id)->firstOrFail();

        $items = OrderItem::with(['order:id,order_number,status,created_at'])
            ->where('community_shop_id', $shop->id)
            ->orderBy('id', 'desc')
            ->paginate(20);

        // Sanitize response to guarantee buyer privacy
        $sanitized = $items->getCollection()->map(function ($item) {
            return [
                'id' => $item->id,
                'order_number' => $item->order?->order_number,
                'order_status' => $item->order?->status,
                'product_title' => $item->product_title,
                'variant_name' => $item->variant_name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'seller_earning' => (float) $item->seller_earning,
                'total_earning' => round((float) $item->seller_earning * (int) $item->quantity, 2),
                'ordered_at' => $item->order?->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $sanitized,
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }
}
