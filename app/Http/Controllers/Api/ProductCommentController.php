<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportCommentRequest;
use App\Http\Requests\StoreProductCommentRequest;
use App\Http\Requests\ToggleReactionRequest;
use App\Http\Requests\UpdateProductCommentRequest;
use App\Models\Product;
use App\Models\ProductComment;
use App\Services\ProductCommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductCommentController extends Controller
{
    public function __construct(
        protected ProductCommentService $commentService
    ) {}

    /**
     * Resolve product by ID or slug with availability check.
     */
    protected function resolveProduct(string|int $productId, Request $request): Product
    {
        $product = is_numeric($productId)
            ? Product::findOrFail($productId)
            : Product::where('slug', $productId)->firstOrFail();

        // Enforce product accessibility unless administrative staff
        $user = $request->user();
        $isAdmin = $user && $user->isAdmin();

        if (! $isAdmin && ($product->status !== 'published' || $product->visibility !== 'public' || ! $product->is_active)) {
            abort(404, 'Product not found or unavailable for discussion.');
        }

        return $product;
    }

    /**
     * List public comments for a product post.
     */
    public function index(Request $request, string|int $productId): JsonResponse
    {
        $product = $this->resolveProduct($productId, $request);
        $paginator = $this->commentService->listComments($product, $request->all(), $request->user());

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * Retrieve comment summary metrics for a product.
     */
    public function summary(Request $request, string|int $productId): JsonResponse
    {
        $product = $this->resolveProduct($productId, $request);
        $summary = $this->commentService->getSummary($product, $request->user());

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * Post a new comment or reply.
     */
    public function store(StoreProductCommentRequest $request, string|int $productId): JsonResponse
    {
        $product = $this->resolveProduct($productId, $request);
        $comment = $this->commentService->createComment(
            $product,
            $request->user(),
            $request->validated(),
            $request->file('attachment')
        );

        return response()->json([
            'success' => true,
            'message' => 'Comment posted successfully.',
            'data' => $this->commentService->formatComment($comment, $request->user()),
        ], 201);
    }

    /**
     * View a single comment thread.
     */
    public function show(Request $request, int $commentId): JsonResponse
    {
        $comment = ProductComment::with([
            'user:id,name,username,status',
            'user.profile:id,user_id,avatar_path,locality',
            'alternativeProduct:id,title,slug,base_price,status',
            'alternativeProduct.primaryImage',
            'replies.user:id,name,username',
            'replies.user.profile',
        ])->findOrFail($commentId);

        $user = $request->user();
        if ($comment->status !== ProductComment::STATUS_PUBLISHED && (! $user || ! $user->isAdmin())) {
            abort(404, 'Comment is not available.');
        }

        return response()->json([
            'success' => true,
            'data' => $this->commentService->formatComment($comment, $user),
        ]);
    }

    /**
     * Update comment content by author.
     */
    public function update(UpdateProductCommentRequest $request, int $commentId): JsonResponse
    {
        $comment = ProductComment::findOrFail($commentId);
        $updated = $this->commentService->updateComment($comment, $request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Comment updated successfully.',
            'data' => $this->commentService->formatComment($updated, $request->user()),
        ]);
    }

    /**
     * Delete comment by author or moderator.
     */
    public function destroy(Request $request, int $commentId): JsonResponse
    {
        $comment = ProductComment::findOrFail($commentId);
        $this->commentService->deleteComment($comment, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Comment deleted successfully.',
        ]);
    }

    /**
     * Toggle or update user reaction on a comment.
     */
    public function toggleReaction(ToggleReactionRequest $request, int $commentId): JsonResponse
    {
        $comment = ProductComment::findOrFail($commentId);
        $result = $this->commentService->toggleReaction(
            $comment,
            $request->user(),
            $request->validated('reaction_type')
        );

        return response()->json([
            'success' => true,
            'action' => $result['action'],
            'data' => $result,
        ]);
    }

    /**
     * Report a comment for abuse, spam, or misleading content.
     */
    public function report(ReportCommentRequest $request, int $commentId): JsonResponse
    {
        $comment = ProductComment::findOrFail($commentId);
        $report = $this->commentService->reportComment(
            $comment,
            $request->user(),
            $request->validated('reason'),
            $request->validated('description')
        );

        return response()->json([
            'success' => true,
            'message' => 'Report submitted successfully. Our moderation team will review this comment.',
            'data' => [
                'report_id' => $report->id,
                'comment_id' => $report->comment_id,
                'status' => $report->status,
            ],
        ], 201);
    }
}
