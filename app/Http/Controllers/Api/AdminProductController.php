<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\AdminProductService;
use App\Services\LogService;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminProductController extends Controller
{
    public function __construct(
        protected AdminProductService $productService
    ) {}

    /**
     * List all products for administrative management (including drafts & archived).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->with([
                'category:id,name,slug',
                'seller:id,store_name,slug',
                'primaryImage',
            ])
            ->withCount('variants');

        // Search query
        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        // Category filter
        if ($catId = $request->input('category_id')) {
            $query->where('category_id', $catId);
        }

        // Group buy filter
        if ($request->has('is_group_buy_enabled')) {
            $query->where('is_group_buy_enabled', $request->boolean('is_group_buy_enabled'));
        }

        $perPage = min(100, max(5, $request->integer('per_page', 20)));
        $products = $query->latest('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $products->getCollection(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * Get detailed product data for administrative editing.
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::with([
            'category',
            'seller',
            'images',
            'primaryImage',
            'specifications.attribute',
            'specifications.attributeItem',
            'variants.attributeItems',
            'activeCampaign',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    /**
     * Create a new product. Incomplete drafts are permitted.
     */
    public function store(Request $request): JsonResponse
    {
        $status = $request->input('status', 'draft');

        // Base validation rules
        $rules = [
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:products,slug',
            'category_id' => $status === 'published' ? 'required|exists:categories,id' : 'nullable|exists:categories,id',
            'brand' => 'nullable|string|max:100',
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'status' => 'required|in:draft,published,archived',
            'visibility' => 'required|in:public,hidden',
            'currency' => 'nullable|string|max:10',
            'base_price' => $status === 'published' ? 'required|numeric|min:0' : 'nullable|numeric|min:0',
            'compare_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'track_inventory' => 'boolean',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'min_order_quantity' => 'nullable|integer|min:1',
            'max_order_quantity' => 'nullable|integer|min:1',
            'is_normal_purchase_enabled' => 'boolean',
            'is_group_buy_enabled' => 'boolean',
            'is_recommendation_enabled' => 'boolean',
            'recommendation_commission' => 'nullable|numeric|min:0',
            'is_community_shop_enabled' => 'boolean',
            'supplier_allocation_price' => 'nullable|numeric|min:0',
            'min_selling_price' => 'nullable|numeric|min:0',
            'max_selling_price' => 'nullable|numeric|min:0',
            'platform_fee_percent' => 'nullable|numeric|min:0|max:100',
            'is_group_drop_enabled' => 'boolean',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_cod_available' => 'boolean',
            'is_free_shipping' => 'boolean',
            'shipping_charge' => 'nullable|numeric|min:0',
            'is_returnable' => 'boolean',
            'return_window_days' => 'nullable|integer|min:0',
            'is_cancelable' => 'boolean',
            'cancellation_cutoff_hours' => 'nullable|integer|min:0',
            'weight_kg' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|array',
            'tags' => 'nullable|array',
            'seller_id' => 'nullable|exists:sellers,id',
            'specification_attributes' => 'nullable|array',
            'variants' => 'nullable|array',
        ];

        $validated = $request->validate($rules);

        // Published products must support at least one purchase mode
        if ($status === 'published') {
            $normalEnabled = $request->boolean('is_normal_purchase_enabled', true);
            $groupBuyEnabled = $request->boolean('is_group_buy_enabled', false);

            if (! $normalEnabled && ! $groupBuyEnabled) {
                throw ValidationException::withMessages([
                    'is_normal_purchase_enabled' => ['A published product must enable at least one purchase mode (Normal Purchase or Group Buy).'],
                ]);
            }
        }

        try {
            $product = $this->productService->createProduct($validated, $request->user()->id);
        } catch (\Throwable $e) {
            app(LogService::class)->record(
                event: 'product.creation_failed',
                outcome: 'failure',
                actor: $request->user(),
                subject: null,
                metadata: [
                    'title' => $request->input('title', 'Unknown'),
                    'sku' => $request->input('sku'),
                    'failure_reason' => $e->getMessage(),
                ],
                message: 'Product creation failed: ' . $e->getMessage()
            );

            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'data' => $product,
        ], 201);
    }

    /**
     * Update an existing product.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $status = $request->input('status', $product->status);

        $rules = [
            'title' => 'sometimes|required|string|max:255',
            'slug' => "sometimes|nullable|string|max:255|unique:products,slug,{$id}",
            'category_id' => $status === 'published' ? 'sometimes|required|exists:categories,id' : 'nullable|exists:categories,id',
            'brand' => 'nullable|string|max:100',
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'status' => 'sometimes|required|in:draft,published,archived',
            'visibility' => 'sometimes|required|in:public,hidden',
            'currency' => 'nullable|string|max:10',
            'base_price' => $status === 'published' ? 'sometimes|required|numeric|min:0' : 'nullable|numeric|min:0',
            'compare_price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'track_inventory' => 'boolean',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'min_order_quantity' => 'nullable|integer|min:1',
            'max_order_quantity' => 'nullable|integer|min:1',
            'is_normal_purchase_enabled' => 'boolean',
            'is_group_buy_enabled' => 'boolean',
            'is_recommendation_enabled' => 'boolean',
            'recommendation_commission' => 'nullable|numeric|min:0',
            'is_community_shop_enabled' => 'boolean',
            'supplier_allocation_price' => 'nullable|numeric|min:0',
            'min_selling_price' => 'nullable|numeric|min:0',
            'max_selling_price' => 'nullable|numeric|min:0',
            'platform_fee_percent' => 'nullable|numeric|min:0|max:100',
            'is_group_drop_enabled' => 'boolean',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_cod_available' => 'boolean',
            'is_free_shipping' => 'boolean',
            'shipping_charge' => 'nullable|numeric|min:0',
            'is_returnable' => 'boolean',
            'return_window_days' => 'nullable|integer|min:0',
            'is_cancelable' => 'boolean',
            'cancellation_cutoff_hours' => 'nullable|integer|min:0',
            'weight_kg' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|array',
            'tags' => 'nullable|array',
            'seller_id' => 'nullable|exists:sellers,id',
            'specification_attributes' => 'nullable|array',
            'variants' => 'nullable|array',
        ];

        $validated = $request->validate($rules);

        // Validation for published products
        if ($status === 'published') {
            $normalEnabled = $request->has('is_normal_purchase_enabled') ? $request->boolean('is_normal_purchase_enabled') : $product->is_normal_purchase_enabled;
            $groupBuyEnabled = $request->has('is_group_buy_enabled') ? $request->boolean('is_group_buy_enabled') : $product->is_group_buy_enabled;

            if (! $normalEnabled && ! $groupBuyEnabled) {
                throw ValidationException::withMessages([
                    'is_normal_purchase_enabled' => ['A published product must enable at least one purchase mode.'],
                ]);
            }

            // Published product must have a primary image
            if (! $product->images()->where('is_primary', true)->exists() && ! $product->images()->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Please upload at least one product image before publishing.'],
                ]);
            }
        }

        $updatedProduct = $this->productService->updateProduct($product, $validated, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data' => $updatedProduct,
        ]);
    }

    /**
     * Archive product (soft delete).
     */
    public function destroy(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->update(['status' => 'archived']);
        $product->delete();

        app(LogService::class)->record(
            event: 'product.archived',
            outcome: 'success',
            actor: request()->user(),
            subject: $product,
            metadata: [
                'product_id' => $product->id,
                'title' => $product->title,
                'sku' => $product->sku,
            ],
            message: "Product '{$product->title}' archived."
        );

        return response()->json([
            'success' => true,
            'message' => 'Product archived successfully.',
        ]);
    }

    /**
     * Upload product images with validation.
     */
    public function uploadImages(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $request->validate([
            'images' => 'required|array',
            'images.*' => 'required|image|mimes:jpeg,png,webp,jpg|max:5120',
        ]);

        $uploaded = [];
        $existingCount = $product->images()->count();
        $hasPrimary = $product->images()->where('is_primary', true)->exists();

        foreach ($request->file('images') as $index => $file) {
            $path = $file->store("products/{$product->id}", 'public');
            $imageUrl = MediaUrl::resolve(Storage::disk('public')->url($path), $path);

            $isPrimary = (! $hasPrimary && $index === 0);
            if ($isPrimary) $hasPrimary = true;

            $image = ProductImage::create([
                'product_id' => $product->id,
                'image_url' => $imageUrl,
                'file_path' => $path,
                'alt_text' => $product->title,
                'is_primary' => $isPrimary,
                'sort_order' => $existingCount + $index,
            ]);

            $uploaded[] = $image;
        }

        return response()->json([
            'success' => true,
            'message' => count($uploaded) . ' image(s) uploaded successfully.',
            'data' => $uploaded,
        ]);
    }

    /**
     * Reorder images, assign primary, and edit alt text.
     */
    public function updateImages(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $request->validate([
            'images' => 'required|array',
            'images.*.id' => 'required|exists:product_images,id',
            'images.*.sort_order' => 'required|integer',
            'images.*.is_primary' => 'required|boolean',
            'images.*.alt_text' => 'nullable|string|max:255',
        ]);

        $items = $request->input('images');
        $primaryCount = collect($items)->where('is_primary', true)->count();

        if ($primaryCount > 1) {
            throw ValidationException::withMessages([
                'images' => ['Only one image can be designated as the primary image.'],
            ]);
        }

        foreach ($items as $imgData) {
            ProductImage::where('id', $imgData['id'])
                ->where('product_id', $product->id)
                ->update([
                    'sort_order' => $imgData['sort_order'],
                    'is_primary' => $imgData['is_primary'],
                    'alt_text' => $imgData['alt_text'] ?? $product->title,
                ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Images updated successfully.',
            'data' => $product->images()->orderBy('sort_order')->get(),
        ]);
    }

    /**
     * Delete an image.
     */
    public function deleteImage(Request $request, int $productId, int $imageId): JsonResponse
    {
        $image = ProductImage::where('product_id', $productId)->where('id', $imageId)->firstOrFail();
        $wasPrimary = $image->is_primary;
        $image->delete();

        // If deleted image was primary, make the next image primary
        if ($wasPrimary) {
            $nextImage = ProductImage::where('product_id', $productId)->orderBy('sort_order')->first();
            if ($nextImage) {
                $nextImage->update(['is_primary' => true]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Image removed successfully.',
        ]);
    }

    /**
     * Categories lookup for product create/edit forms.
     */
    public function categoriesLookup(): JsonResponse
    {
        $categories = Category::where('is_active', true)->orderBy('order_index')->get(['id', 'name', 'slug', 'icon']);

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }
}
