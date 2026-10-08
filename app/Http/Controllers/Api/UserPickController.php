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

        $picks = UserPick::with(['product.primaryImage', 'product.activeVariants'])
            ->where('user_id', $user->id)
            ->orderBy('display_order', 'asc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $picks,
        ]);
    }

    /**
     * Add a product to My Picks.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'caption' => 'nullable|string|max:1000',
            'is_featured' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
        ]);

        $existing = UserPick::where('user_id', $user->id)
            ->where('product_id', $validated['product_id'])
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'product_id' => ['This product is already in your picks.'],
            ]);
        }

        $code = 'REC-' . strtoupper(Str::random(4)) . '-' . $user->id . '-' . $validated['product_id'];

        $pick = UserPick::create([
            'user_id' => $user->id,
            'product_id' => $validated['product_id'],
            'caption' => $validated['caption'] ?? null,
            'display_order' => (int) UserPick::where('user_id', $user->id)->count(),
            'is_featured' => (bool) ($validated['is_featured'] ?? false),
            'is_public' => (bool) ($validated['is_public'] ?? true),
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
                $product = Product::find($pick->product_id);
                $this->notificationService->sendToUser(
                    user: $follower,
                    event: 'community.new_pick',
                    title: "{$user->name} shared a new Pick!",
                    message: "Check out {$user->name}'s recommendation for {$product?->title}.",
                    subject: $pick,
                    actionUrl: "/p/{$product?->slug}?rec={$pick->recommendation_code}",
                    audience: 'customer',
                    dedupKey: "notif_pick_{$pick->id}_to_{$followerId}"
                );
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Product saved to your picks.',
            'data' => $pick->load(['product.primaryImage']),
        ], 201);
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
     * Remove a product from My Picks.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $pick = UserPick::where('user_id', $user->id)->findOrFail($id);
        $pick->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product removed from picks.',
        ]);
    }

    /**
     * Public user picks by username.
     */
    public function userPicks(string $username): JsonResponse
    {
        $targetUser = User::where('username', $username)
            ->orWhere('id', is_numeric($username) ? (int) $username : 0)
            ->firstOrFail();

        $picks = UserPick::with(['product.primaryImage'])
            ->where('user_id', $targetUser->id)
            ->where('is_public', true)
            ->orderBy('is_featured', 'desc')
            ->orderBy('display_order', 'asc')
            ->get();

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
