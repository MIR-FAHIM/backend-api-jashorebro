<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Models\Product;
use App\Models\UserPick;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunityFeedController extends Controller
{
    /**
     * Public / authenticated community discovery feed.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $filter = $request->query('filter', 'For you');
        $categorySlug = $request->query('category');

        $query = GroupBuyCampaign::with([
            'product.primaryImage',
            'product.category',
            'product.seller',
            'variant',
            'organizer.profile',
        ])
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('end_at')->orWhere('end_at', '>', now());
            });

        // Filter by specific category or filter tab
        if (! empty($categorySlug) && $categorySlug !== 'all') {
            $query->whereHas('product.category', function ($q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            });
        } elseif ($filter !== 'For you' && $filter !== 'Following') {
            $query->whereHas('product.category', function ($q) use ($filter) {
                $q->where('name', 'like', "%{$filter}%")->orWhere('slug', strtolower($filter));
            });
        }

        // Handle Following tab
        if ($filter === 'Following') {
            if (! $user) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'requires_auth' => true,
                    'message' => 'Sign in to see drops and picks from community curators you follow.',
                ]);
            }

            $followedUserIds = $user->following()->pluck('followed_user_id')->toArray();
            if (empty($followedUserIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'You are not following any curators yet. Explore the community feed to follow curators.',
                ]);
            }

            $query->whereIn('organizer_user_id', $followedUserIds);
        }

        $campaigns = $query->orderBy('id', 'desc')->paginate(15);

        // Pre-fetch joined campaign IDs for authenticated user
        $joinedCampaignIds = [];
        if ($user) {
            $joinedCampaignIds = GroupBuyParticipant::where('user_id', $user->id)
                ->whereIn('status', ['reserved', 'confirmed'])
                ->pluck('campaign_id')
                ->unique()
                ->toArray();
        }

        $items = $campaigns->getCollection()->map(function (GroupBuyCampaign $c) use ($joinedCampaignIds) {
            $product = $c->product;
            $distinctParticipants = $c->participants()
                ->whereIn('status', ['reserved', 'confirmed'])
                ->distinct('user_id')
                ->count('user_id');

            $target = (int) $c->target_participants;
            $progressPercent = $target > 0 ? min(100, round(($distinctParticipants / $target) * 100)) : 0;
            $remainingNeeded = max(0, $target - $distinctParticipants);

            $retailPrice = (float) ($product?->base_price ?? $c->group_price);
            $groupPrice = (float) $c->group_price;

            $endsIn = null;
            if ($c->end_at) {
                $diff = now()->diff($c->end_at);
                if ($c->end_at->isPast()) {
                    $endsIn = 'Ended';
                } elseif ($diff->days > 0) {
                    $endsIn = "{$diff->days}d {$diff->h}h";
                } elseif ($diff->h > 0) {
                    $endsIn = "{$diff->h}h {$diff->i}m";
                } else {
                    $endsIn = "{$diff->i}m";
                }
            }

            $tiers = [
                ['requiredParticipants' => 1, 'price' => $retailPrice],
                ['requiredParticipants' => $target, 'price' => $groupPrice],
            ];

            return [
                'id' => $c->id,
                'campaign_code' => $c->campaign_code,
                'title' => $c->title,
                'category' => $product?->category?->name ?? 'Artisan Goods',
                'category_slug' => $product?->category?->slug,
                'image' => $product?->primaryImage?->thumbnail_url ?? $product?->image,
                'retailPrice' => $retailPrice,
                'retail_price' => $retailPrice,
                'groupPrice' => $groupPrice,
                'group_price' => $groupPrice,
                'currentParticipants' => $distinctParticipants,
                'current_participants' => $distinctParticipants,
                'target_participants' => $target,
                'progress_percent' => $progressPercent,
                'remaining_needed' => $remainingNeeded,
                'isJoined' => in_array($c->id, $joinedCampaignIds),
                'is_joined' => in_array($c->id, $joinedCampaignIds),
                'endsIn' => $endsIn,
                'ends_in' => $endsIn,
                'end_at' => $c->end_at?->toIso8601String(),
                'status' => $c->status,
                'curator' => [
                    'id' => $c->organizer?->id ?? $c->created_by,
                    'name' => $c->organizer?->name ?? 'Community Organizer',
                    'handle' => $c->organizer?->username ?? 'community',
                    'username' => $c->organizer?->username ?? 'community',
                    'avatar_url' => $c->organizer?->profile?->avatar_url,
                ],
                'recommendation' => "Community volume drop for {$product?->title}. Join to unlock ৳{$groupPrice}!",
                'tiers' => $tiers,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    /**
     * Authenticated following feed.
     */
    public function following(Request $request): JsonResponse
    {
        $user = $request->user();
        $followedUserIds = $user->following()->pluck('followed_user_id')->toArray();

        if (empty($followedUserIds)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'You are not following any curators yet.',
            ]);
        }

        // Fetch drops organized by followed users
        $campaigns = GroupBuyCampaign::with([
            'product.primaryImage',
            'product.category',
            'organizer.profile',
        ])
            ->whereIn('organizer_user_id', $followedUserIds)
            ->where('status', 'active')
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get();

        $joinedCampaignIds = GroupBuyParticipant::where('user_id', $user->id)
            ->whereIn('status', ['reserved', 'confirmed'])
            ->pluck('campaign_id')
            ->unique()
            ->toArray();

        $items = $campaigns->map(function (GroupBuyCampaign $c) use ($joinedCampaignIds) {
            $product = $c->product;
            $distinctParticipants = $c->participants()
                ->whereIn('status', ['reserved', 'confirmed'])
                ->distinct('user_id')
                ->count('user_id');

            $target = (int) $c->target_participants;
            $retailPrice = (float) ($product?->base_price ?? $c->group_price);
            $groupPrice = (float) $c->group_price;

            return [
                'id' => $c->id,
                'campaign_code' => $c->campaign_code,
                'title' => $c->title,
                'category' => $product?->category?->name ?? 'Artisan Goods',
                'image' => $product?->primaryImage?->thumbnail_url ?? $product?->image,
                'retailPrice' => $retailPrice,
                'groupPrice' => $groupPrice,
                'currentParticipants' => $distinctParticipants,
                'target_participants' => $target,
                'progress_percent' => $target > 0 ? min(100, round(($distinctParticipants / $target) * 100)) : 0,
                'isJoined' => in_array($c->id, $joinedCampaignIds),
                'status' => $c->status,
                'curator' => [
                    'id' => $c->organizer?->id,
                    'name' => $c->organizer?->name ?? 'Curator',
                    'handle' => $c->organizer?->username ?? 'curator',
                ],
                'recommendation' => "Community volume drop by {$c->organizer?->name}.",
                'tiers' => [
                    ['requiredParticipants' => 1, 'price' => $retailPrice],
                    ['requiredParticipants' => $target, 'price' => $groupPrice],
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }
}
