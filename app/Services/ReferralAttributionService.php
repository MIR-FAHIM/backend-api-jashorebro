<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ReferralAttribution;
use App\Models\User;
use App\Models\UserPick;
use Illuminate\Support\Facades\DB;

class ReferralAttributionService
{
    public const POLICY_VERSION = 'public-pick-last-before-cart-v1';
    public const WINDOW_DAYS = 7;

    /** Record a server-verified public Pick visit; a reused receipt never extends its window. */
    public function resolveVisit(array $input, ?User $buyer): array
    {
        return DB::transaction(function () use ($input, $buyer) {
            // Serialize visits and first cart locks for this product, including concurrent tabs.
            $product = Product::whereKey($input['product_id'])->lockForUpdate()->first();
            if (! $product) {
                return $this->ineligible('product_unavailable');
            }

            $pick = UserPick::with('user')
                ->where('product_id', $product->id)
                ->where('recommendation_code', $input['recommendation_code'])
                ->first();
            if ($reason = $this->pickIneligibility($pick, $product, $buyer)) {
                return $this->ineligible($reason);
            }

            $visitorHash = $this->hashSecret($input['visitor_token']);
            $rawToken = $input['attribution_token'] ?? null;
            $existing = $this->findReceipt($rawToken, $visitorHash, $product->id);
            $latestPickId = ReferralAttribution::where('visitor_token_hash', $visitorHash)
                ->where('product_id', $product->id)
                ->where(function ($query) use ($buyer) {
                    $query->whereNull('buyer_user_id');
                    if ($buyer) {
                        $query->orWhere('buyer_user_id', $buyer->id);
                    }
                })
                ->orderByDesc('visited_at')->orderByDesc('id')->value('user_pick_id');
            if ($existing && (int) $existing->user_pick_id === (int) $pick->id
                && (int) $latestPickId === (int) $pick->id
                && ! $this->receiptIneligibility($existing, $product, $buyer)) {
                return $this->verified($existing, $rawToken);
            }

            $rawToken = $this->newToken();
            $visitedAt = now();
            $receipt = ReferralAttribution::create([
                'token_hash' => $this->hashSecret($rawToken),
                'visitor_token_hash' => $visitorHash,
                'product_id' => $product->id,
                'user_pick_id' => $pick->id,
                'recommender_id' => $pick->user_id,
                'buyer_user_id' => $buyer?->id,
                'visited_at' => $visitedAt,
                'expires_at' => $visitedAt->copy()->addDays(self::WINDOW_DAYS),
                'policy_version' => self::POLICY_VERSION,
            ]);

            return $this->verified($receipt, $rawToken);
        });
    }

