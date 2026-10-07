<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Search and filter product catalog.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
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
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        // Filter by category slug or ID
        if ($categorySlug = $request->input('category')) {
            $category = is_numeric($categorySlug)
                ? Category::find($categorySlug)
                : Category::where('slug', $categorySlug)->first();

            if ($category) {
                // Also include children categories if parent
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

        // Filter by featured
        if ($request->has('is_featured')) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }

        // Filter by drop ready
        if ($request->has('is_drop_ready')) {
            $query->where('is_drop_ready', $request->boolean('is_drop_ready'));
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
            default => $query->latest(),
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
        $product = Product::where('slug', $slug)
            ->where('is_active', true)
            ->with([
                'category',
                'seller',
                'variants' => fn ($q) => $q->where('is_active', true),
                'images',
            ])
            ->first();

        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Product not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge($this->formatProduct($product), [
                'description' => $product->description,
                'variants' => $product->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'sku' => $v->sku,
                    'price' => $v->price_override ?? $product->base_price,
                    'stock_quantity' => $v->stock_quantity,
                    'attributes' => $v->attributes,
                ]),
                'images' => $product->images->map(fn ($img) => [
                    'id' => $img->id,
                    'image_url' => $img->image_url,
                    'is_primary' => $img->is_primary,
                ]),
            ]),
        ]);
    }

    /**
     * Top featured and drop-ready items.
     */
    public function featured(): JsonResponse
    {
        $products = Product::where('is_active', true)
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
            'stock_quantity' => $product->stock_quantity,
            'is_featured' => $product->is_featured,
            'is_drop_ready' => $product->is_drop_ready,
            'rating_avg' => $product->rating_avg,
            'rating_count' => $product->rating_count,
            'image' => $primaryImg,
            'category' => [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
                'icon' => $product->category->icon,
            ],
            'seller' => [
                'id' => $product->seller->id,
                'store_name' => $product->seller->store_name,
                'slug' => $product->seller->slug,
                'locality' => $product->seller->upazila ?? $product->seller->district,
                'logo_url' => $product->seller->logo_url,
                'rating_avg' => $product->seller->rating_avg,
            ],
        ];
    }
}
