<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeedFeedback;
use App\Services\CommunityFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunityFeedController extends Controller
{
    public function __construct(
        protected CommunityFeedService $feedService
    ) {}

    /**
     * Public / authenticated community discovery feed (For You or filtered).
     */
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user('sanctum');
        $params = $request->all();

        // Preserve legacy filter tab mapping if passed
        if ($request->has('filter') && ! $request->has('tab')) {
            $filter = $request->query('filter');
            if ($filter === 'Following') {
                $params['tab'] = 'following';
            } elseif ($filter === 'Trending') {
                $params['tab'] = 'trending';
            } else {
                $params['tab'] = 'for_you';
                if ($filter !== 'For you' && ! empty($filter)) {
                    $params['category'] = strtolower($filter);
                }
            }
        }

        $result = $this->feedService->getFeed($viewer, $params);

        return response()->json($result);
    }

    /**
     * Authenticated following feed.
     */
    public function following(Request $request): JsonResponse
    {
        $viewer = $request->user('sanctum') ?? $request->user();
        $params = array_merge($request->all(), ['tab' => 'following']);

        $result = $this->feedService->getFeed($viewer, $params);

        return response()->json($result);
    }

    /**
     * Trending momentum feed.
     */
    public function trending(Request $request): JsonResponse
    {
        $viewer = $request->user('sanctum');
        $params = array_merge($request->all(), ['tab' => 'trending']);

        $result = $this->feedService->getFeed($viewer, $params);

        return response()->json($result);
    }

    /**
     * User feed preference & suppression feedback (Hide, Less like this, Mute).
     */
    public function addFeedback(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'feed_item_id' => 'nullable|string|max:100',
            'content_type' => 'nullable|string|max:50',
            'source_id' => 'nullable|integer',
            'action' => 'required|in:' . implode(',', [
                FeedFeedback::ACTION_HIDE,
                FeedFeedback::ACTION_LESS_LIKE_THIS,
                FeedFeedback::ACTION_MUTE_AUTHOR,
                FeedFeedback::ACTION_MUTE_TOPIC,
            ]),
            'target_author_id' => 'nullable|integer|exists:users,id',
            'reason' => 'nullable|string|max:255',
        ]);

        $feedback = $this->feedService->addFeedback($user, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Feed preference saved successfully.',
            'feedback' => $feedback,
        ]);
    }

    /**
     * Deduplicated batch impression telemetry.
     */
    public function addImpressions(Request $request): JsonResponse
    {
        $viewer = $request->user('sanctum');

        $validated = $request->validate([
            'impressions' => 'required|array|min:1|max:100',
            'impressions.*.feed_item_id' => 'required|string',
            'impressions.*.content_type' => 'nullable|string',
            'impressions.*.source_id' => 'nullable|integer',
            'impressions.*.tab' => 'nullable|string',
            'impressions.*.position' => 'nullable|integer',
            'impressions.*.event_type' => 'nullable|string',
            'session_id' => 'nullable|string|max:100',
        ]);

        $count = $this->feedService->recordImpressions(
            viewer: $viewer,
            impressions: $validated['impressions'],
            sessionId: $validated['session_id'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => "Logged {$count} feed impressions.",
            'count' => $count,
        ]);
    }
}
