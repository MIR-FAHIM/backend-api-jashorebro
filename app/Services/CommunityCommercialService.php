<?php

namespace App\Services;

use App\Models\CommunityShop;
use App\Models\GroupBuyCampaign;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopListing;
use App\Models\User;
use App\Models\UserPick;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class CommunityCommercialService
{
    /**
     * Default attribution window for product recommendations in days.
     */
    public const DEFAULT_ATTRIBUTION_WINDOW_DAYS = 7;

    /**
     * Revalidate and compute the commercial revenue allocation for a community shop item.
     *
     * Rules:
     * - Item selling price must be between min_selling_price and max_selling_price.
     * - Gross markup = item selling price - supplier allocation.
     * - Platform fee = gross markup * (platform_fee_percent / 100).
     * - Seller earning = gross markup - platform fee.
     * - All monetary calculations use rounded 2-decimal floats.
     *
     * @throws ValidationException
     */
    public function calculateShopItemAllocation(
        Product $product,
        ?ProductVariant $variant,
        float $requestedPrice,
        ?CommunityShop $shop = null,
        ?User $buyer = null
    ): array {
        // Resolve supplier allocation & min/max bounds
        $supplierAllocation = $variant?->supplier_allocation_price
            ?? $product->supplier_allocation_price
            ?? (float) ($product->cost_price ?? round($product->base_price * 0.85, 2));

        $minPrice = $variant?->min_selling_price
            ?? $product->min_selling_price
            ?? $supplierAllocation;

        $maxPrice = $variant?->max_selling_price
            ?? $product->max_selling_price
            ?? round($product->base_price * 1.5, 2);

        $platformFeePercent = (float) ($product->platform_fee_percent ?? 10.00);

        if ($requestedPrice < $minPrice || $requestedPrice > $maxPrice) {
            throw ValidationException::withMessages([
                'selling_price' => [
                    "Selling price ৳{$requestedPrice} for '{$product->title}' must be between ৳{$minPrice} and ৳{$maxPrice}."
                ],
            ]);
        }

        $grossMarkup = round(max(0.00, $requestedPrice - $supplierAllocation), 2);
        $platformFee = round(($grossMarkup * $platformFeePercent) / 100.0, 2);
        $sellerEarning = round(max(0.00, $grossMarkup - $platformFee), 2);

        // Self-purchase prevention: Community sellers generate 0 earnings on their own purchases
        $isSelfPurchase = false;
        if ($buyer && $shop && $buyer->id === $shop->user_id) {
            $isSelfPurchase = true;
            $sellerEarning = 0.00;
        }

        return [
            'earning_model' => 'community_shop',
            'unit_price' => $requestedPrice,
            'supplier_allocation_price' => $supplierAllocation,
            'gross_markup' => $grossMarkup,
            'platform_fee' => $platformFee,
            'seller_earning' => $sellerEarning,
            'commission_amount' => 0.00,
            'is_self_purchase' => $isSelfPurchase,
            'terms_snapshot' => [
                'policy_version' => '1.0',
                'min_selling_price' => $minPrice,
                'max_selling_price' => $maxPrice,
                'platform_fee_percent' => $platformFeePercent,
                'calculated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Compute recommendation commission allocation for catalog sales.
     */
    public function calculateRecommendationAllocation(
        Product $product,
        ?ProductVariant $variant,
        User $recommender,
        ?User $buyer = null
    ): array {
        $catalogPrice = $variant ? (float) $variant->effective_price : (float) $product->base_price;
        $commissionPerUnit = $variant?->recommendation_commission
            ?? (float) ($product->recommendation_commission ?? 0.00);

        $supplierAllocation = $variant?->supplier_allocation_price
            ?? $product->supplier_allocation_price
            ?? round(max(0.00, $catalogPrice - $commissionPerUnit), 2);

        // Self-purchase prevention
        $isSelfPurchase = false;
        if ($buyer && $buyer->id === $recommender->id) {
            $isSelfPurchase = true;
            $commissionPerUnit = 0.00;
        }

        return [
            'earning_model' => 'recommendation',
            'unit_price' => $catalogPrice,
            'supplier_allocation_price' => $supplierAllocation,
            'gross_markup' => $commissionPerUnit,
            'platform_fee' => 0.00,
            'seller_earning' => 0.00,
            'commission_amount' => $commissionPerUnit,
            'is_self_purchase' => $isSelfPurchase,
            'terms_snapshot' => [
                'policy_version' => '1.0',
                'fixed_commission_per_unit' => $commissionPerUnit,
                'calculated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Compute community group drop allocation.
     */
    public function calculateGroupDropAllocation(
        GroupBuyCampaign $campaign,
        Product $product,
        ?ProductVariant $variant,
        ?User $buyer = null
    ): array {
        $groupPrice = (float) $campaign->group_price;
        $organizerCommission = (float) ($campaign->organizer_commission_per_unit ?? 0.00);
        $supplierAllocation = (float) ($campaign->supplier_allocation_price > 0
            ? $campaign->supplier_allocation_price
            : max(0.00, $groupPrice - $organizerCommission));

        $isSelfPurchase = false;
        if ($buyer && $campaign->organizer_user_id && $buyer->id === $campaign->organizer_user_id) {
            $isSelfPurchase = true;
            $organizerCommission = 0.00;
        }

        return [
            'earning_model' => 'group_drop',
            'unit_price' => $groupPrice,
            'supplier_allocation_price' => $supplierAllocation,
            'gross_markup' => $organizerCommission,
            'platform_fee' => 0.00,
            'seller_earning' => $organizerCommission,
            'commission_amount' => 0.00,
            'is_self_purchase' => $isSelfPurchase,
            'terms_snapshot' => [
                'policy_version' => '1.0',
                'group_price' => $groupPrice,
                'organizer_commission' => $organizerCommission,
                'calculated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Authoritatively resolve attribution and commercial terms for a single cart/order item line.
     *
     * Precedence:
     * 1. Active Community Group Drop.
     * 2. Verified Community Shop Listing.
     * 3. Valid Product Recommendation within attribution window.
     * 4. Default Direct Catalog (no community earnings).
     */
    public function resolveLineAttribution(
        array $lineInput,
        Product $product,
        ?ProductVariant $variant,
        ?User $buyer = null
    ): array {
        // 1. Group Drop Campaign
        if (! empty($lineInput['campaign_id'])) {
            $campaign = GroupBuyCampaign::find($lineInput['campaign_id']);
            if ($campaign && $campaign->organizer_user_id) {
                $allocation = $this->calculateGroupDropAllocation($campaign, $product, $variant, $buyer);
                return array_merge($allocation, [
                    'community_shop_id' => $campaign->originating_shop_id,
                    'shop_listing_id' => null,
                    'recommender_id' => null,
                    'recommendation_code' => null,
                    'beneficiary_user_id' => $campaign->organizer_user_id,
                ]);
            }
        }

        // 2. Explicit Community Shop Listing
        if (! empty($lineInput['shop_listing_id'])) {
            $listing = ShopListing::with('shop')->find($lineInput['shop_listing_id']);
            if ($listing && $listing->is_active && $listing->shop && $listing->shop->status === 'active') {
                $shop = $listing->shop;
                $allocation = $this->calculateShopItemAllocation(
                    product: $product,
                    variant: $variant,
                    requestedPrice: (float) $listing->selling_price,
                    shop: $shop,
                    buyer: $buyer
                );

                return array_merge($allocation, [
                    'community_shop_id' => $shop->id,
                    'shop_listing_id' => $listing->id,
                    'recommender_id' => null,
                    'recommendation_code' => null,
                    'beneficiary_user_id' => $shop->user_id,
                ]);
            }
        }

        // 3. Recommendation link or referral code
        $recCode = $lineInput['recommendation_code'] ?? null;
        $recommenderId = $lineInput['recommender_id'] ?? null;

        if ($recCode || $recommenderId) {
            $pick = null;
            if ($recCode) {
                $pick = UserPick::with('user')
                    ->where('recommendation_code', $recCode)
                    ->where('product_id', $product->id)
                    ->first();
            } elseif ($recommenderId) {
                $pick = UserPick::with('user')
                    ->where('user_id', $recommenderId)
                    ->where('product_id', $product->id)
                    ->first();
            }

            if ($pick && $pick->user && $product->is_recommendation_enabled) {
                // Verify attribution window (created within configured days, default 7)
                $windowDays = self::DEFAULT_ATTRIBUTION_WINDOW_DAYS;
                $isWithinWindow = $pick->created_at->diffInDays(now()) <= $windowDays;

                if ($isWithinWindow) {
                    $allocation = $this->calculateRecommendationAllocation(
                        product: $product,
                        variant: $variant,
                        recommender: $pick->user,
                        buyer: $buyer
                    );

                    return array_merge($allocation, [
                        'community_shop_id' => null,
                        'shop_listing_id' => null,
                        'recommender_id' => $pick->user_id,
                        'recommendation_code' => $pick->recommendation_code,
                        'beneficiary_user_id' => $pick->user_id,
                    ]);
                }
            }
        }

        // 4. Default: Direct catalog purchase (no community earnings)
        $catalogPrice = $variant ? (float) $variant->effective_price : (float) $product->base_price;
        $supplierAllocation = $variant?->supplier_allocation_price
            ?? $product->supplier_allocation_price
            ?? (float) ($product->cost_price ?? $catalogPrice);

        return [
            'earning_model' => 'none',
            'unit_price' => $catalogPrice,
            'supplier_allocation_price' => $supplierAllocation,
            'gross_markup' => 0.00,
            'platform_fee' => 0.00,
            'seller_earning' => 0.00,
            'commission_amount' => 0.00,
            'is_self_purchase' => false,
            'community_shop_id' => null,
            'shop_listing_id' => null,
            'recommender_id' => null,
            'recommendation_code' => null,
            'beneficiary_user_id' => null,
            'terms_snapshot' => [
                'policy_version' => '1.0',
                'channel' => 'direct_catalog',
                'calculated_at' => now()->toIso8601String(),
            ],
        ];
    }
}
