<?php

namespace App\Services;

use App\Models\CommunityShop;
use App\Models\GroupBuyCampaign;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopListing;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CommunityCommercialService
{
    public function __construct(protected ReferralAttributionService $referralAttributionService) {}

    /**
     * Default attribution window for product recommendations in days.
     */
    public const DEFAULT_ATTRIBUTION_WINDOW_DAYS = ReferralAttributionService::WINDOW_DAYS;

    /**
     * Resolve the configured resale terms shared by seller previews and allocation checks.
     *
     * @return array{is_enabled: bool, is_configured: bool, supplier_allocation_price: ?float, min_selling_price: ?float, max_selling_price: ?float, platform_fee_percent: ?float, reason: ?string, message: ?string}
     */
    public function resolveShopResaleTerms(Product $product, ?ProductVariant $variant = null): array
    {
        $terms = [
            'is_enabled' => (bool) $product->is_community_shop_enabled,
            'is_configured' => false,
            'supplier_allocation_price' => null,
            'min_selling_price' => null,
            'max_selling_price' => null,
            'platform_fee_percent' => null,
            'reason' => null,
            'message' => null,
        ];

        if (! $terms['is_enabled']) {
            $terms['reason'] = 'disabled';
            $terms['message'] = "The product '{$product->title}' is not eligible for community shop listings.";

            return $terms;
        }

        $supplierAllocation = $variant?->supplier_allocation_price
            ?? $product->supplier_allocation_price
            ?? $product->cost_price;
        $minPrice = $variant?->min_selling_price
            ?? $product->min_selling_price
            ?? $supplierAllocation;
        $maxPrice = $variant?->max_selling_price
            ?? $product->max_selling_price;

        $terms['supplier_allocation_price'] = $supplierAllocation === null ? null : (float) $supplierAllocation;
        $terms['min_selling_price'] = $minPrice === null ? null : (float) $minPrice;
        $terms['max_selling_price'] = $maxPrice === null ? null : (float) $maxPrice;
        $terms['platform_fee_percent'] = (float) ($product->platform_fee_percent ?? 10.00);

        if ($supplierAllocation === null) {
            $terms['reason'] = 'missing_supplier_allocation';
            $terms['message'] = "Commercial wholesale pricing is not configured for '{$product->title}'. This product cannot be listed in a community shop.";
        } elseif ($maxPrice === null) {
            $terms['reason'] = 'missing_max_price';
            $terms['message'] = "Maximum retail price ceiling is not configured for '{$product->title}'. This product cannot be listed in a community shop.";
        } elseif ($supplierAllocation < 0 || $minPrice < 0 || $maxPrice < $minPrice
            || $terms['platform_fee_percent'] < 0 || $terms['platform_fee_percent'] > 100) {
            $terms['reason'] = 'invalid_price_bounds';
            $terms['message'] = "Resale pricing bounds are invalid for '{$product->title}'. An administrator must update its commercial settings.";
        } else {
            $terms['is_configured'] = true;
        }

        return $terms;
    }

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
        $terms = $this->resolveShopResaleTerms($product, $variant);

        if (! $terms['is_configured']) {
            throw ValidationException::withMessages([
                'selling_price' => [$terms['message']],
            ]);
        }
        $supplierAllocation = (float) $terms['supplier_allocation_price'];
        $minPrice = (float) $terms['min_selling_price'];
        $maxPrice = (float) $terms['max_selling_price'];
        $platformFeePercent = (float) $terms['platform_fee_percent'];

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
        $organizerCommission = $campaign->organizer_user_id
            ? (float) ($campaign->organizer_commission_per_unit ?? 0.00)
            : 0.00;
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
        $this->validateLineSourceIdentity($lineInput, $product, $variant);
        $hasReferral = ! empty($lineInput['attribution_token']) || ! empty($lineInput['attribution_visitor_token'])
            || ! empty($lineInput['recommendation_code']) || ! empty($lineInput['recommender_id']);

        // 1. Group Drop Campaign
        if (! empty($lineInput['campaign_id'])) {
            $campaign = GroupBuyCampaign::find($lineInput['campaign_id']);
            if ($campaign && $campaign->status === 'active' && ! $campaign->isExpired()
                && (! $campaign->start_at || ! $campaign->start_at->isFuture())) {
                $allocation = $this->calculateGroupDropAllocation($campaign, $product, $variant, $buyer);
                return array_merge($allocation, [
                    'community_shop_id' => $campaign->originating_shop_id,
                    'shop_listing_id' => null,
                    'recommender_id' => null,
                    'recommendation_code' => null,
                    'beneficiary_user_id' => $campaign->organizer_user_id,
                    'referral_status' => $hasReferral ? 'overridden' : 'none',
                    'referral_reason' => $hasReferral ? 'campaign_precedence' : null,
                    'referral' => null,
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
                    'referral_status' => $hasReferral ? 'overridden' : 'none',
                    'referral_reason' => $hasReferral ? 'shop_precedence' : null,
                    'referral' => null,
                ]);
            }
        }

        // 3. Only a locked server receipt can authorize a recommendation commission.
        $referralResult = $hasReferral
            ? $this->referralAttributionService->validateLockedLine($lineInput, $product, $buyer)
            : null;
        $referral = $referralResult ? $this->referralAttributionService->publicData($referralResult) : null;
        if ($referral !== null) {
            unset($referral['attribution_token']);
        }

        if (($referralResult['status'] ?? null) === 'verified') {
            $receipt = $referralResult['receipt'];
            $allocation = $this->calculateRecommendationAllocation(
                product: $product,
                variant: $variant,
                recommender: $receipt->recommender,
                buyer: $buyer
            );
            $allocation['terms_snapshot']['referral_attribution'] = [
                'id' => $receipt->id,
                'user_pick_id' => $receipt->user_pick_id,
                'product_id' => $receipt->product_id,
                'recommender_id' => $receipt->recommender_id,
                'buyer_user_id' => $receipt->buyer_user_id,
                'visited_at' => $receipt->visited_at->toIso8601String(),
                'expires_at' => $receipt->expires_at->toIso8601String(),
                'locked_at' => $receipt->locked_at->toIso8601String(),
                'policy_version' => $receipt->policy_version,
            ];

            return array_merge($allocation, [
                'community_shop_id' => null,
                'shop_listing_id' => null,
                'recommender_id' => $receipt->recommender_id,
                'recommendation_code' => $receipt->pick->recommendation_code,
                'beneficiary_user_id' => $receipt->recommender_id,
                'referral_status' => 'verified',
                'referral_reason' => null,
                'referral' => $referral,
            ]);
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
            'referral_status' => $referralResult['status'] ?? 'none',
            'referral_reason' => $referralResult['reason'] ?? null,
            'referral' => $referral,
            'terms_snapshot' => [
                'policy_version' => '1.0',
                'channel' => 'direct_catalog',
                'calculated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /** Reject cross-product sources before using their price or beneficiary. */
    private function validateLineSourceIdentity(array $line, Product $product, ?ProductVariant $variant): void
    {
        if ($variant && (int) $variant->product_id !== (int) $product->id) {
            throw ValidationException::withMessages(['items' => ['The selected variant does not belong to this product.']]);
        }

        $isGroupBuy = ($line['purchase_mode'] ?? null) === 'group_buy';
        $hasCampaign = ! empty($line['campaign_id']);
        if ($isGroupBuy !== $hasCampaign) {
            throw ValidationException::withMessages(['items' => ['A drop campaign must be purchased using group buy mode.']]);
        }

        if (! empty($line['campaign_id'])) {
            $campaign = GroupBuyCampaign::find($line['campaign_id']);
            if (! $campaign || (int) $campaign->product_id !== (int) $product->id
                || ($campaign->product_variant_id && (int) $campaign->product_variant_id !== (int) ($variant?->id))) {
                throw ValidationException::withMessages(['items' => ['The selected drop campaign does not match this product and variant.']]);
            }
            if ($campaign->status !== 'active' || $campaign->isExpired()
                || ($campaign->start_at && $campaign->start_at->isFuture())) {
                throw ValidationException::withMessages(['items' => ['The selected drop campaign is not active.']]);
            }
        }

        if (! empty($line['shop_listing_id'])) {
            $listing = ShopListing::find($line['shop_listing_id']);
            if (! $listing || (int) $listing->product_id !== (int) $product->id
                || (! empty($line['community_shop_id']) && (int) $listing->community_shop_id !== (int) $line['community_shop_id'])) {
                throw ValidationException::withMessages(['items' => ['The selected shop listing does not match this product and shop.']]);
            }
        }
    }
}
