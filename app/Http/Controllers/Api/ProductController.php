<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\GroupBuyCampaign;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Search and filter public product catalog.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->where('is_active', true)
            ->with([
                'category:id,name,slug,icon',
                'seller:id,store_name,slug,district,upazila,logo_url,rating_avg',
                'primaryImage',
                'images',
            ]);

        // Search query
        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        // Filter by category slug or ID
        if ($categorySlug = $request->input('category')) {
            $category = is_numeric($categorySlug)
                ? Category::find($categorySlug)
                : Category::where('slug', $categorySlug)->first();

            if ($category) {
                $categoryIds = array_merge([$category->id], $category->children()->pluck('id')->all());
                $query->whereIn('category_id', $categoryIds);
            }
        }

        // Filter by seller slug or ID
        if ($sellerSlug = $request->input('seller')) {
            $seller = is_numeric($sellerSlug)
                ? Seller::find($sellerSlug)
                : Seller::where('slug', $sellerSlug)->first();

            if ($seller) {
                $query->where('seller_id', $seller->id);
            }
        }

        // Filter by flags
        if ($request->has('is_featured')) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }
        if ($request->has('is_drop_ready')) {
            $query->where('is_group_buy_enabled', $request->boolean('is_drop_ready'));
        }
        if ($request->has('is_new_arrival')) {
            $query->where('is_new_arrival', $request->boolean('is_new_arrival'));
        }

        // Price range
        if ($request->filled('min_price')) {
            $query->where('base_price', '>=', (float) $request->input('min_price'));
        }
        if ($request->filled('max_price')) {
            $query->where('base_price', '<=', (float) $request->input('max_price'));
        }

        // Sorting
        $sort = $request->input('sort', 'latest');
        match ($sort) {
            'price_low' => $query->orderBy('base_price', 'asc'),
            'price_high' => $query->orderBy('base_price', 'desc'),
            'rating' => $query->orderBy('rating_avg', 'desc'),
            default => $query->latest('id'),
        };

        $perPage = min((int) $request->input('per_page', 20), 50);
        $products = $query->paginate($perPage);

        $data = $products->getCollection()->map(function (Product $product) {
            return $this->formatProduct($product);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * Get single product details by slug.
     */
    public function show(string $slug): JsonResponse
    {
        $product = Product::where(function ($q) use ($slug) {
                $q->where('slug', $slug);
                if (is_numeric($slug)) {
                    $q->orWhere('id', (int) $slug);
                }
            })
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->with([
                'category',
                'seller',
                'activeVariants.attributeItems',
                'activeVariants.image',
                'images',
                'specifications.attribute',
                'specifications.attributeItem',
                'activeCampaign',
            ])
            ->first();

        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        $activeCampaignData = null;
        if ($product->activeCampaign) {
            $ac = $product->activeCampaign;
            $activeCampaignData = [
                'id' => $ac->id,
                'campaign_code' => $ac->campaign_code,
                'title' => $ac->title,
                'status' => $ac->status,
                'group_price' => $ac->group_price,
                'target_participants' => $ac->target_participants,
                'distinct_participants' => $ac->distinct_participants_count,
                'remaining_needed' => $ac->remaining_needed,
                'progress_percent' => $ac->progress_percent,
                'quantity_limit_per_customer' => $ac->quantity_limit_per_customer,
                'start_at' => $ac->start_at?->toIso8601String(),
                'end_at' => $ac->end_at?->toIso8601String(),
                'product_variant_id' => $ac->product_variant_id,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($this->formatProduct($product), [
                'description' => $product->description,
                'brand' => $product->brand,
                'sku' => $product->sku,
                'currency' => $product->currency ?? 'BDT',
                'track_inventory' => $product->track_inventory,
                'min_order_quantity' => $product->min_order_quantity ?? 1,
                'max_order_quantity' => $product->max_order_quantity,
                'is_normal_purchase_enabled' => $product->is_normal_purchase_enabled,
                'is_group_buy_enabled' => $product->is_group_buy_enabled,
                'is_cod_available' => $product->is_cod_available,
                'is_free_shipping' => $product->is_free_shipping,
                'shipping_charge' => $product->shipping_charge ?? 60.00,
                'is_returnable' => $product->is_returnable,
                'return_window_days' => $product->return_window_days ?? 7,
                'is_cancelable' => $product->is_cancelable,
                'cancellation_cutoff_hours' => $product->cancellation_cutoff_hours ?? 24,
                'weight_kg' => $product->weight_kg,
                'dimensions' => $product->dimensions,
                'specifications' => $product->specifications->map(fn ($spec) => [
                    'id' => $spec->id,
                    'attribute_name' => $spec->attribute?->name,
                    'attribute_slug' => $spec->attribute?->slug,
                    'value' => $spec->attributeItem?->label ?? $spec->custom_value,
                    'color_code' => $spec->attributeItem?->color_code,
                ]),
                'variants' => $product->activeVariants->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'price' => $v->price_override ?? $product->base_price,
                    'compare_price' => $v->compare_price ?? $product->compare_price,
                    'group_price' => $v->group_price,
                    'stock_quantity' => $v->stock_quantity,
                    'image_url' => $v->image?->image_url,
                    'attributes' => $v->attributes,
                    'attribute_items' => $v->attributeItems->map(fn ($ai) => [
                        'id' => $ai->id,
                        'attribute_id' => $ai->attribute_id,
                        'label' => $ai->label,
                        'value' => $ai->value,
                        'color_code' => $ai->color_code,
                    ]),
                ]),
                'images' => $product->images->map(fn ($img) => [
                    'id' => $img->id,
                    'image_url' => $img->image_url,
                    'thumbnail_url' => $img->thumbnail_url,
                    'is_primary' => $img->is_primary,
                    'alt_text' => $img->alt_text,
                ]),
                'active_campaign' => $activeCampaignData,
            ]),
        ]);
    }

    /**
     * Active drop campaign details for a product.
     */
    public function activeCampaign(string $slug): JsonResponse
    {
        $product = Product::where(function ($q) use ($slug) {
                $q->where('slug', $slug);
                if (is_numeric($slug)) {
                    $q->orWhere('id', (int) $slug);
                }
            })
            ->where('status', 'published')
            ->firstOrFail();

        $campaign = $product->activeCampaign;

        if (! $campaign) {
            return response()->json([
                'success' => false,
                'message' => 'No active drop campaign for this product.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $campaign->id,
                'campaign_code' => $campaign->campaign_code,
                'title' => $campaign->title,
                'status' => $campaign->status,
                'group_price' => $campaign->group_price,
                'target_participants' => $campaign->target_participants,
                'distinct_participants' => $campaign->distinct_participants_count,
                'remaining_needed' => $campaign->remaining_needed,
                'progress_percent' => $campaign->progress_percent,
                'quantity_limit_per_customer' => $campaign->quantity_limit_per_customer,
                'start_at' => $campaign->start_at?->toIso8601String(),
                'end_at' => $campaign->end_at?->toIso8601String(),
                'product_variant_id' => $campaign->product_variant_id,
                'product' => [
                    'id' => $product->id,
                    'title' => $product->title,
                    'slug' => $product->slug,
                    'base_price' => $product->base_price,
                    'image' => $product->primaryImage?->image_url,
                ],
            ],
        ]);
    }

    /**
     * Top featured and drop-ready items.
     */
    public function featured(): JsonResponse
    {
        $products = Product::where('status', 'published')
            ->where('visibility', 'public')
            ->where('is_featured', true)
            ->with([
                'category:id,name,slug,icon',
                'seller:id,store_name,slug,district,upazila,logo_url',
                'primaryImage',
                'images',
            ])
            ->limit(10)
            ->get()
            ->map(fn (Product $p) => $this->formatProduct($p));

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Standard JSON formatting for product card view.
     */
    private function formatProduct(Product $product): array
    {
        $primaryImg = $product->primaryImage?->image_url
            ?? $product->images->first()?->image_url
            ?? 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=800&auto=format&fit=crop&q=80';

        return [
            'id' => $product->id,
            'title' => $product->title,
            'slug' => $product->slug,
            'short_description' => $product->short_description,
            'base_price' => $product->base_price,
            'compare_price' => $product->compare_price,
            'discount_percent' => $product->discount_percent,
            'stock_quantity' => $product->effective_stock,
            'is_normal_purchase_enabled' => $product->is_normal_purchase_enabled,
            'is_group_buy_enabled' => $product->is_group_buy_enabled,
            'is_featured' => $product->is_featured,
            'is_drop_ready' => $product->is_group_buy_enabled,
            'is_cod_available' => $product->is_cod_available,
            'is_free_shipping' => $product->is_free_shipping,
            'shipping_charge' => $product->shipping_charge ?? 60.00,
            'rating_avg' => $product->rating_avg,
            'rating_count' => $product->rating_count,
            'image' => $primaryImg,
            'category' => [
                'id' => $product->category?->id,
                'name' => $product->category?->name,
                'slug' => $product->category?->slug,
                'icon' => $product->category?->icon,
            ],
            'seller' => [
                'id' => $product->seller?->id,
                'store_name' => $product->seller?->store_name,
                'slug' => $product->seller?->slug,
                'locality' => $product->seller?->upazila ?? $product->seller?->district,
                'logo_url' => $product->seller?->logo_url,
                'rating_avg' => $product->seller?->rating_avg,
            ],
        ];
    }
}
