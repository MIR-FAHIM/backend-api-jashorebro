<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Models\UserPick;
use App\Services\LogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserPickController extends Controller
{
    public function __construct(
        protected LogService $logService,
        protected NotificationService $notificationService
    ) {}

    /**
     * Get authenticated user's picks.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = UserPick::with(['product.primaryImage', 'product.activeVariants'])
            ->where('user_id', $user->id)
            ->orderBy('display_order', 'asc')
            ->orderBy('id', 'desc');

        if ($request->has('per_page') || $request->has('page')) {
            $perPage = min((int) $request->input('per_page', 20), 50);
            $paginated = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $paginated->items(),
                'picks' => $paginated->items(),
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ]);
        }

        $picks = $query->get();

        return response()->json([
            'success' => true,
            'data' => $picks,
            'picks' => $picks,
        ]);
    }

    /**
     * Add a product to My Picks (Standard / Activity endpoint).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required',
            'caption' => 'nullable|string|max:1000',
            'personal_caption' => 'nullable|string|max:1000',
            'is_featured' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
        ]);

        return $this->performPick($request->user(), $validated['product_id'], $validated);
    }

    /**
     * Pick a product via RESTful endpoint: POST /api/products/{productId}/pick
     */
    public function pickProduct(Request $request, string $productId): JsonResponse
    {
        $validated = $request->validate([
            'caption' => 'nullable|string|max:1000',
            'personal_caption' => 'nullable|string|max:1000',
            'is_featured' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
        ]);

        return $this->performPick($request->user(), $productId, $validated);
    }

    /**
     * Shared logic to pick a catalog product.
     */
    protected function performPick(User $user, int|string $productIdOrSlug, array $data): JsonResponse
    {
        $product = Product::where(function ($q) use ($productIdOrSlug) {
                if (is_numeric($productIdOrSlug)) {
                    $q->where('id', (int) $productIdOrSlug);
                }
                $q->orWhere('slug', $productIdOrSlug);
            })
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => ['The specified product does not exist or is not available for selection.'],
            ]);
        }

        $existing = UserPick::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'product_id' => ['This product is already in your picks.'],
            ]);
        }

        $caption = $data['caption'] ?? $data['personal_caption'] ?? null;
        $code = 'REC-' . strtoupper(Str::random(4)) . '-' . $user->id . '-' . $product->id;

        $pick = UserPick::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'caption' => $caption,
            'display_order' => (int) UserPick::where('user_id', $user->id)->count(),
            'is_featured' => (bool) ($data['is_featured'] ?? false),
            'is_public' => (bool) ($data['is_public'] ?? true),
            'recommendation_code' => $code,
        ]);

        $this->logService->record(
            event: 'pick.added',
            outcome: 'success',
            actor: $user,
            subject: $pick,
            metadata: [
                'pick_id' => $pick->id,
                'product_id' => $pick->product_id,
                'recommendation_code' => $pick->recommendation_code,
            ]
        );

        // Notify followers of new pick
        $followers = $user->followers()->pluck('follower_id');
        foreach ($followers as $followerId) {
            $follower = User::find($followerId);
            if ($follower) {
                $this->notificationService->sendToUser(
                    user: $follower,
                    event: 'community.new_pick',
                    title: "{$user->name} shared a new Pick!",
                    message: "Check out {$user->name}'s recommendation for {$product->title}.",
                    subject: $pick,
                    actionUrl: "/p/{$product->slug}?rec={$pick->recommendation_code}",
                    audience: 'customer',
                    dedupKey: "notif_pick_{$pick->id}_to_{$followerId}"
                );
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Product saved to your picks.',
            'has_picked' => true,
            'data' => $pick->load(['product.primaryImage']),
            'pick' => $pick,
        ], 201);
    }

    /**
     * Remove a product from My Picks via RESTful: DELETE /api/products/{productId}/pick
     */
    public function unpickProduct(Request $request, string $productId): JsonResponse
    {
        $user = $request->user();

        $product = Product::where(function ($q) use ($productId) {
                if (is_numeric($productId)) {
                    $q->where('id', (int) $productId);
                }
                $q->orWhere('slug', $productId);
            })
            ->first();

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        $pick = UserPick::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($pick) {
            $pick->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Product removed from picks.',
            'has_picked' => false,
        ]);
    }

    /**
     * Update an existing pick.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $pick = UserPick::where('user_id', $user->id)->findOrFail($id);

        $validated = $request->validate([
            'caption' => 'nullable|string|max:1000',
            'display_order' => 'nullable|integer|min:0',
            'is_featured' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
        ]);

        $pick->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Pick updated successfully.',
            'data' => $pick->fresh(['product.primaryImage']),
        ]);
    }

    /**
     * Remove a product from My Picks by pick ID.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $pick = UserPick::where('user_id', $user->id)->findOrFail($id);
        $pick->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product removed from picks.',
            'has_picked' => false,
        ]);
    }

    /**
     * Public user picks by username or user ID.
     */
    public function userPicks(Request $request, string $username): JsonResponse
    {
        $targetUser = User::where('username', $username)
            ->orWhere('id', is_numeric($username) ? (int) $username : 0)
            ->firstOrFail();

        /** @var User|null $currentUser */
        $currentUser = $request->user('sanctum') ?? auth('sanctum')->user();
        if ($currentUser && $currentUser->id !== $targetUser->id) {
            $status = $currentUser->relationshipStatusWith($targetUser);
            if ($status === 'blocked') {
                return response()->json([
                    'success' => false,
                    'message' => 'This user profile is not accessible.',
                ], 403);
            }
        }

        $query = UserPick::with(['product.primaryImage'])
            ->where('user_id', $targetUser->id)
            ->where('is_public', true)
            ->orderBy('is_featured', 'desc')
            ->orderBy('display_order', 'asc');

        if ($request->has('per_page') || $request->has('page')) {
            $perPage = min((int) $request->input('per_page', 20), 50);
            $paginated = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $targetUser->id,
                        'name' => $targetUser->name,
                        'username' => $targetUser->username,
                    ],
                    'picks' => $paginated->items(),
                ],
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ]);
        }

        $picks = $query->get();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'username' => $targetUser->username,
                ],
                'picks' => $picks,
            ],
        ]);
    }

    /**
     * List confirmed friends who have picked this product.
     * GET /api/products/{productId}/picked-by-friends
     */
    public function friendsWhoPicked(Request $request, string $productId): JsonResponse
    {
        $product = Product::where(function ($q) use ($productId) {
                if (is_numeric($productId)) {
                    $q->where('id', (int) $productId);
                }
                $q->orWhere('slug', $productId);
            })
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->first();

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        /** @var User|null $currentUser */
        $currentUser = $request->user('sanctum') ?? auth('sanctum')->user();

        if (! $currentUser) {
            return response()->json([
                'success' => true,
                'count' => 0,
                'data' => [],
                'friends' => [],
            ]);
        }

        $friendIds = $currentUser->friendIds();

        if (empty($friendIds)) {
            return response()->json([
                'success' => true,
                'count' => 0,
                'data' => [],
                'friends' => [],
            ]);
        }

        $query = UserPick::with(['user.profile', 'user.communityShop'])
            ->where('product_id', $product->id)
            ->where('is_public', true)
            ->whereIn('user_id', $friendIds)
            ->latest('id');

        $perPage = min((int) $request->input('per_page', 20), 50);
        $paginated = $query->paginate($perPage);

        $friends = $paginated->getCollection()->map(function (UserPick $pick) use ($currentUser) {
            $friend = $pick->user;
            return [
                'id' => $friend->id,
                'name' => $friend->name,
                'username' => $friend->username,
                'avatar_url' => $friend->profile?->avatar_url ?? $friend->profile?->avatar_path,
                'locality' => $friend->profile?->locality ?? 'Jashore',
                'bio' => $friend->profile?->bio,
                'profile_url' => "/users/{$friend->username}",
                'relationship_status' => 'friends',
                'mutual_friends_count' => $currentUser->mutualFriendsCountWith($friend),
                'has_shop' => $friend->isCommunitySeller(),
                'shop_slug' => $friend->communityShop?->slug,
                'caption' => $pick->caption,
                'recommendation_code' => $pick->recommendation_code,
                'picked_at' => $pick->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'count' => $paginated->total(),
            'data' => $friends,
            'friends' => $friends,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Lookup pick by recommendation code.
     */
    public function lookupCode(string $code): JsonResponse
    {
        $pick = UserPick::with(['product.primaryImage', 'product.activeVariants', 'user:id,name,username'])
            ->where('recommendation_code', $code)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $pick,
        ]);
    }
}
