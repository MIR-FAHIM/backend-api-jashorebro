<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommentReport;
use App\Models\ProductComment;
use App\Services\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminCommentModerationController extends Controller
{
    public function __construct(
        protected LogService $logService
    ) {}

    /**
     * List comment reports with status and reason filtering.
     */
    public function reports(Request $request): JsonResponse
    {
        $query = CommentReport::query()
            ->with([
                'comment.product:id,title,slug',
                'comment.user:id,name,username',
                'reporter:id,name,username',
                'moderator:id,name,username',
            ]);

        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        } else {
            $query->where('status', CommentReport::STATUS_PENDING);
        }

        if ($reason = $request->input('reason')) {
            $query->where('reason', $reason);
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 50);
        $reports = $query->latest('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $reports->items(),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
                'last_page' => $reports->lastPage(),
            ],
        ]);
    }

    /**
     * Resolve an abuse report and take appropriate moderation action.
     */
    public function resolveReport(Request $request, int $id): JsonResponse
    {
        $report = CommentReport::with('comment')->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', 'in:reviewed,dismissed,action_taken'],
            'action_taken' => ['nullable', 'in:hidden,deleted,dismissed,none'],
            'moderator_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $actionTaken = $validated['action_taken'] ?? ($validated['status'] === 'dismissed' ? 'dismissed' : 'none');

        $report->update([
            'status' => $validated['status'],
            'action_taken' => $actionTaken,
            'moderator_id' => $user->id,
            'moderator_notes' => $validated['moderator_notes'] ?? null,
            'resolved_at' => now(),
        ]);

        // Apply action to the target comment if needed
        if ($report->comment) {
            if ($actionTaken === 'hidden') {
                $report->comment->update(['status' => ProductComment::STATUS_HIDDEN]);
            } elseif ($actionTaken === 'deleted') {
                $report->comment->delete();
            }
        }

        $this->logService->record(
            'comment.report_resolved',
            'success',
            $user,
            $report,
            [
                'action_taken' => $actionTaken,
                'comment_id' => $report->comment_id,
            ],
            "Moderator resolved comment report #{$report->id} with action: {$actionTaken}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Report resolved successfully.',
            'data' => $report,
        ]);
    }

    /**
     * Update comment visibility status directly.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $comment = ProductComment::withTrashed()->findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', 'in:published,hidden,flagged'],
        ]);

        $comment->update(['status' => $validated['status']]);

        $this->logService->record(
            'comment.status_updated',
            'success',
            $request->user(),
            $comment,
            ['status' => $validated['status']],
            "Moderator updated status of comment #{$comment->id} to {$validated['status']}."
        );

        return response()->json([
            'success' => true,
            'message' => "Comment status updated to {$validated['status']}.",
            'data' => $comment,
        ]);
    }

    /**
     * Soft delete a comment as administrator.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $comment = ProductComment::findOrFail($id);

        if ($comment->parent_id) {
            ProductComment::where('id', $comment->parent_id)->decrement('replies_count');
        }

        $comment->delete();

        $this->logService->record(
            'comment.deleted_by_admin',
            'success',
            $request->user(),
            $comment,
            ['comment_id' => $id],
            "Moderator deleted comment #{$id}."
        );

        return response()->json([
            'success' => true,
            'message' => 'Comment deleted by moderation.',
        ]);
    }
}
