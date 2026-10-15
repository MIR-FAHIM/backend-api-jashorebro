<?php

namespace App\Services;

use App\Models\CommentReaction;
use App\Models\CommunityShop;
use App\Models\FeedFeedback;
use App\Models\FeedImpression;
use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Models\Product;
use App\Models\ProductComment;
use App\Models\User;
use App\Models\UserPick;
use App\Support\MediaUrl;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CommunityFeedService
{
    /**
     * Get unified public feed items.
     *
     * @param User|null $viewer
     * @param array $params
     * @return array
     */
    public function getFeed(?User $viewer, array $params = []): array
    {
        $tab = strtolower($params['tab'] ?? $params['filter'] ?? 'for_you');
        if ($tab === 'for you' || $tab === 'foryou') {
            $tab = 'for_you';
        } elseif ($tab === 'following') {
            $tab = 'following';
        } elseif ($tab === 'trending') {
            $tab = 'trending';
        } else {
            $tab = 'for_you';
        }

        $categorySlug = $params['category'] ?? null;
        if ($categorySlug === 'all') {
            $categorySlug = null;
        }

        $friendsOnly = filter_var($params['friends_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $search = ! empty($params['q']) ? trim($params['q']) : null;
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($params['per_page'] ?? 15)));

        // Auth requirement for Following tab
        if ($tab === 'following' && ! $viewer) {
            return [
                'success' => true,
                'data' => [],
                'requires_auth' => true,
                'message' => 'Sign in to see recommendations and discussions from curators you follow.',
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'has_more' => false,
                ],
            ];
        }

        // Auth requirement for Friends Only filter
        if ($friendsOnly && ! $viewer) {
            return [
                'success' => true,
                'data' => [],
                'requires_auth' => true,
                'message' => 'Sign in to view recommendations from your confirmed friends.',
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'has_more' => false,
                ],
            ];
        }

        // Fetch viewer social graph
        $friendIds = $viewer ? $viewer->friendIds() : [];
        $followedIds = $viewer ? $viewer->following()->pluck('followed_user_id')->toArray() : [];

        if ($tab === 'following' && empty($followedIds)) {
            return [
                'success' => true,
                'data' => [],
                'message' => 'You are not following any community curators yet. Explore the For You feed to discover curators.',
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'has_more' => false,
                ],
            ];
        }

        if ($friendsOnly && empty($friendIds)) {
            return [
                'success' => true,
                'data' => [],
                'message' => 'You have no confirmed friends yet. Connect with people from the Friends page to see their picks.',
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'has_more' => false,
                ],
            ];
        }

        // Fetch user feedback / suppressions
        $hiddenFeedItemIds = [];
        $mutedAuthorIds = [];
        $lessLikeItemIds = [];
        if ($viewer) {
            $feedbacks = FeedFeedback::where('user_id', $viewer->id)->get();
            foreach ($feedbacks as $fb) {
                if ($fb->action === FeedFeedback::ACTION_HIDE && $fb->feed_item_id) {
                    $hiddenFeedItemIds[] = $fb->feed_item_id;
                } elseif ($fb->action === FeedFeedback::ACTION_MUTE_AUTHOR && $fb->target_author_id) {
                    $mutedAuthorIds[] = $fb->target_author_id;
                } elseif ($fb->action === FeedFeedback::ACTION_LESS_LIKE_THIS && $fb->feed_item_id) {
                    $lessLikeItemIds[] = $fb->feed_item_id;
                }
            }
        }

        // Gather candidates across the 5 domains
        $candidates = $this->collectCandidates(
            viewer: $viewer,
            tab: $tab,
            categorySlug: $categorySlug,
            search: $search,
            friendsOnly: $friendsOnly,
            friendIds: $friendIds,
            followedIds: $followedIds,
            mutedAuthorIds: $mutedAuthorIds,
            hiddenFeedItemIds: $hiddenFeedItemIds,
            lessLikeItemIds: $lessLikeItemIds
        );

        // Score candidates based on active tab and social affinity
        $scoredCandidates = $this->scoreCandidates(
            candidates: $candidates,
            tab: $tab,
            viewer: $viewer,
            friendIds: $friendIds,
            followedIds: $followedIds,
            lessLikeItemIds: $lessLikeItemIds
        );

        // Apply diversity and repetition constraints
        $arrangedCandidates = $this->arrangeWithRepetitionRules($scoredCandidates, $tab);

        // Paginate slice
        $total = count($arrangedCandidates);
        $offset = ($page - 1) * $perPage;
        $pageSlice = array_slice($arrangedCandidates, $offset, $perPage);
        $hasMore = ($offset + $perPage) < $total;

        // Transform into standardized public feed cards
        $items = $this->formatFeedItems($pageSlice, $viewer, $friendIds, $followedIds);

        return [
            'success' => true,
            'tab' => $tab,
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => $hasMore,
                'ranking_version' => 'v1.0',
            ],
        ];
    }

    /**
     * Collect candidate models across domains.
     */
    protected function collectCandidates(
        ?User $viewer,
        string $tab,
        ?string $categorySlug,
        ?string $search,
        bool $friendsOnly,
        array $friendIds,
        array $followedIds,
        array $mutedAuthorIds,
        array $hiddenFeedItemIds,
        array $lessLikeItemIds
    ): array {
        $candidates = [];

        // 1. RECOMMENDATIONS (UserPick)
        $pickQuery = UserPick::with([
            'user.profile',
            'product.primaryImage',
            'product.category',
            'product.seller',
            'product.activeVariants',
        ])
            ->where('is_public', true)
            ->whereHas('product', fn ($q) => $q->where('status', 'published')->where('is_active', true))
            ->whereHas('user', fn ($q) => $q->where('status', 'active'));

        if ($categorySlug) {
            $pickQuery->whereHas('product.category', fn ($q) => $q->where('slug', $categorySlug));
        }
        if ($search) {
            $pickQuery->where(function ($q) use ($search) {
                $q->where('caption', 'like', "%{$search}%")
                  ->orWhereHas('product', fn ($pq) => $pq->where('title', 'like', "%{$search}%"));
            });
        }
        if ($friendsOnly) {
            $pickQuery->whereIn('user_id', $friendIds);
        } elseif ($tab === 'following') {
            $pickQuery->whereIn('user_id', $followedIds);
        }
        if (! empty($mutedAuthorIds)) {
            $pickQuery->whereNotIn('user_id', $mutedAuthorIds);
        }

        $picks = $pickQuery->orderBy('id', 'desc')->limit(40)->get();
        foreach ($picks as $pick) {
            $feedItemId = "rec_{$pick->id}";
            if (in_array($feedItemId, $hiddenFeedItemIds, true)) {
                continue;
            }
            $candidates[] = [
                'feed_item_id' => $feedItemId,
                'type' => 'recommendation',
                'source_id' => $pick->id,
                'published_at' => $pick->created_at ? $pick->created_at->toIso8601String() : now()->toIso8601String(),
                'author_id' => $pick->user_id,
                'product_id' => $pick->product_id,
                'raw_model' => $pick,
            ];
        }

        // 2. DISCUSSIONS (ProductComment)
        $commentQuery = ProductComment::with([
            'user.profile',
            'product.primaryImage',
            'product.category',
            'reactions',
        ])
            ->whereNull('parent_id')
            ->where('status', ProductComment::STATUS_PUBLISHED)
            ->whereHas('product', fn ($q) => $q->where('status', 'published')->where('is_active', true))
            ->whereHas('user', fn ($q) => $q->where('status', 'active'));

        if ($categorySlug) {
            $commentQuery->whereHas('product.category', fn ($q) => $q->where('slug', $categorySlug));
        }
        if ($search) {
            $commentQuery->where(function ($q) use ($search) {
                $q->where('content', 'like', "%{$search}%")
                  ->orWhereHas('product', fn ($pq) => $pq->where('title', 'like', "%{$search}%"));
            });
        }
        if ($friendsOnly) {
            $commentQuery->whereIn('user_id', $friendIds);
        } elseif ($tab === 'following') {
            $commentQuery->whereIn('user_id', $followedIds);
        }
        if (! empty($mutedAuthorIds)) {
            $commentQuery->whereNotIn('user_id', $mutedAuthorIds);
        }

        $comments = $commentQuery->orderBy('id', 'desc')->limit(40)->get();
        foreach ($comments as $comment) {
            $feedItemId = "disc_{$comment->id}";
            if (in_array($feedItemId, $hiddenFeedItemIds, true)) {
                continue;
            }
            $candidates[] = [
                'feed_item_id' => $feedItemId,
                'type' => 'discussion',
                'source_id' => $comment->id,
                'published_at' => $comment->created_at ? $comment->created_at->toIso8601String() : now()->toIso8601String(),
                'author_id' => $comment->user_id,
                'product_id' => $comment->product_id,
                'raw_model' => $comment,
            ];
        }

        // 3. DROPS (GroupBuyCampaign)
        $dropQuery = GroupBuyCampaign::with([
            'product.primaryImage',
            'product.category',
            'product.seller',
            'product.specifications.attribute',
            'product.specifications.attributeItem',
            'variant',
            'organizer.profile',
            'participants',
        ])
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('end_at')->orWhere('end_at', '>', now());
            })
            ->whereHas('product', fn ($q) => $q->where('status', 'published')->where('is_active', true));

        if ($categorySlug) {
            $dropQuery->whereHas('product.category', fn ($q) => $q->where('slug', $categorySlug));
        }
        if ($search) {
            $dropQuery->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhereHas('product', fn ($pq) => $pq->where('title', 'like', "%{$search}%"));
            });
        }
        if ($friendsOnly) {
            $dropQuery->whereIn('organizer_user_id', $friendIds);
        } elseif ($tab === 'following') {
            $dropQuery->whereIn('organizer_user_id', $followedIds);
        }
        if (! empty($mutedAuthorIds)) {
            $dropQuery->whereNotIn('organizer_user_id', $mutedAuthorIds);
        }

        $drops = $dropQuery->orderBy('id', 'desc')->limit(30)->get();
        foreach ($drops as $drop) {
            $feedItemId = "drop_{$drop->id}";
            if (in_array($feedItemId, $hiddenFeedItemIds, true)) {
                continue;
            }
            $candidates[] = [
                'feed_item_id' => $feedItemId,
                'type' => 'drop',
                'source_id' => $drop->id,
                'published_at' => $drop->start_at ? $drop->start_at->toIso8601String() : $drop->created_at->toIso8601String(),
                'author_id' => $drop->organizer_user_id,
                'product_id' => $drop->product_id,
                'raw_model' => $drop,
            ];
        }

        // 4. SHOPS (CommunityShop)
        $shopQuery = CommunityShop::with([
            'user.profile',
            'activeListings.product.primaryImage',
            'activeListings.product.category',
        ])
            ->whereIn('status', ['active', 'verified'])
            ->whereHas('activeListings')
            ->whereHas('user', fn ($q) => $q->where('status', 'active'));

        if ($categorySlug) {
            $shopQuery->whereHas('activeListings.product.category', fn ($q) => $q->where('slug', $categorySlug));
        }
        if ($search) {
            $shopQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }
        if ($friendsOnly) {
            $shopQuery->whereIn('user_id', $friendIds);
        } elseif ($tab === 'following') {
            $shopQuery->whereIn('user_id', $followedIds);
        }
        if (! empty($mutedAuthorIds)) {
            $shopQuery->whereNotIn('user_id', $mutedAuthorIds);
        }

        $shops = $shopQuery->orderBy('id', 'desc')->limit(15)->get();
        foreach ($shops as $shop) {
            $feedItemId = "shop_{$shop->id}";
            if (in_array($feedItemId, $hiddenFeedItemIds, true)) {
                continue;
            }
            $candidates[] = [
                'feed_item_id' => $feedItemId,
                'type' => 'shop',
                'source_id' => $shop->id,
                'published_at' => $shop->created_at ? $shop->created_at->toIso8601String() : now()->toIso8601String(),
                'author_id' => $shop->user_id,
                'product_id' => null,
                'raw_model' => $shop,
            ];
        }

        // 5. CATALOG FINDS (Product) - only for public/discovery tabs, not following or friends-only
        if (! $friendsOnly && $tab !== 'following') {
            $productQuery = Product::with([
                'primaryImage',
                'category',
                'seller',
                'activeVariants',
            ])
                ->where('status', 'published')
                ->where('is_active', true);

            if ($categorySlug) {
                $productQuery->whereHas('category', fn ($q) => $q->where('slug', $categorySlug));
            }
            if ($search) {
                $productQuery->where('title', 'like', "%{$search}%");
            }

            // Exclude products that already have direct drop or recommendation candidates
            $existingProductIds = array_filter(array_column($candidates, 'product_id'));
            if (! empty($existingProductIds)) {
                $productQuery->whereNotIn('id', array_unique($existingProductIds));
            }

            $products = $productQuery->orderBy('id', 'desc')->limit(20)->get();
            foreach ($products as $prod) {
                $feedItemId = "prod_{$prod->id}";
                if (in_array($feedItemId, $hiddenFeedItemIds, true)) {
                    continue;
                }
                $candidates[] = [
                    'feed_item_id' => $feedItemId,
                    'type' => 'catalog',
                    'source_id' => $prod->id,
                    'published_at' => $prod->created_at ? $prod->created_at->toIso8601String() : now()->toIso8601String(),
                    'author_id' => null,
                    'product_id' => $prod->id,
                    'raw_model' => $prod,
                ];
            }
        }

        return $candidates;
    }

    /**
     * Score each candidate according to tab, recency, social affinity, and engagement.
     */
    protected function scoreCandidates(
        array $candidates,
        string $tab,
        ?User $viewer,
        array $friendIds,
        array $followedIds,
        array $lessLikeItemIds
    ): array {
        foreach ($candidates as &$c) {
            $publishedAt = Carbon::parse($c['published_at']);
            $hoursAgo = max(0.1, $publishedAt->diffInHours(now()));

            if ($tab === 'following') {
                // Strict chronological recency for Following tab
                $c['score'] = $publishedAt->getTimestamp();
                continue;
            }

            // Baseline recency score with decay
            $recencyScore = max(5.0, 100.0 - ($hoursAgo * 1.5));

            // Social graph boost
            $socialBoost = 0;
            $authorId = $c['author_id'];
            if ($viewer && $authorId) {
                if (in_array($authorId, $friendIds, true)) {
                    $socialBoost += 40;
                } elseif (in_array($authorId, $followedIds, true)) {
                    $socialBoost += 25;
                }
            }

            // Engagement / momentum score
            $engagementBoost = 0;
            $type = $c['type'];
            $model = $c['raw_model'];

            if ($type === 'discussion') {
                $reactionsCount = (int) ($model->reactions_count ?? 0);
                $repliesCount = (int) ($model->replies_count ?? 0);
                $helpfulCount = (int) ($model->helpful_count ?? 0);
                $isVerified = (bool) ($model->is_verified_purchase ?? false);

                $engagementBoost += ($helpfulCount * 4) + ($reactionsCount * 2) + ($repliesCount * 3);
                if ($isVerified) {
                    $engagementBoost += 15;
                }
            } elseif ($type === 'drop') {
                $distinctQualifying = $model->participants()
                    ->whereIn('status', ['reserved', 'confirmed'])
                    ->whereNotNull('order_id')
                    ->distinct('user_id')
                    ->count('user_id');
                $target = (int) $model->target_participants;
                $pct = $target > 0 ? ($distinctQualifying / $target) * 100 : 0;

                // High interest drop nearing quota
                if ($pct >= 50 && $pct < 100) {
                    $engagementBoost += 30;
                } elseif ($pct > 0) {
                    $engagementBoost += 15;
                }
            } elseif ($type === 'recommendation') {
                if ($model->is_featured) {
                    $engagementBoost += 20;
                }
            } elseif ($type === 'shop') {
                if ($model->is_verified) {
                    $engagementBoost += 15;
                }
            }

            // Trending velocity formula in Trending tab
            if ($tab === 'trending') {
                $c['score'] = ($engagementBoost * 3.0) + max(0, 50 - ($hoursAgo * 0.5));
            } else {
                // For You blended score
                $lessLikePenalty = in_array($c['feed_item_id'], $lessLikeItemIds, true) ? -50 : 0;
                $c['score'] = $recencyScore + $socialBoost + $engagementBoost + $lessLikePenalty;
            }
        }
        unset($c);

        // Sort descending by score
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $candidates;
    }

    /**
     * Interleave candidates to satisfy author spacing, product deduplication, and shop limits.
     */
    protected function arrangeWithRepetitionRules(array $candidates, string $tab): array
    {
        if ($tab === 'following') {
            return $candidates;
        }

        $arranged = [];
        $deferred = [];
        $lastAuthorId = null;
        $recentProductIds = []; // keeps last 2 product ids
        $shopCountSinceLast = 0;

        foreach ($candidates as $c) {
            $authorId = $c['author_id'];
            $productId = $c['product_id'];
            $isShop = $c['type'] === 'shop';

            $authorConflict = $authorId !== null && $authorId === $lastAuthorId;
            $productConflict = $productId !== null && in_array($productId, $recentProductIds, true);
            $shopTooFrequent = $isShop && $shopCountSinceLast < 4;

            if ($authorConflict || $productConflict || $shopTooFrequent) {
                $deferred[] = $c;
                continue;
            }

            // Place item
            $arranged[] = $c;
            $lastAuthorId = $authorId;
            if ($productId !== null) {
                $recentProductIds[] = $productId;
                if (count($recentProductIds) > 2) {
                    array_shift($recentProductIds);
                }
            }
            if ($isShop) {
                $shopCountSinceLast = 0;
            } else {
                $shopCountSinceLast++;
            }
        }

        // Append deferred candidates
        foreach ($deferred as $def) {
            $arranged[] = $def;
        }

        return $arranged;
    }

    /**
     * Bulk format feed cards with social proof, viewer states, and attribution.
     */
    protected function formatFeedItems(
        array $pageCandidates,
        ?User $viewer,
        array $friendIds,
        array $followedIds
    ): array {
        if (empty($pageCandidates)) {
            return [];
        }

        $productIds = array_filter(array_column($pageCandidates, 'product_id'));
        $commentIds = [];
        $campaignIds = [];

        foreach ($pageCandidates as $c) {
            if ($c['type'] === 'discussion') {
                $commentIds[] = $c['source_id'];
            } elseif ($c['type'] === 'drop') {
                $campaignIds[] = $c['source_id'];
            }
        }

        // Friends who picked indexed by product_id
        $friendsWhoPickedMap = [];
        if (! empty($productIds) && ! empty($friendIds)) {
            $picks = UserPick::with(['user.profile'])
                ->whereIn('product_id', array_unique($productIds))
                ->where('is_public', true)
                ->whereIn('user_id', $friendIds)
                ->get()
                ->groupBy('product_id');

            foreach ($picks as $pId => $group) {
                $friendsWhoPickedMap[$pId] = $group->map(fn ($p) => [
                    'id' => $p->user->id,
                    'name' => $p->user->name,
                    'username' => $p->user->username,
                    'avatar_url' => $p->user->avatar_url,
                ])->values()->all();
            }
        }

        // Viewer reactions for discussions
        $viewerCommentReactions = [];
        if ($viewer && ! empty($commentIds)) {
            $viewerCommentReactions = CommentReaction::where('user_id', $viewer->id)
                ->whereIn('comment_id', array_unique($commentIds))
                ->pluck('reaction_type', 'comment_id')
                ->all();
        }

        // Viewer drop participation
        $viewerDropStates = [];
        if ($viewer && ! empty($campaignIds)) {
            $participants = GroupBuyParticipant::where('user_id', $viewer->id)
                ->whereIn('campaign_id', array_unique($campaignIds))
                ->whereIn('status', ['interested', 'reserved', 'confirmed'])
                ->get()
                ->groupBy('campaign_id');

            foreach ($participants as $campId => $recs) {
                $viewerDropStates[$campId] = [
                    'has_expressed_interest' => $recs->contains(fn ($r) => $r->status === 'interested'),
                    'has_order_reservation' => $recs->contains(fn ($r) => $r->status === 'reserved' && $r->order_id !== null),
                    'has_confirmed_participation' => $recs->contains(fn ($r) => $r->status === 'confirmed'),
                ];
            }
        }

        // Viewer saved product picks
        $viewerSavedProducts = [];
        if ($viewer && ! empty($productIds)) {
            $viewerSavedProducts = UserPick::where('user_id', $viewer->id)
                ->whereIn('product_id', array_unique($productIds))
                ->pluck('id', 'product_id')
                ->all();
        }

        $formatted = [];
        foreach ($pageCandidates as $c) {
            $type = $c['type'];
            $model = $c['raw_model'];
            $feedItemId = $c['feed_item_id'];
            $authorId = $c['author_id'];
            $productId = $c['product_id'];

            $isFriend = $authorId && in_array($authorId, $friendIds, true);
            $isFollowing = $authorId && in_array($authorId, $followedIds, true);

            switch ($type) {
                case 'recommendation':
                    $formatted[] = $this->formatRecommendationCard(
                        pick: $model,
                        feedItemId: $feedItemId,
                        isFriend: $isFriend,
                        isFollowing: $isFollowing,
                        friendsWhoPicked: $friendsWhoPickedMap[$productId] ?? [],
                        isSavedByViewer: isset($viewerSavedProducts[$productId])
                    );
                    break;

                case 'discussion':
                    $formatted[] = $this->formatDiscussionCard(
                        comment: $model,
                        feedItemId: $feedItemId,
                        isFriend: $isFriend,
                        isFollowing: $isFollowing,
                        viewerReaction: $viewerCommentReactions[$model->id] ?? null,
                        isSavedByViewer: isset($viewerSavedProducts[$productId])
                    );
                    break;

                case 'drop':
                    $formatted[] = $this->formatDropCard(
                        campaign: $model,
                        feedItemId: $feedItemId,
                        isFriend: $isFriend,
                        isFollowing: $isFollowing,
                        viewerState: $viewerDropStates[$model->id] ?? null
                    );
                    break;

                case 'shop':
                    $formatted[] = $this->formatShopCard(
                        shop: $model,
                        feedItemId: $feedItemId,
                        isFriend: $isFriend,
                        isFollowing: $isFollowing
                    );
                    break;

                case 'catalog':
                    $formatted[] = $this->formatCatalogCard(
                        product: $model,
                        feedItemId: $feedItemId,
                        friendsWhoPicked: $friendsWhoPickedMap[$productId] ?? [],
                        isSavedByViewer: isset($viewerSavedProducts[$productId])
                    );
                    break;
            }
        }

        return $formatted;
    }

    /**
     * Format Recommendation card payload.
     */
    protected function formatRecommendationCard(
        UserPick $pick,
        string $feedItemId,
        bool $isFriend,
        bool $isFollowing,
        array $friendsWhoPicked,
        bool $isSavedByViewer
    ): array {
        $product = $pick->product;
        $user = $pick->user;

        $reason = $isFriend
            ? '👥 Friend recommendation'
            : ($isFollowing ? 'Curator you follow' : 'Community Recommendation');

        $price = (float) ($product->base_price ?? 0);
        $originalPrice = $product->original_price ? (float) $product->original_price : null;

        return [
            'feed_item_id' => $feedItemId,
            'type' => 'recommendation',
            'source_id' => $pick->id,
            'published_at' => $pick->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'reason' => $reason,
            'actor' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username ?? 'user',
                'avatar_url' => $user->avatar_url,
                'locality' => $user->profile?->locality ?? 'Jashore',
                'is_friend' => $isFriend,
                'is_following' => $isFollowing,
                'is_verified' => false,
            ],
            'caption' => $pick->caption ?? "Recommended by {$user->name}",
            'content_excerpt' => $pick->caption,
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'slug' => $product->slug,
                'image' => $product->primaryImage?->thumbnail_url ?? $product->primaryImage?->image_url ?? $product->image,
                'base_price' => $price,
                'original_price' => $originalPrice,
                'category' => $product->category?->name ?? 'Artisan Goods',
                'category_slug' => $product->category?->slug,
                'has_variants' => $product->activeVariants?->isNotEmpty() ?? false,
                'seller_name' => $product->seller?->store_name ?? 'Local Merchant',
            ],
            'social_context' => [
                'friends_who_picked' => array_slice($friendsWhoPicked, 0, 3),
                'friends_who_picked_count' => count($friendsWhoPicked),
                'is_featured' => (bool) $pick->is_featured,
            ],
            'viewer_state' => [
                'is_saved' => $isSavedByViewer,
            ],
            'commerce' => [
                'recommendation_code' => $pick->recommendation_code,
                'price' => $price,
                'original_price' => $originalPrice,
                'in_stock' => $product->stock_quantity > 0,
            ],
            'allowed_actions' => ['view', 'save', 'share', 'hide', 'less_like_this', 'mute_author', 'report'],

            // Legacy backward-compatibility fields
            'id' => $pick->id,
            'title' => $product->title,
            'image' => $product->primaryImage?->thumbnail_url ?? $product->image,
            'retailPrice' => $price,
            'retail_price' => $price,
        ];
    }

    /**
     * Format Discussion card payload.
     */
    protected function formatDiscussionCard(
        ProductComment $comment,
        string $feedItemId,
        bool $isFriend,
        bool $isFollowing,
        ?string $viewerReaction,
        bool $isSavedByViewer
    ): array {
        $product = $comment->product;
        $user = $comment->user;

        $intentLabel = match ($comment->comment_type) {
            ProductComment::TYPE_QUESTION => '❓ Question',
            ProductComment::TYPE_EXPERIENCE => 'Verified Experience',
            ProductComment::TYPE_DEAL => '🏷️ Deal Alert',
            ProductComment::TYPE_ALTERNATIVE => '💡 Alternative',
            default => 'Product Discussion',
        };

        $reason = $isFriend
            ? '👥 Friend discussion'
            : ($comment->is_verified_purchase ? 'Verified Buyer Experience' : 'Community Discussion');

        $price = (float) ($product->base_price ?? 0);

        return [
            'feed_item_id' => $feedItemId,
            'type' => 'discussion',
            'source_id' => $comment->id,
            'published_at' => $comment->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'reason' => $reason,
            'actor' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username ?? 'user',
                'avatar_url' => $user->avatar_url,
                'locality' => $user->profile?->locality ?? 'Jashore',
                'is_friend' => $isFriend,
                'is_following' => $isFollowing,
                'is_verified' => (bool) $comment->is_verified_purchase,
            ],
            'caption' => Str::limit($comment->content, 180),
            'content_excerpt' => $comment->content,
            'attachment_url' => $comment->attachment_url,
            'intent_type' => $comment->comment_type,
            'intent_label' => $intentLabel,
            'is_verified_purchase' => (bool) $comment->is_verified_purchase,
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'slug' => $product->slug,
                'image' => $product->primaryImage?->thumbnail_url ?? $product->primaryImage?->image_url ?? $product->image,
                'base_price' => $price,
                'category' => $product->category?->name ?? 'Artisan Goods',
                'category_slug' => $product->category?->slug,
            ],
            'social_context' => [
                'reactions_count' => (int) ($comment->reactions_count ?? 0),
                'helpful_count' => (int) ($comment->helpful_count ?? 0),
                'replies_count' => (int) ($comment->replies_count ?? 0),
            ],
            'viewer_state' => [
                'has_reacted' => $viewerReaction !== null,
                'user_reaction' => $viewerReaction,
                'is_saved' => $isSavedByViewer,
            ],
            'commerce' => [
                'price' => $price,
            ],
            'allowed_actions' => ['react', 'reply', 'view', 'hide', 'less_like_this', 'mute_author', 'report'],

            // Legacy backward-compatibility fields
            'id' => $comment->id,
            'title' => $product->title,
            'image' => $product->primaryImage?->thumbnail_url ?? $product->image,
            'retailPrice' => $price,
            'retail_price' => $price,
        ];
    }

    /**
     * Format Drop card payload.
     */
    protected function formatDropCard(
        GroupBuyCampaign $campaign,
        string $feedItemId,
        bool $isFriend,
        bool $isFollowing,
        ?array $viewerState
    ): array {
        $product = $campaign->product;
        $curator = $campaign->organizer;

        $interestCount = $campaign->participants()
            ->where('status', 'interested')
            ->distinct('user_id')
            ->count('user_id');

        $qualifyingParticipants = $campaign->participants()
            ->whereIn('status', ['reserved', 'confirmed'])
            ->whereNotNull('order_id')
            ->distinct('user_id')
            ->count('user_id');

        $target = (int) $campaign->target_participants;
        $progressPercent = $target > 0 ? min(100, (int) round(($qualifyingParticipants / $target) * 100)) : 0;
        $remainingNeeded = max(0, $target - $qualifyingParticipants);

        $retailPrice = (float) ($campaign->variant?->effective_price ?? $product?->base_price ?? $campaign->group_price);
        $groupPrice = (float) $campaign->group_price;

        $hasExpressedInterest = $viewerState['has_expressed_interest'] ?? false;
        $hasOrderReservation = $viewerState['has_order_reservation'] ?? false;
        $hasConfirmedParticipation = $viewerState['has_confirmed_participation'] ?? false;
        $isJoined = $hasExpressedInterest || $hasOrderReservation || $hasConfirmedParticipation;

        $endsIn = null;
        if ($campaign->end_at) {
            $diff = now()->diff($campaign->end_at);
            if ($campaign->end_at->isPast()) {
                $endsIn = 'Ended';
            } elseif ($diff->days > 0) {
                $endsIn = "{$diff->days}d {$diff->h}h";
            } elseif ($diff->h > 0) {
                $endsIn = "{$diff->h}h {$diff->i}m";
            } else {
                $endsIn = "{$diff->i}m";
            }
        }

        $reason = $isFriend
            ? "👥 Friend's volume drop"
            : ($progressPercent >= 50 ? '🔥 High Demand Volume Drop' : 'Active Volume Drop');

        return [
            'feed_item_id' => $feedItemId,
            'type' => 'drop',
            'source_id' => $campaign->id,
            'published_at' => $campaign->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'reason' => $reason,
            'actor' => [
                'id' => $curator?->id ?? $campaign->created_by,
                'name' => $curator?->name ?? 'Community Organizer',
                'username' => $curator?->username ?? 'curator',
                'avatar_url' => $curator?->profile?->avatar_url,
                'locality' => $curator?->profile?->locality ?? 'Jashore',
                'is_friend' => $isFriend,
                'is_following' => $isFollowing,
                'is_verified' => false,
            ],
            'caption' => "Unlock wholesale price ৳{$groupPrice} together! ({$remainingNeeded} more required)",
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'slug' => $product->slug,
                'image' => $product->primaryImage?->thumbnail_url ?? $product->primaryImage?->image_url ?? $product->image,
                'base_price' => $retailPrice,
                'category' => $product->category?->name ?? 'Artisan Goods',
                'category_slug' => $product->category?->slug,
            ],
            'drop_details' => [
                'id' => $campaign->id,
                'campaign_code' => $campaign->campaign_code,
                'title' => $campaign->title,
                'retail_price' => $retailPrice,
                'group_price' => $groupPrice,
                'target_participants' => $target,
                'qualifying_participants_count' => $qualifyingParticipants,
                'interest_count' => $interestCount,
                'progress_percent' => $progressPercent,
                'remaining_needed' => $remainingNeeded,
                'ends_in' => $endsIn,
                'end_at' => $campaign->end_at?->toIso8601String(),
                'status' => $campaign->status,
            ],
            'viewer_state' => [
                'is_joined' => $isJoined,
                'has_expressed_interest' => $hasExpressedInterest,
                'has_order_reservation' => $hasOrderReservation,
                'has_confirmed_participation' => $hasConfirmedParticipation,
            ],
            'commerce' => [
                'campaign_id' => $campaign->id,
                'price' => $groupPrice,
                'original_price' => $retailPrice,
                'in_stock' => true,
            ],
            'allowed_actions' => ['join', 'view', 'share', 'hide', 'less_like_this', 'mute_author', 'report'],

            // Legacy backward-compatibility fields
            'id' => $campaign->id,
            'campaign_code' => $campaign->campaign_code,
            'title' => $campaign->title,
            'category' => $product->category?->name ?? 'Artisan Goods',
            'category_slug' => $product->category?->slug,
            'image' => $product->primaryImage?->thumbnail_url ?? $product->image,
            'retailPrice' => $retailPrice,
            'retail_price' => $retailPrice,
            'groupPrice' => $groupPrice,
            'group_price' => $groupPrice,
            'currentParticipants' => $qualifyingParticipants,
            'current_participants' => $qualifyingParticipants,
            'qualifying_participants_count' => $qualifyingParticipants,
            'interest_count' => $interestCount,
            'target_participants' => $target,
            'progress_percent' => $progressPercent,
            'remaining_needed' => $remainingNeeded,
            'isJoined' => $isJoined,
            'is_joined' => $isJoined,
            'endsIn' => $endsIn,
            'ends_in' => $endsIn,
            'end_at' => $campaign->end_at?->toIso8601String(),
            'status' => $campaign->status,
            'curator' => [
                'id' => $curator?->id,
                'name' => $curator?->name ?? 'Curator',
                'handle' => $curator?->username ?? 'curator',
                'username' => $curator?->username ?? 'curator',
                'avatar_url' => $curator?->profile?->avatar_url,
            ],
            'recommendation' => "Community volume drop for {$product->title}. Join to unlock ৳{$groupPrice}!",
            'tiers' => [
                ['requiredParticipants' => 1, 'price' => $retailPrice],
                ['requiredParticipants' => $target, 'price' => $groupPrice],
            ],
        ];
    }

    /**
     * Format Shop Spotlight card payload.
     */
    protected function formatShopCard(
        CommunityShop $shop,
        string $feedItemId,
        bool $isFriend,
        bool $isFollowing
    ): array {
        $owner = $shop->user;
        $listings = $shop->activeListings->take(4)->map(fn ($l) => [
            'id' => $l->id,
            'product_id' => $l->product_id,
            'title' => $l->product?->title,
            'price' => (float) $l->selling_price,
            'image' => $l->product?->primaryImage?->thumbnail_url ?? $l->product?->image,
        ])->all();

        $reason = $isFriend
            ? "👥 Friend's Curated Shop"
            : ($shop->is_verified ? 'Verified Community Shop' : 'Spotlight Community Shop');

        return [
            'feed_item_id' => $feedItemId,
            'type' => 'shop',
            'source_id' => $shop->id,
            'published_at' => $shop->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'reason' => $reason,
            'actor' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'username' => $owner->username ?? 'curator',
                'avatar_url' => $owner->avatar_url,
                'locality' => $owner->profile?->locality ?? 'Jashore',
                'is_friend' => $isFriend,
                'is_following' => $isFollowing,
                'is_verified' => (bool) $shop->is_verified,
            ],
            'caption' => $shop->description ?? "Explore {$shop->name}'s hand-picked catalog.",
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'slug' => $shop->slug,
                'description' => $shop->description,
                'logo_url' => $shop->logo_url,
                'banner_url' => $shop->banner_url,
                'is_verified' => (bool) $shop->is_verified,
                'active_listings_count' => $shop->activeListings->count(),
                'showcase_items' => $listings,
            ],
            'allowed_actions' => ['visit', 'follow', 'share', 'hide', 'less_like_this', 'mute_author'],

            // Legacy backward-compatibility fields
            'id' => $shop->id,
            'title' => $shop->name,
            'image' => $shop->banner_url ?? $shop->logo_url,
        ];
    }

    /**
     * Format Catalog Spotlight card payload.
     */
    protected function formatCatalogCard(
        Product $product,
        string $feedItemId,
        array $friendsWhoPicked,
        bool $isSavedByViewer
    ): array {
        $price = (float) ($product->base_price ?? 0);
        $originalPrice = $product->original_price ? (float) $product->original_price : null;

        return [
            'feed_item_id' => $feedItemId,
            'type' => 'catalog',
            'source_id' => $product->id,
            'published_at' => $product->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'reason' => 'Catalog Spotlight in Jashore',
            'actor' => [
                'id' => null,
                'name' => $product->seller?->store_name ?? 'Local Verified Merchant',
                'username' => 'merchant',
                'avatar_url' => null,
                'locality' => $product->seller?->locality ?? 'Jashore',
                'is_friend' => false,
                'is_following' => false,
                'is_verified' => true,
            ],
            'caption' => $product->description ? Str::limit($product->description, 140) : $product->title,
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'slug' => $product->slug,
                'image' => $product->primaryImage?->thumbnail_url ?? $product->primaryImage?->image_url ?? $product->image,
                'base_price' => $price,
                'original_price' => $originalPrice,
                'category' => $product->category?->name ?? 'Artisan Goods',
                'category_slug' => $product->category?->slug,
                'has_variants' => $product->activeVariants?->isNotEmpty() ?? false,
                'seller_name' => $product->seller?->store_name ?? 'Local Merchant',
            ],
            'social_context' => [
                'friends_who_picked' => array_slice($friendsWhoPicked, 0, 3),
                'friends_who_picked_count' => count($friendsWhoPicked),
            ],
            'viewer_state' => [
                'is_saved' => $isSavedByViewer,
            ],
            'commerce' => [
                'price' => $price,
                'original_price' => $originalPrice,
                'in_stock' => $product->stock_quantity > 0,
            ],
            'allowed_actions' => ['view', 'save', 'share', 'hide', 'less_like_this'],

            // Legacy backward-compatibility fields
            'id' => $product->id,
            'title' => $product->title,
            'image' => $product->primaryImage?->thumbnail_url ?? $product->image,
            'retailPrice' => $price,
            'retail_price' => $price,
        ];
    }

    /**
     * Record user preference / suppression feedback.
     */
    public function addFeedback(User $user, array $data): FeedFeedback
    {
        return FeedFeedback::create([
            'user_id' => $user->id,
            'feed_item_id' => $data['feed_item_id'] ?? null,
            'content_type' => $data['content_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'action' => $data['action'],
            'target_author_id' => $data['target_author_id'] ?? null,
            'reason' => $data['reason'] ?? null,
        ]);
    }

    /**
     * Batch log impression telemetry for analytics.
     */
    public function recordImpressions(?User $viewer, array $impressions, ?string $sessionId = null): int
    {
        $records = [];
        $now = now();

        foreach ($impressions as $imp) {
            if (empty($imp['feed_item_id'])) {
                continue;
            }
            $records[] = [
                'user_id' => $viewer?->id,
                'session_id' => $sessionId ?? ($imp['session_id'] ?? null),
                'event_type' => $imp['event_type'] ?? 'view',
                'feed_item_id' => $imp['feed_item_id'],
                'content_type' => $imp['content_type'] ?? null,
                'source_id' => $imp['source_id'] ?? null,
                'tab' => $imp['tab'] ?? 'for_you',
                'position' => (int) ($imp['position'] ?? 0),
                'ranking_version' => $imp['ranking_version'] ?? 'v1.0',
                'created_at' => $now,
            ];
        }

        if (! empty($records)) {
            FeedImpression::insert($records);
        }

        return count($records);
    }
}
