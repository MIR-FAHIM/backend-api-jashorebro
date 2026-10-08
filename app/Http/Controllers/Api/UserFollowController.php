<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserFollow;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserFollowController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Follow another user / shop owner (one-way).
     */
    public function follow(Request $request, int $userId): JsonResponse
    {
        $follower = $request->user();

        if ($follower->id === $userId) {
            throw ValidationException::withMessages([
                'user_id' => ['You cannot follow yourself.'],
            ]);
        }

        $targetUser = User::findOrFail($userId);

        $follow = UserFollow::firstOrCreate([
            'follower_id' => $follower->id,
            'followed_user_id' => $targetUser->id,
        ]);

        if ($follow->wasRecentlyCreated) {
            $this->notificationService->sendToUser(
                user: $targetUser,
                event: 'community.new_follower',
                title: 'New Follower!',
                message: "{$follower->name} started following your store and picks.",
                subject: $follower,
                actionUrl: "/profile/{$follower->username}",
                audience: 'customer',
                dedupKey: "notif_follow_{$follower->id}_to_{$targetUser->id}"
            );
        }

        return response()->json([
            'success' => true,
            'message' => "You are now following {$targetUser->name}.",
            'is_following' => true,
        ]);
    }

    /**
     * Unfollow a user.
     */
    public function unfollow(Request $request, int $userId): JsonResponse
    {
        $follower = $request->user();

        UserFollow::where('follower_id', $follower->id)
            ->where('followed_user_id', $userId)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Unfollowed successfully.',
            'is_following' => false,
        ]);
    }

    /**
     * Check if authenticated user is following target user.
     */
    public function status(Request $request, int $userId): JsonResponse
    {
        $follower = $request->user();
        $isFollowing = false;

        if ($follower) {
            $isFollowing = UserFollow::where('follower_id', $follower->id)
                ->where('followed_user_id', $userId)
                ->exists();
        }

        $followerCount = UserFollow::where('followed_user_id', $userId)->count();
        $followingCount = UserFollow::where('follower_id', $userId)->count();

        return response()->json([
            'success' => true,
            'is_following' => $isFollowing,
            'follower_count' => $followerCount,
            'following_count' => $followingCount,
        ]);
    }

    /**
     * List user's followers.
     */
    public function followers(int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $followers = $user->followers()
            ->with('follower:id,name,username')
            ->orderBy('id', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $followers->getCollection()->pluck('follower'),
            'meta' => [
                'total' => $followers->total(),
            ],
        ]);
    }

    /**
     * List users that target user is following.
     */
    public function following(int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $following = $user->following()
            ->with('followedUser:id,name,username')
            ->orderBy('id', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $following->getCollection()->pluck('followedUser'),
            'meta' => [
                'total' => $following->total(),
            ],
        ]);
    }
}
