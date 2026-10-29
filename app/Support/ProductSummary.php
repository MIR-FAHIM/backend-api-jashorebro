<?php

namespace App\Support;

use App\Models\Product;

/**
 * Customer-safe marketplace facts shared by catalog and Pick responses.
 */
class ProductSummary
{
    public static function relations(): array
    {
        return [
            'primaryImage', 'images', 'activeVariants.image',
            'category:id,name,slug,icon',
            'seller:id,store_name,slug,district,upazila,logo_url,rating_avg',
        ];
    }

    public static function make(Product $product): array
    {
        $product->loadMissing(self::relations());
        if ($product->getAttribute('variants_count') === null) {
            $product->loadCount('variants');
        }

        $activeVariants = $product->activeVariants;
        $hasVariants = $activeVariants->isNotEmpty();
        $prices = $hasVariants
            ? $activeVariants->map(fn ($variant) => (float) ($variant->price_override ?? $product->base_price))
            : collect([(float) $product->base_price]);
        $stockQuantity = $product->variants_count > 0
            ? (int) $activeVariants->sum('stock_quantity')
            : (int) $product->stock_quantity;
        $minimumOrderQuantity = max(1, (int) ($product->min_order_quantity ?? 1));
        $hasOrderableStock = $hasVariants
            ? $activeVariants->contains(fn ($variant) => (int) $variant->stock_quantity >= $minimumOrderQuantity)
            : $stockQuantity >= $minimumOrderQuantity;

        return [
            'id' => $product->id,
            'title' => $product->title,
            'slug' => $product->slug,
            'short_description' => $product->short_description,
            'brand' => $product->brand,
            'currency' => $product->currency ?? 'BDT',
            'base_price' => $product->base_price,
            'compare_price' => $product->compare_price,
            'has_variants' => $hasVariants,
            'price_from' => $prices->min(),
            'price_to' => $prices->max(),
            'discount_percent' => $product->discount_percent,
            'stock_quantity' => $stockQuantity,
            'track_inventory' => $product->track_inventory,
            'min_order_quantity' => $minimumOrderQuantity,
            'max_order_quantity' => $product->max_order_quantity,
            'availability' => ! $product->track_inventory ? 'not_tracked' : ($hasOrderableStock ? 'in_stock' : 'out_of_stock'),
            'is_available' => $product->status === 'published' && $product->visibility === 'public' && $product->is_active,
            'status' => $product->status,
            'visibility' => $product->visibility,
            'is_active' => $product->is_active,
            'is_normal_purchase_enabled' => $product->is_normal_purchase_enabled,
            'is_group_buy_enabled' => $product->is_group_buy_enabled,
            'is_community_shop_enabled' => $product->is_community_shop_enabled,
            'is_recommendation_enabled' => $product->is_recommendation_enabled,
            'recommendation_commission' => $product->recommendation_commission,
            'is_group_drop_enabled' => $product->is_group_drop_enabled,
            'is_featured' => $product->is_featured,
            'is_drop_ready' => $product->is_group_buy_enabled,
            'is_cod_available' => $product->is_cod_available,
            'is_free_shipping' => $product->is_free_shipping,
            'shipping_charge' => $product->shipping_charge ?? 60.00,
            'rating_avg' => $product->rating_avg,
            'rating_count' => $product->rating_count,
            'image' => $product->primaryImage?->image_url ?? $product->images->first()?->image_url,
            'active_variants' => $activeVariants->map(fn ($variant) => [
                'id' => $variant->id,
                'product_id' => $variant->product_id,
                'name' => $variant->name,
                'price_override' => $variant->price_override,
                'price' => $variant->price_override ?? $product->base_price,
                'compare_price' => $variant->compare_price ?? $product->compare_price,
                'stock_quantity' => $variant->stock_quantity,
                'attributes' => $variant->attributes,
                'image_url' => $variant->image?->image_url,
                'is_active' => $variant->is_active,
            ])->values()->all(),
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
