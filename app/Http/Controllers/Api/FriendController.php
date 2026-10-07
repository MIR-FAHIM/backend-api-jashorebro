<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FriendController extends Controller
{
    /**
     * List confirmed friends of the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $query = $currentUser->friendsQuery()->with('profile');

        if ($search = $request->string('q')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $friends = $query->paginate(20);

        $data = $friends->getCollection()->map(function (User $friend) use ($currentUser) {
            return [
                'id' => $friend->id,
                'name' => $friend->name,
                'username' => $friend->username,
                'avatar_url' => $friend->profile?->avatar_url,
                'locality' => $friend->profile?->locality ?? 'Jashore',
                'bio' => $friend->profile?->bio,
                'mutual_friends_count' => $currentUser->mutualFriendsCountWith($friend),
                'relationship_status' => 'friends',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $friends->currentPage(),
                'last_page' => $friends->lastPage(),
                'total' => $friends->total(),
            ],
        ]);
    }

    /**
     * Get pending incoming and outgoing friend requests.
     */
    public function requests(Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        $incoming = Friendship::with(['sender.profile'])
            ->where('recipient_id', $currentUser->id)
            ->where('status', 'pending')
            ->latest()
            ->get()
            ->map(function (Friendship $req) use ($currentUser) {
                return [
                    'id' => $req->id,
                    'created_at' => $req->created_at?->toIso8601String(),
                    'user' => [
                        'id' => $req->sender->id,
                        'name' => $req->sender->name,
                        'username' => $req->sender->username,
                        'avatar_url' => $req->sender->profile?->avatar_url,
                        'locality' => $req->sender->profile?->locality ?? 'Jashore',
                        'mutual_friends_count' => $currentUser->mutualFriendsCountWith($req->sender),
                    ],
                ];
            });

        $outgoing = Friendship::with(['recipient.profile'])
            ->where('sender_id', $currentUser->id)
            ->where('status', 'pending')
            ->latest()
            ->get()
            ->map(function (Friendship $req) use ($currentUser) {
                return [
                    'id' => $req->id,
                    'created_at' => $req->created_at?->toIso8601String(),
                    'user' => [
                        'id' => $req->recipient->id,
                        'name' => $req->recipient->name,
                        'username' => $req->recipient->username,
                        'avatar_url' => $req->recipient->profile?->avatar_url,
                        'locality' => $req->recipient->profile?->locality ?? 'Jashore',
                        'mutual_friends_count' => $currentUser->mutualFriendsCountWith($req->recipient),
                    ],
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'incoming' => $incoming,
                'outgoing' => $outgoing,
                'incoming_count' => $incoming->count(),
            ],
        ]);
    }

    /**
     * Send a friend request by recipient_id or username.
     */
    public function sendRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipient_id' => ['nullable', 'integer', 'exists:users,id'],
            'username' => ['nullable', 'string', 'exists:users,username'],
        ]);

        if (empty($validated['recipient_id']) && empty($validated['username'])) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide either recipient_id or username.',
            ], 422);
        }

        /** @var User $currentUser */
        $currentUser = $request->user();

        /** @var User|null $recipient */
        $recipient = ! empty($validated['recipient_id'])
            ? User::find($validated['recipient_id'])
            : User::where('username', $validated['username'])->first();

        if (! $recipient) {
            return response()->json(['success' => false, 'message' => 'User not found.'], 404);
        }

        if ($recipient->id === $currentUser->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot send a friend request to yourself.',
            ], 422);
        }

        // Check existing friendship
        $existing = $currentUser->friendshipWith($recipient);

        if ($existing) {
            if ($existing->status === 'accepted') {
                return response()->json([
                    'success' => false,
                    'message' => 'You are already friends with this user.',
                    'relationship_status' => 'friends',
                ], 400);
            }

            if ($existing->status === 'pending') {
                if ($existing->sender_id === $currentUser->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Friend request is already pending.',
                        'relationship_status' => 'pending_sent',
                    ], 400);
                } else {
                    // Recipient had already sent a request -> auto-accept
                    $existing->update(['status' => 'accepted', 'acted_at' => now()]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Accepted mutual friend request!',
                        'relationship_status' => 'friends',
                        'friendship_id' => $existing->id,
                    ]);
                }
            }

            if ($existing->status === 'blocked') {
                return response()->json(['success' => false, 'message' => 'Action unavailable.'], 403);
            }
        }

        $friendship = Friendship::create([
            'sender_id' => $currentUser->id,
            'recipient_id' => $recipient->id,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Friend request sent successfully.',
            'relationship_status' => 'pending_sent',
            'friendship_id' => $friendship->id,
        ], 201);
    }

    /**
     * Accept a pending incoming friend request.
     */
    public function acceptRequest(Request $request, int $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        $friendship = Friendship::where('id', $id)
            ->where('recipient_id', $currentUser->id)
            ->where('status', 'pending')
            ->first();

        if (! $friendship) {
            return response()->json([
                'success' => false,
                'message' => 'Friend request not found or already processed.',
            ], 404);
        }

        $friendship->update([
            'status' => 'accepted',
            'acted_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Friend request accepted!',
            'relationship_status' => 'friends',
            'friendship_id' => $friendship->id,
        ]);
    }

    /**
     * Decline an incoming friend request.
     */
    public function declineRequest(Request $request, int $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        $friendship = Friendship::where('id', $id)
            ->where('recipient_id', $currentUser->id)
            ->where('status', 'pending')
            ->first();

        if (! $friendship) {
            return response()->json([
                'success' => false,
                'message' => 'Friend request not found.',
            ], 404);
        }

        $friendship->delete();

        return response()->json([
            'success' => true,
            'message' => 'Friend request declined.',
            'relationship_status' => 'none',
        ]);
    }

    /**
     * Cancel an outgoing pending request.
     */
    public function cancelRequest(Request $request, int $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        $friendship = Friendship::where('id', $id)
            ->where('sender_id', $currentUser->id)
            ->where('status', 'pending')
            ->first();

        if (! $friendship) {
            return response()->json([
                'success' => false,
                'message' => 'Friend request not found.',
            ], 404);
        }

        $friendship->delete();

        return response()->json([
            'success' => true,
            'message' => 'Friend request cancelled.',
            'relationship_status' => 'none',
        ]);
    }

    /**
     * Remove a confirmed friend.
     */
    public function unfriend(Request $request, int $userId): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        $friendship = $currentUser->friendshipWith($userId);

        if (! $friendship || $friendship->status !== 'accepted') {
            return response()->json([
                'success' => false,
                'message' => 'Friendship not found.',
            ], 404);
        }

        $friendship->delete();

        return response()->json([
            'success' => true,
            'message' => 'Removed from friends.',
            'relationship_status' => 'none',
        ]);
    }

    /**
     * Search users by name, username, or phone number.
     */
    public function find(Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $query = $request->string('q')->trim()->value();

        if (! $query || strlen($query) < 2) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Query must be at least 2 characters.',
            ]);
        }

        $users = User::with('profile')
            ->where('id', '!=', $currentUser->id)
            ->where('status', 'active')
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('username', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%");
            })
            ->limit(20)
            ->get()
            ->map(function (User $user) use ($currentUser) {
                $friendship = $currentUser->friendshipWith($user);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'avatar_url' => $user->profile?->avatar_url,
                    'locality' => $user->profile?->locality ?? 'Jashore',
                    'bio' => $user->profile?->bio,
                    'mutual_friends_count' => $currentUser->mutualFriendsCountWith($user),
                    'relationship_status' => $currentUser->relationshipStatusWith($user),
                    'friendship_id' => $friendship?->id,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Get suggested users based on locality and mutual connections.
     */
    public function suggestions(Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $existingFriendIds = $currentUser->friendIds();
        $myLocality = $currentUser->profile?->locality ?? 'Jashore';

        // Exclude self and existing friends
        $excludedIds = array_merge([$currentUser->id], $existingFriendIds);

        // Also exclude pending relationships
        $pendingIds = Friendship::where('sender_id', $currentUser->id)
            ->orWhere('recipient_id', $currentUser->id)
            ->pluck('sender_id')
            ->merge(
                Friendship::where('sender_id', $currentUser->id)
                    ->orWhere('recipient_id', $currentUser->id)
                    ->pluck('recipient_id')
            )
            ->unique()
            ->all();

        $excludedIds = array_unique(array_merge($excludedIds, $pendingIds));

        $suggestions = User::with('profile')
            ->whereNotIn('id', $excludedIds)
            ->where('status', 'active')
            ->limit(15)
            ->get()
            ->map(function (User $user) use ($currentUser) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'avatar_url' => $user->profile?->avatar_url,
                    'locality' => $user->profile?->locality ?? 'Jashore',
                    'bio' => $user->profile?->bio,
                    'mutual_friends_count' => $currentUser->mutualFriendsCountWith($user),
                    'relationship_status' => 'none',
                    'friendship_id' => null,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $suggestions,
        ]);
    }

    /**
     * Public / Authenticated Instagram-style user profile by username.
     */
    public function userProfile(Request $request, string $username): JsonResponse
    {
        /** @var User|null $currentUser */
        $currentUser = $request->user() ?? auth('sanctum')->user();

        /** @var User|null $user */
        $user = User::with('profile')->where('username', $username)->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $friendIds = $user->friendIds();
        $friendsCount = count($friendIds);

        $relationshipStatus = 'none';
        $mutualFriendsCount = 0;
        $friendshipId = null;

        if ($currentUser) {
            $relationshipStatus = $currentUser->relationshipStatusWith($user);
            $mutualFriendsCount = $currentUser->mutualFriendsCountWith($user);
            $friendship = $currentUser->friendshipWith($user);
            $friendshipId = $friendship?->id;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'phone_verified' => $user->isPhoneVerified(),
                'joined_at' => $user->created_at?->format('F Y'),
                'profile' => [
                    'bio' => $user->profile?->bio ?? 'Community member in JashoreBro.',
                    'avatar_url' => $user->profile?->avatar_url,
                    'locality' => $user->profile?->locality ?? 'Jashore',
                    'district' => $user->profile?->district ?? 'Jashore',
                ],
                'stats' => [
                    'friends_count' => $friendsCount,
                    'mutual_friends_count' => $mutualFriendsCount,
                    'picks_count' => 0, // Hooked up when shelf feature is deployed
                    'drops_joined_count' => 0,
                ],
                'relationship_status' => $relationshipStatus,
                'friendship_id' => $friendshipId,
            ],
        ]);
    }
}