    /** Freeze the latest eligible visit at the cart event, preserving previously locked lines. */
    public function lockVisit(array $input, ?User $buyer): array
    {
        return DB::transaction(function () use ($input, $buyer) {
            $product = Product::whereKey($input['product_id'])->lockForUpdate()->first();
            if (! $product) {
                return $this->ineligible('product_unavailable');
            }

            $visitorHash = $this->hashSecret($input['visitor_token']);
            $rawToken = $input['attribution_token'];
            $candidate = $this->findReceipt($rawToken, $visitorHash, $product->id);
            if (! $candidate) {
                return $this->ineligible('invalid_attribution');
            }
            if (! $this->buyerMatches($candidate, $buyer)) {
                return $this->ineligible('buyer_mismatch');
            }

            if ($candidate->locked_at) {
                if ($reason = $this->receiptIneligibility($candidate, $product, $buyer)) {
                    return $this->ineligible($reason);
                }
                $this->bindBuyer($candidate, $buyer);

                return $this->verified($candidate, $rawToken);
            }

            // A client cannot select an older recommender by supplying an earlier visit token.
            $visits = ReferralAttribution::with(['pick.user', 'recommender'])
                ->where('visitor_token_hash', $visitorHash)
                ->where('product_id', $product->id)
                ->where('policy_version', self::POLICY_VERSION)
                ->where('expires_at', '>', now())
                ->where(function ($query) use ($buyer) {
                    $query->whereNull('buyer_user_id');
                    if ($buyer) {
                        $query->orWhere('buyer_user_id', $buyer->id);
                    }
                })
                ->orderByDesc('visited_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            $latest = $visits->first(fn (ReferralAttribution $visit) => ! $this->receiptIneligibility($visit, $product, $buyer));
            if (! $latest) {
                return $this->ineligible($this->receiptIneligibility($candidate, $product, $buyer) ?? 'attribution_unavailable');
            }

            if ($latest->id !== $candidate->id) {
                // Hash-only storage cannot recover the latest token. Mint a locked receipt
                // with the same visit clock instead of rotating and breaking another cart.
                $rawToken = $this->newToken();
                $latest = ReferralAttribution::create([
                    'token_hash' => $this->hashSecret($rawToken),
                    'visitor_token_hash' => $visitorHash,
                    'product_id' => $latest->product_id,
                    'user_pick_id' => $latest->user_pick_id,
                    'recommender_id' => $latest->recommender_id,
                    'buyer_user_id' => $buyer?->id ?? $latest->buyer_user_id,
                    'visited_at' => $latest->visited_at,
                    'expires_at' => $latest->expires_at,
                    'locked_at' => now(),
                    'policy_version' => $latest->policy_version,
                ]);
            } else {
                $latest->locked_at = now();
                $this->bindBuyer($latest, $buyer);
                $latest->save();
            }

            return $this->verified($latest, $rawToken);
        });
    }

    /** Revalidate a frozen cart source and atomically claim anonymous receipts for one buyer. */
    public function validateLockedLine(array $line, Product $product, ?User $buyer): array
    {
        $rawToken = $line['attribution_token'] ?? null;
        $visitorToken = $line['attribution_visitor_token'] ?? null;
        if (! $rawToken && ! $visitorToken) {
            return $this->ineligible('unverified_source');
        }
        if (! $this->isSecret($rawToken) || ! $this->isSecret($visitorToken)) {
            return $this->ineligible('invalid_attribution');
        }

        return DB::transaction(function () use ($rawToken, $visitorToken, $product, $buyer) {
            $receipt = $this->findReceipt($rawToken, $this->hashSecret($visitorToken), $product->id);
            if (! $receipt) {
                return $this->ineligible('invalid_attribution');
            }
            if (! $receipt->locked_at) {
                return $this->ineligible('attribution_not_locked');
            }
            if ($reason = $this->receiptIneligibility($receipt, $product, $buyer)) {
                return $this->ineligible($reason);
            }

            $this->bindBuyer($receipt, $buyer);

            return $this->verified($receipt, null);
        });
    }

    /** Public API/quote metadata contains no hashes or visitor secret. */
    public function publicData(array $result): array
    {
        $receipt = $result['receipt'] ?? null;
        $recommender = $receipt?->recommender;

        return [
            'status' => $result['status'],
            'reason' => $result['reason'],
            'attribution_token' => $result['attribution_token'] ?? null,
            'product_id' => $receipt?->product_id,
            'recommendation_code' => $receipt?->pick?->recommendation_code,
            'recommender_id' => $receipt?->recommender_id,
            'recommender' => $recommender ? [
                'id' => $recommender->id,
                'name' => $recommender->name,
                'username' => $recommender->username,
            ] : null,
            'visited_at' => $receipt?->visited_at?->toIso8601String(),
            'expires_at' => $receipt?->expires_at?->toIso8601String(),
            'locked_at' => $receipt?->locked_at?->toIso8601String(),
            'policy_version' => $receipt?->policy_version ?? self::POLICY_VERSION,
        ];
    }

    private function pickIneligibility(?UserPick $pick, Product $product, ?User $buyer): ?string
    {
        if ($product->status !== 'published' || $product->visibility !== 'public' || ! $product->is_active) {
            return 'product_unavailable';
        }
        if (! $product->is_recommendation_enabled) {
            return 'recommendation_disabled';
        }
        if (! $pick || ! $pick->is_public || ! $pick->user || (int) $pick->product_id !== (int) $product->id) {
            return 'pick_unavailable';
        }
        if ($pick->user->status !== 'active') {
            return 'recommender_unavailable';
        }
        if ($buyer && (int) $buyer->id === (int) $pick->user_id) {
            return 'self_referral';
        }
        if ($buyer && $buyer->relationshipStatusWith($pick->user) === 'blocked') {
            return 'blocked';
        }

        return null;
    }

    private function receiptIneligibility(ReferralAttribution $receipt, Product $product, ?User $buyer): ?string
    {
        if ((int) $receipt->product_id !== (int) $product->id || $receipt->policy_version !== self::POLICY_VERSION) {
            return 'invalid_attribution';
        }
        if (! $this->buyerMatches($receipt, $buyer)) {
            return 'buyer_mismatch';
        }
        if (! $receipt->expires_at || $receipt->expires_at->lessThanOrEqualTo(now())) {
            return 'attribution_expired';
        }
        if (! $receipt->visited_at || $receipt->visited_at->isFuture()) {
            return 'invalid_attribution';
        }
        $receipt->loadMissing(['pick.user', 'recommender']);
        if (! $receipt->pick || (int) $receipt->pick->user_id !== (int) $receipt->recommender_id || ! $receipt->recommender) {
            return 'pick_unavailable';
        }

        return $this->pickIneligibility($receipt->pick, $product, $buyer);
    }

    private function buyerMatches(ReferralAttribution $receipt, ?User $buyer): bool
    {
        return $receipt->buyer_user_id === null
            || ($buyer && (int) $receipt->buyer_user_id === (int) $buyer->id);
    }

    private function bindBuyer(ReferralAttribution $receipt, ?User $buyer): void
    {
        if ($buyer && $receipt->buyer_user_id === null) {
            $receipt->buyer_user_id = $buyer->id;
            $receipt->save();
        }
    }

    private function findReceipt(?string $rawToken, string $visitorHash, int $productId): ?ReferralAttribution
    {
        if (! $this->isSecret($rawToken)) {
            return null;
        }

        return ReferralAttribution::where('token_hash', $this->hashSecret($rawToken))
            ->where('visitor_token_hash', $visitorHash)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();
    }

    private function isSecret(mixed $secret): bool
    {
        return is_string($secret) && preg_match('/\A[a-f0-9]{64}\z/', $secret) === 1;
    }

    private function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function verified(ReferralAttribution $receipt, ?string $rawToken): array
    {
        return ['status' => 'verified', 'reason' => null, 'receipt' => $receipt, 'attribution_token' => $rawToken];
    }

    private function ineligible(string $reason): array
    {
        return ['status' => 'ineligible', 'reason' => $reason, 'receipt' => null, 'attribution_token' => null];
    }
}
