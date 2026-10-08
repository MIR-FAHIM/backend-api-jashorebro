<?php

namespace App\Services;

use App\Models\AttributeItem;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminProductService
{
    /**
     * Get or create the platform-owned marketplace seller for admin-managed products.
     */
    public function getOrCreatePlatformSeller(?int $userId = null): Seller
    {
        $platformSeller = Seller::where('slug', 'jashorebro-direct')->first();

        if ($platformSeller) {
            return $platformSeller;
        }

        $adminUser = $userId ? User::find($userId) : User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'super_admin']))->first();
        if (! $adminUser) {
            $adminUser = User::first();
        }

        return Seller::create([
            'user_id' => $adminUser->id,
            'store_name' => 'JashoreBro Direct',
            'slug' => 'jashorebro-direct',
            'tagline' => 'Official JashoreBro Marketplace Fulfillment',
            'description' => 'Curated platform-managed authentic goods fulfilled directly from the Jashore regional warehouse.',
            'contact_phone' => $adminUser->phone ?? '+8801700000000',
            'district' => 'Jashore',
            'upazila' => 'Jashore Sadar',
            'address' => 'Central Hub, Doratana, Jashore',
            'status' => 'active',
            'verified_at' => now(),
            'rating_avg' => 5.0,
            'rating_count' => 10,
        ]);
    }

    /**
     * Atomically create a product with specification attributes, variants, and platform seller defaults.
     */
    public function createProduct(array $data, int $userId): Product
    {
        return DB::transaction(function () use ($data, $userId) {
            // Assign platform seller if seller_id is missing or admin-owned
            if (empty($data['seller_id'])) {
                $seller = $this->getOrCreatePlatformSeller($userId);
                $data['seller_id'] = $seller->id;
            }

            // Ensure unique slug
            if (empty($data['slug'])) {
                $data['slug'] = Str::slug($data['title']);
            }
            $baseSlug = $data['slug'];
            $slugCounter = 1;
            while (Product::where('slug', $data['slug'])->exists()) {
                $data['slug'] = "{$baseSlug}-{$slugCounter}";
                $slugCounter++;
            }

            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;

            // Separate nested attributes and variants
            $specifications = $data['specification_attributes'] ?? [];
            $variants = $data['variants'] ?? [];
            unset($data['specification_attributes'], $data['variants'], $data['images']);

            /** @var Product $product */
            $product = Product::create($data);

            // Save specification attributes
            $this->syncSpecifications($product, $specifications);

            // Save variants
            if (! empty($variants)) {
                $this->syncVariants($product, $variants);
            }

            return $product->fresh(['category', 'seller', 'primaryImage', 'images', 'specifications', 'variants.attributeItems']);
        });
    }

    /**
     * Atomically update a product, preserving existing variant IDs, prices, and stock where combinations still exist.
     */
    public function updateProduct(Product $product, array $data, int $userId): Product
    {
        return DB::transaction(function () use ($product, $data, $userId) {
            $data['updated_by'] = $userId;

            if (isset($data['slug']) && $data['slug'] !== $product->slug) {
                $baseSlug = Str::slug($data['slug']);
                $slugCounter = 1;
                while (Product::where('slug', $data['slug'])->where('id', '!=', $product->id)->exists()) {
                    $data['slug'] = "{$baseSlug}-{$slugCounter}";
                    $slugCounter++;
                }
            }

            $specifications = $data['specification_attributes'] ?? null;
            $variants = $data['variants'] ?? null;
            unset($data['specification_attributes'], $data['variants'], $data['images']);

            $product->update($data);

            if ($specifications !== null) {
                $this->syncSpecifications($product, $specifications);
            }

            if ($variants !== null) {
                $this->syncVariants($product, $variants);
            }

            return $product->fresh(['category', 'seller', 'primaryImage', 'images', 'specifications', 'variants.attributeItems']);
        });
    }

    /**
     * Sync specification attributes (non-purchasable option descriptors).
     */
    protected function syncSpecifications(Product $product, array $specifications): void
    {
        ProductAttributeValue::where('product_id', $product->id)
            ->where('is_variant_option', false)
            ->delete();

        foreach ($specifications as $spec) {
            if (empty($spec['attribute_id'])) continue;

            ProductAttributeValue::create([
                'product_id' => $product->id,
                'attribute_id' => $spec['attribute_id'],
                'attribute_item_id' => $spec['attribute_item_id'] ?? null,
                'custom_value' => $spec['custom_value'] ?? null,
                'is_variant_option' => false,
            ]);
        }
    }

    /**
     * Sync and preserve variants by matching attribute combinations or existing IDs.
     */
    protected function syncVariants(Product $product, array $variantsData): void
    {
        $existingVariants = $product->variants()->get()->keyBy('id');
        $keptVariantIds = [];

        foreach ($variantsData as $vData) {
            $variantId = $vData['id'] ?? null;
            $attributeItemIds = $vData['attribute_item_ids'] ?? [];

            // If ID matches existing variant, update in place
            if ($variantId && isset($existingVariants[$variantId])) {
                /** @var ProductVariant $variant */
                $variant = $existingVariants[$variantId];
                $variant->update([
                    'name' => $vData['name'] ?? $variant->name,
                    'sku' => $vData['sku'] ?? $variant->sku,
                    'barcode' => $vData['barcode'] ?? $variant->barcode,
                    'price_override' => $vData['price_override'] ?? $variant->price_override,
                    'compare_price' => $vData['compare_price'] ?? $variant->compare_price,
                    'group_price' => $vData['group_price'] ?? $variant->group_price,
                    'stock_quantity' => $vData['stock_quantity'] ?? $variant->stock_quantity,
                    'image_id' => $vData['image_id'] ?? $variant->image_id,
                    'is_active' => $vData['is_active'] ?? true,
                ]);

                if (! empty($attributeItemIds)) {
                    $variant->attributeItems()->sync($attributeItemIds);
                }

                $keptVariantIds[] = $variant->id;
            } else {
                // Check if combination already exists by attribute items
                $matchedVariant = null;
                if (! empty($attributeItemIds)) {
                    foreach ($existingVariants as $candidate) {
                        $candidateItemIds = $candidate->attributeItems()->pluck('attribute_items.id')->all();
                        sort($candidateItemIds);
                        $checkIds = $attributeItemIds;
                        sort($checkIds);
                        if ($candidateItemIds === $checkIds) {
                            $matchedVariant = $candidate;
                            break;
                        }
                    }
                }

                if ($matchedVariant) {
                    $matchedVariant->update([
                        'name' => $vData['name'] ?? $matchedVariant->name,
                        'sku' => $vData['sku'] ?? $matchedVariant->sku,
                        'price_override' => $vData['price_override'] ?? $matchedVariant->price_override,
                        'compare_price' => $vData['compare_price'] ?? $matchedVariant->compare_price,
                        'group_price' => $vData['group_price'] ?? $matchedVariant->group_price,
                        'stock_quantity' => $vData['stock_quantity'] ?? $matchedVariant->stock_quantity,
                        'is_active' => $vData['is_active'] ?? true,
                    ]);
                    $keptVariantIds[] = $matchedVariant->id;
                } else {
                    // Create new variant
                    $newVariant = ProductVariant::create([
                        'product_id' => $product->id,
                        'name' => $vData['name'],
                        'sku' => $vData['sku'] ?? ($product->sku ? "{$product->sku}-" . Str::random(4) : null),
                        'barcode' => $vData['barcode'] ?? null,
                        'price_override' => $vData['price_override'] ?? null,
                        'compare_price' => $vData['compare_price'] ?? null,
                        'group_price' => $vData['group_price'] ?? null,
                        'stock_quantity' => $vData['stock_quantity'] ?? 0,
                        'image_id' => $vData['image_id'] ?? null,
                        'is_active' => $vData['is_active'] ?? true,
                    ]);

                    if (! empty($attributeItemIds)) {
                        $newVariant->attributeItems()->sync($attributeItemIds);
                    }

                    $keptVariantIds[] = $newVariant->id;
                }
            }
        }

        // Soft delete / archive omitted variants that are no longer part of combinations
        $toArchive = $existingVariants->keys()->diff($keptVariantIds);
        if ($toArchive->isNotEmpty()) {
            ProductVariant::whereIn('id', $toArchive)->delete();
        }

        // Aggregate stock to product-level
        $aggregateStock = (int) $product->variants()->where('is_active', true)->sum('stock_quantity');
        $product->update(['stock_quantity' => $aggregateStock]);
    }
}
