<?php

namespace App\Services;

use App\Models\CommentReaction;
use App\Models\CommentReport;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductComment;
use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductCommentService
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Determine if a user has a verified delivered/completed purchase for the product.
     */
    public function checkVerifiedPurchase(int $userId, int $productId): bool
    {
        if ($userId <= 0 || $productId <= 0) {
            return false;
        }

        return Order::where('user_id', $userId)
            ->whereIn('status', ['delivered', 'completed'])
            ->whereHas('items', fn ($query) => $query->where('product_id', $productId))
            ->exists();
    }

    /**
     * Retrieve paginated public comments with replies and the current user's reaction state.
     */
    public function listComments(Product $product, array $filters = [], ?User $currentUser = null): LengthAwarePaginator
    {
        $query = ProductComment::query()
            ->where('product_id', $product->id)
            ->root()
            ->published()
            ->with([
                'user:id,name,username,status',
                'user.profile:id,user_id,avatar_path,locality',
                'alternativeProduct:id,title,slug,base_price,status',
                'alternativeProduct.primaryImage',
                'replies' => function ($replyQuery) {
                    $replyQuery->with([
                        'user:id,name,username,status',
                        'user.profile:id,user_id,avatar_path,locality',
                    ]);
                },
            ]);

        // Filter by comment type
        if (! empty($filters['type']) && $filters['type'] !== 'all') {
            $query->where('comment_type', $filters['type']);
        }

        // Filter by verified buyer status
        if (! empty($filters['verified_only']) && filter_var($filters['verified_only'], FILTER_VALIDATE_BOOLEAN)) {
            $query->where('is_verified_purchase', true);
        }

        // Sorting
        $sort = $filters['sort'] ?? 'latest';
        match ($sort) {
            'oldest' => $query->orderByDesc('is_pinned')->oldest('id'),
            'most_helpful' => $query->orderByDesc('is_pinned')->orderByDesc('helpful_count')->latest('id'),
            'most_reacted' => $query->orderByDesc('is_pinned')->orderByDesc('reactions_count')->latest('id'),
            default => $query->orderByDesc('is_pinned')->latest('id'),
        };

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 50);
        $paginator = $query->paginate($perPage);

        // Batch load current user's reactions for all items on page (roots + replies)
        $userReactions = [];
        if ($currentUser) {
            $commentIds = $paginator->getCollection()->pluck('id')->all();
            foreach ($paginator->getCollection() as $comment) {
                if ($comment->relationLoaded('replies')) {
                    $commentIds = array_merge($commentIds, $comment->replies->pluck('id')->all());
                }
            }

            if (! empty($commentIds)) {
                $userReactions = CommentReaction::where('user_id', $currentUser->id)
                    ->whereIn('comment_id', array_unique($commentIds))
                    ->pluck('reaction_type', 'comment_id')
                    ->all();
            }
        }

        // Transform collection to format comments with user reactions and metadata
        $paginator->getCollection()->transform(function (ProductComment $comment) use ($currentUser, $userReactions) {
            return $this->formatComment($comment, $currentUser, $userReactions);
        });

        return $paginator;
    }

    /**
     * Compute comprehensive social comments summary for a product.
     */
    public function getSummary(Product $product, ?User $currentUser = null): array
    {
        $publishedComments = ProductComment::where('product_id', $product->id)->published();

        $totalComments = (clone $publishedComments)->count();
        $rootCommentsCount = (clone $publishedComments)->root()->count();
        $repliesCount = (clone $publishedComments)->whereNotNull('parent_id')->count();
        $totalReactions = (int) (clone $publishedComments)->sum('reactions_count');

        // Reaction counts breakdown
        $reactionBreakdown = [
            CommentReaction::TYPE_LIKE => (int) (clone $publishedComments)->sum('likes_count'),
            CommentReaction::TYPE_HELPFUL => (int) (clone $publishedComments)->sum('helpful_count'),
            CommentReaction::TYPE_AGREE => (int) (clone $publishedComments)->sum('agree_count'),
            CommentReaction::TYPE_NEED_DETAILS => (int) (clone $publishedComments)->sum('need_details_count'),
            CommentReaction::TYPE_GOOD_DEAL => (int) (clone $publishedComments)->sum('good_deal_count'),
        ];

        // Type breakdown
        $typeBreakdown = (clone $publishedComments)
            ->selectRaw('comment_type, count(*) as total')
            ->groupBy('comment_type')
            ->pluck('total', 'comment_type')
            ->all();

        // Top 3 most helpful comments preview
        $topHelpful = ProductComment::where('product_id', $product->id)
            ->published()
            ->root()
            ->where('helpful_count', '>', 0)
            ->orderByDesc('helpful_count')
            ->with(['user:id,name,username', 'user.profile:id,user_id,avatar_path,locality'])
            ->limit(3)
            ->get()
            ->map(fn ($c) => $this->formatComment($c, $currentUser));

        // Active drop reference if product is attached to an active campaign
        $activeDrop = null;
        if ($product->activeCampaign) {
            $ac = $product->activeCampaign;
            $activeDrop = [
                'campaign_id' => $ac->id,
                'campaign_code' => $ac->campaign_code,
                'title' => $ac->title,
                'group_price' => $ac->group_price,
                'progress_percent' => $ac->progress_percent,
                'remaining_needed' => $ac->remaining_needed,
            ];
        }

        return [
            'total_comments' => $totalComments,
            'root_comments_count' => $rootCommentsCount,
            'replies_count' => $repliesCount,
            'total_reactions' => $totalReactions,
            'reactions_breakdown' => $reactionBreakdown,
            'comment_types_breakdown' => $typeBreakdown,
            'verified_purchases_count' => (clone $publishedComments)->where('is_verified_purchase', true)->count(),
            'top_helpful_comments' => $topHelpful,
            'has_user_commented' => $currentUser ? ProductComment::where('product_id', $product->id)->where('user_id', $currentUser->id)->exists() : false,
            'is_user_verified_purchaser' => $currentUser ? $this->checkVerifiedPurchase($currentUser->id, $product->id) : false,
            'active_drop' => $activeDrop,
        ];
    }

    /**
     * Create a comment or reply.
     */
    public function createComment(Product $product, User $user, array $data, ?UploadedFile $attachment = null): ProductComment
    {
        $parentId = ! empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        $content = trim($data['content'] ?? '');
        $commentType = $data['comment_type'] ?? ProductComment::TYPE_GENERAL;
        $effectiveParent = null;

        // 1-Level Nesting Policy
        if ($parentId) {
            $parent = ProductComment::where('product_id', $product->id)->findOrFail($parentId);

            if ($parent->parent_id !== null) {
                // If replying to a reply, normalize parent to the root comment
                $effectiveParent = $parent->parent;
                $parentId = $effectiveParent->id;

                // Prepend mention of the reply author if not already in text
                $authorMention = "@{$parent->user->username}";
                if (! str_contains($content, $authorMention)) {
                    $content = "{$authorMention} {$content}";
                }
            } else {
                $effectiveParent = $parent;
            }
        }

        // Verified purchase check
        $isVerifiedPurchase = $this->checkVerifiedPurchase($user->id, $product->id);

        // Upload image attachment if present
        $attachmentUrl = null;
        if ($attachment) {
            $path = $attachment->store("comments/{$product->id}", 'public');
            $attachmentUrl = MediaUrl::resolve(Storage::disk('public')->url($path), $path);
        } elseif (! empty($data['attachment_url'])) {
            $attachmentUrl = MediaUrl::resolve($data['attachment_url']);
        }

        $alternativeProductId = ! empty($data['alternative_product_id']) ? (int) $data['alternative_product_id'] : null;
        $priceSuggestion = isset($data['price_suggestion']) && $data['price_suggestion'] !== '' ? (float) $data['price_suggestion'] : null;

        $comment = DB::transaction(function () use (
            $product,
            $user,
            $parentId,
            $commentType,
            $content,
            $attachmentUrl,
            $alternativeProductId,
            $priceSuggestion,
            $isVerifiedPurchase,
            $effectiveParent
        ) {
            $comment = ProductComment::create([
                'product_id' => $product->id,
                'user_id' => $user->id,
                'parent_id' => $parentId,
                'comment_type' => $commentType,
                'content' => $content,
                'attachment_url' => $attachmentUrl,
                'alternative_product_id' => $alternativeProductId,
                'price_suggestion' => $priceSuggestion,
                'is_verified_purchase' => $isVerifiedPurchase,
                'status' => ProductComment::STATUS_PUBLISHED,
            ]);

            if ($effectiveParent) {
                $effectiveParent->increment('replies_count');
            }

            return $comment;
        });

        // Notifications
        $this->dispatchCommentNotifications($comment, $user, $product, $effectiveParent);

        $comment->load([
            'user:id,name,username,status',
            'user.profile:id,user_id,avatar_path,locality',
            'alternativeProduct:id,title,slug,base_price,status',
        ]);

        return $comment;
    }

    /**
     * Update an existing comment.
     */
    public function updateComment(ProductComment $comment, User $user, array $data): ProductComment
    {
        if (! $comment->isAuthor($user)) {
            throw ValidationException::withMessages([
                'authorization' => ['You are not authorized to edit this comment.'],
            ]);
        }

        $content = trim($data['content'] ?? '');

        $comment->update([
            'content' => $content,
            'edited_at' => now(),
        ]);

        $comment->load([
            'user:id,name,username,status',
            'user.profile:id,user_id,avatar_path,locality',
        ]);

        return $comment;
    }

    /**
     * Delete a comment (soft delete).
     */
    public function deleteComment(ProductComment $comment, User $user): bool
    {
        if (! $comment->isAuthor($user) && ! $user->isAdmin()) {
            throw ValidationException::withMessages([
                'authorization' => ['You are not authorized to delete this comment.'],
            ]);
        }

        return DB::transaction(function () use ($comment) {
            if ($comment->parent_id) {
                ProductComment::where('id', $comment->parent_id)->decrement('replies_count');
            }

            return $comment->delete();
        });
    }

    /**
     * Toggle or update user reaction on a comment.
     */
    public function toggleReaction(ProductComment $comment, User $user, string $reactionType): array
    {
        if (! in_array($reactionType, CommentReaction::TYPES, true)) {
            throw ValidationException::withMessages([
                'reaction_type' => ['Invalid reaction type provided.'],
            ]);
        }

        return DB::transaction(function () use ($comment, $user, $reactionType) {
            $existing = CommentReaction::where('comment_id', $comment->id)
                ->where('user_id', $user->id)
                ->first();

            $newCol = $this->getColumnForReaction($reactionType);

            if ($existing) {
                if ($existing->reaction_type === $reactionType) {
                    // Clicked the same reaction again: toggle off / remove
                    $existing->delete();
                    $comment->decrement($newCol);
                    $comment->decrement('reactions_count');

                    $action = 'removed';
                    $activeReaction = null;
                } else {
                    // Changed to a different reaction
                    $oldCol = $this->getColumnForReaction($existing->reaction_type);
                    $existing->update(['reaction_type' => $reactionType]);

                    $comment->decrement($oldCol);
                    $comment->increment($newCol);

                    $action = 'updated';
                    $activeReaction = $reactionType;
                }
            } else {
                // New reaction
                CommentReaction::create([
                    'comment_id' => $comment->id,
                    'user_id' => $user->id,
                    'reaction_type' => $reactionType,
                ]);

                $comment->increment($newCol);
                $comment->increment('reactions_count');

                $action = 'created';
                $activeReaction = $reactionType;
            }

            $comment->refresh();

            return [
                'action' => $action,
                'comment_id' => $comment->id,
                'user_reaction' => $activeReaction,
                'reactions_count' => (int) $comment->reactions_count,
                'reaction_counts' => $comment->reactionBreakdown(),
            ];
        });
    }

    /**
     * Submit an abuse or spam report on a comment.
     */
    public function reportComment(ProductComment $comment, User $user, string $reason, ?string $description = null): CommentReport
    {
        if (! in_array($reason, CommentReport::REASONS, true)) {
            throw ValidationException::withMessages([
                'reason' => ['Invalid report reason provided.'],
            ]);
        }

        $existing = CommentReport::where('comment_id', $comment->id)
            ->where('reporter_id', $user->id)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'report' => ['You have already reported this comment.'],
            ]);
        }

        return DB::transaction(function () use ($comment, $user, $reason, $description) {
            $report = CommentReport::create([
                'comment_id' => $comment->id,
                'reporter_id' => $user->id,
                'reason' => $reason,
                'description' => $description,
                'status' => CommentReport::STATUS_PENDING,
            ]);

            $comment->increment('reports_count');

            // Automatically flag comment if reports exceed threshold
            if ($comment->reports_count >= 5 && $comment->status === ProductComment::STATUS_PUBLISHED) {
                $comment->update(['status' => ProductComment::STATUS_FLAGGED]);
            }

            return $report;
        });
    }

    /**
     * Format a single comment model into clean output array.
     */
    public function formatComment(ProductComment $comment, ?User $currentUser = null, array $userReactions = []): array
    {
        $commentId = $comment->id;
        $currentUserReaction = $userReactions[$commentId] ?? ($currentUser ? $comment->reactions()->where('user_id', $currentUser->id)->value('reaction_type') : null);

        $replies = [];
        if ($comment->relationLoaded('replies') && $comment->replies) {
            $replies = $comment->replies->map(function ($reply) use ($currentUser, $userReactions) {
                return $this->formatComment($reply, $currentUser, $userReactions);
            })->all();
        }

        $alternativeData = null;
        if ($comment->alternativeProduct) {
            $alt = $comment->alternativeProduct;
            $alternativeData = [
                'id' => $alt->id,
                'title' => $alt->title,
                'slug' => $alt->slug,
                'base_price' => $alt->base_price,
                'image_url' => $alt->primaryImage?->image_url,
            ];
        }

        return [
            'id' => $comment->id,
            'product_id' => $comment->product_id,
            'parent_id' => $comment->parent_id,
            'comment_type' => $comment->comment_type,
            'content' => $comment->content,
            'attachment_url' => $comment->attachment_url,
            'alternative_product' => $alternativeData,
            'price_suggestion' => $comment->price_suggestion,
            'is_verified_purchase' => (bool) $comment->is_verified_purchase,
            'is_pinned' => (bool) $comment->is_pinned,
            'edited_at' => $comment->edited_at?->toIso8601String(),
            'created_at' => $comment->created_at?->toIso8601String(),
            'user' => [
                'id' => $comment->user->id,
                'name' => $comment->user->name,
                'username' => $comment->user->username,
                'avatar_url' => $comment->user->profile?->avatar_url,
                'locality' => $comment->user->profile?->locality,
            ],
            'reactions_count' => (int) $comment->reactions_count,
            'reaction_counts' => $comment->reactionBreakdown(),
            'user_reaction' => $currentUserReaction,
            'is_own_comment' => $currentUser ? $comment->user_id === $currentUser->id : false,
            'replies_count' => (int) $comment->replies_count,
            'replies' => $replies,
        ];
    }

    /**
     * Dispatch notifications for replies and @mentions.
     */
    protected function dispatchCommentNotifications(ProductComment $comment, User $actor, Product $product, ?ProductComment $parent = null): void
    {
        $notifiedUserIds = [$actor->id];

        // 1. Reply notification to parent author
        if ($parent && ! in_array($parent->user_id, $notifiedUserIds, true)) {
            $this->notificationService->sendToUser(
                $parent->user_id,
                'comment_reply',
                'New reply to your product comment',
                "{$actor->name} replied to your comment on {$product->title}.",
                $comment,
                "/products/{$product->slug}#comment-{$comment->id}"
            );
            $notifiedUserIds[] = $parent->user_id;
        }

        // 2. Mention notifications for @username
        preg_match_all('/@([A-Za-z0-9_]+)/', $comment->content, $matches);
        if (! empty($matches[1])) {
            $usernames = array_unique($matches[1]);
            $mentionedUsers = User::whereIn('username', $usernames)
                ->whereNotIn('id', $notifiedUserIds)
                ->get(['id', 'name', 'username']);

            foreach ($mentionedUsers as $mentioned) {
                $this->notificationService->sendToUser(
                    $mentioned->id,
                    'comment_mention',
                    'You were mentioned in a product discussion',
                    "{$actor->name} mentioned you in a comment on {$product->title}.",
                    $comment,
                    "/products/{$product->slug}#comment-{$comment->id}"
                );
            }
        }
    }

    /**
     * Map reaction type to its database counter column name.
     */
    protected function getColumnForReaction(string $type): string
    {
        return match ($type) {
            CommentReaction::TYPE_LIKE => 'likes_count',
            CommentReaction::TYPE_HELPFUL => 'helpful_count',
            CommentReaction::TYPE_AGREE => 'agree_count',
            CommentReaction::TYPE_NEED_DETAILS => 'need_details_count',
            CommentReaction::TYPE_GOOD_DEAL => 'good_deal_count',
            default => 'likes_count',
        };
    }
}
