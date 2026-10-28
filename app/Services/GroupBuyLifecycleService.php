<?php

namespace App\Services;

use App\Models\GroupBuyCampaign;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupBuyLifecycleService
{
    public const POLICY_VERSION = 'conditional-order-target-v1';

    public function policy(): array
    {
        return [
            'version' => self::POLICY_VERSION,
            'qualification' => 'distinct_customers_with_accepted_conditional_orders_including_unpaid',
            'interest_counts_toward_target' => false,
            'checkout' => 'one_campaign_only_separate_from_normal_purchases',
            'success' => 'permanent_unlock_closed_to_new_orders',
            'accepted_price' => 'fixed_at_order_acceptance',
            'unsuccessful_end' => 'cancel_awaiting_orders_release_stock_manual_refund_review_if_paid',
        ];
    }

    public function validateCart(array $lines): void
    {
        $groups = collect($lines)->filter(fn ($line) => ($line['purchase_mode'] ?? null) === 'group_buy');
        if ($groups->isNotEmpty() && ($groups->count() !== count($lines) || $groups->pluck('campaign_id')->unique()->count() !== 1)) {
            throw ValidationException::withMessages(['items' => ['Check out one Price Drop at a time. Purchase ordinary products and other Price Drops in separate orders.']]);
        }
    }

    /** Only real, non-cancelled and non-refunded order reservations count. */
    public function qualifyingCount(GroupBuyCampaign $campaign): int
    {
        return $campaign->distinct_participants_count;
    }

    public function productIsPublic(?Product $product): bool
    {
        return $product && $product->status === 'published' && $product->visibility === 'public' && $product->is_active;
    }

    public function availability(GroupBuyCampaign $campaign): array
    {
        $campaign->loadMissing(['product', 'variant']);
        $reason = null;
        if (! $this->productIsPublic($campaign->product)) $reason = 'Product is unavailable.';
        elseif (! ($campaign->product->is_group_buy_enabled || $campaign->product->is_group_drop_enabled)) $reason = 'Price Drop ordering is disabled for this product.';
        elseif ($campaign->product_variant_id && (! $campaign->variant || ! $campaign->variant->is_active
            || (int) $campaign->variant->product_id !== (int) $campaign->product_id)) $reason = 'This Price Drop variant is unavailable.';
        elseif ($campaign->status === 'succeeded') $reason = 'This Price Drop reached its target and is closed to new orders.';
        elseif (! in_array($campaign->status, ['active', 'scheduled'], true)) $reason = 'This Price Drop is no longer accepting orders.';
        elseif ($campaign->isExpired()) $reason = 'This Price Drop has ended.';
        elseif ($campaign->status === 'scheduled' || ($campaign->start_at && $campaign->start_at->isFuture())) $reason = 'This Price Drop has not started yet.';
        elseif ($campaign->max_participants !== null && $this->qualifyingCount($campaign) >= $campaign->max_participants) $reason = 'This Price Drop has reached its customer limit.';

        return ['can_express_interest' => $reason === null, 'can_order' => $reason === null, 'unavailability_reason' => $reason];
    }

    public function assertOrderable(GroupBuyCampaign $campaign, Product $product, ?ProductVariant $variant): void
    {
        if ((int) $campaign->product_id !== (int) $product->id
            || ($campaign->product_variant_id && (int) $campaign->product_variant_id !== (int) $variant?->id)) {
            throw ValidationException::withMessages(['items' => ['The Price Drop does not match this product and variant.']]);
        }
        $availability = $this->availability($campaign);
        if (! $availability['can_order']) {
            throw ValidationException::withMessages(['items' => [$availability['unavailability_reason']]]);
        }
    }

    public function publicContext(GroupBuyCampaign $campaign): array
    {
        return [
            'id' => $campaign->id, 'campaign_code' => $campaign->campaign_code,
            'title' => $campaign->title, 'status' => $campaign->status,
            'group_price' => $campaign->group_price, 'target_participants' => $campaign->target_participants,
            'qualifying_participants_count' => $this->qualifyingCount($campaign),
            'max_participants' => $campaign->max_participants,
            'quantity_limit_per_customer' => $campaign->quantity_limit_per_customer,
            'start_at' => $campaign->start_at?->toIso8601String(), 'end_at' => $campaign->end_at?->toIso8601String(),
            'product_variant_id' => $campaign->product_variant_id,
            'lifecycle_policy' => $campaign->lifecycle_policy_snapshot ?: $this->policy(),
            'target_reached' => $campaign->status === 'succeeded',
            'terms_hash' => $this->termsHash($campaign),
            ...$this->availability($campaign),
        ];
    }

    public function termsHash(GroupBuyCampaign $campaign): string
    {
        $policy = $campaign->lifecycle_policy_snapshot ?: $this->policy();
        ksort($policy);
        return hash('sha256', json_encode([
            'id' => (int) $campaign->id, 'product_id' => (int) $campaign->product_id,
            'variant_id' => $campaign->product_variant_id ? (int) $campaign->product_variant_id : null,
            'group_price' => number_format((float) $campaign->group_price, 2, '.', ''),
            'target' => (int) $campaign->target_participants, 'max' => $campaign->max_participants === null ? null : (int) $campaign->max_participants,
            'limit' => (int) $campaign->quantity_limit_per_customer,
            'start' => $campaign->start_at?->toIso8601String(), 'end' => $campaign->end_at?->toIso8601String(),
            'policy' => $policy,
        ], JSON_THROW_ON_ERROR));
    }

    /** Caller holds the campaign lock before reserving stock or creating a line. */
    public function assertCustomerCapacity(GroupBuyCampaign $campaign, int $buyerId, int $quantity): void
    {
        $existing = (int) $campaign->activeParticipants()->where('user_id', $buyerId)->sum('quantity');
        if ($existing + $quantity > $campaign->quantity_limit_per_customer) {
            throw ValidationException::withMessages(['items' => ["This Price Drop allows at most {$campaign->quantity_limit_per_customer} units per customer."]]);
        }
        if (! $existing && $campaign->max_participants !== null && $this->qualifyingCount($campaign) >= $campaign->max_participants) {
            throw ValidationException::withMessages(['items' => ['This Price Drop has reached its customer limit.']]);
        }
    }

    public function freezePolicy(GroupBuyCampaign $campaign): void
    {
        if (! $campaign->lifecycle_policy_snapshot) $campaign->update(['lifecycle_policy_snapshot' => $this->policy()]);
    }

    /** Called after a reservation is accepted, within its campaign transaction. */
    public function inspectTarget(GroupBuyCampaign $campaign): bool
    {
        if ($campaign->status !== 'active' || $campaign->isExpired()
            || $this->qualifyingCount($campaign) < $campaign->target_participants) return false;
        $campaign->update(['status' => 'succeeded', 'success_at' => now()]);
        $campaign->activeParticipants()->where('status', 'reserved')->update(['status' => 'confirmed']);
        app(OrderStatusService::class)->handleGroupCampaignUnlocked($campaign);
        return true;
    }

    /** Scheduled command: each due campaign commits independently and can repeat safely. */
    public function processDueCampaign(int $id): string
    {
        return DB::transaction(function () use ($id) {
            $campaign = GroupBuyCampaign::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($campaign->status, ['scheduled', 'active'], true)) return $campaign->status;
            if ($campaign->isExpired()) {
                $campaign->update(['status' => 'failed', 'cancellation_reason' => 'Deadline passed before the target was reached.']);
                app(OrderStatusService::class)->handleGroupCampaignExpired($campaign);
                return 'failed';
            }
            if ($campaign->status === 'scheduled' && (! $campaign->start_at || $campaign->start_at->lessThanOrEqualTo(now()))) {
                $campaign->update(['status' => 'active']);
            }
            if ($campaign->status === 'active') $this->inspectTarget($campaign);
            return $campaign->status;
        }, 3);
    }

    public function cancelCampaign(int $id, string $reason): void
    {
        DB::transaction(function () use ($id, $reason) {
            $campaign = GroupBuyCampaign::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($campaign->status === 'cancelled') return;
            if (in_array($campaign->status, ['succeeded', 'failed'], true)) {
                throw ValidationException::withMessages(['campaign' => ['This Price Drop has already concluded. Manage any remaining order or refund individually.']]);
            }
            $campaign->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            app(OrderStatusService::class)->handleGroupCampaignExpired($campaign, "Price Drop cancelled: {$reason}");
        }, 3);
    }
}
