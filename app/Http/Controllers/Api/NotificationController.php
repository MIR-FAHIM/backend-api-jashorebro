<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get paginated in-app notifications scoped strictly to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $context = $request->input('context', 'customer');

        // Security: If context=admin requested, verify user actually has admin role
        if ($context === 'admin' && ! $user->isAdmin()) {
            $context = 'customer';
        }

        $filter = $request->input('filter', 'all');
        $perPage = min(50, max(5, $request->integer('per_page', 15)));

        $query = $user->notifications()
            ->forAudience($context)
            ->with('subject');

        if ($filter === 'unread') {
            $query->unread();
        }

        $notifications = $query->paginate($perPage);
        $unreadCount = $user->notifications()->forAudience($context)->unread()->count();

        return response()->json([
            'success' => true,
            'data' => $notifications->getCollection(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread_count' => $unreadCount,
                'context' => $context,
            ],
        ]);
    }

    /**
     * Get unread notification badge count for the authenticated recipient in given context.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $context = $request->input('context', 'customer');

        if ($context === 'admin' && ! $user->isAdmin()) {
            $context = 'customer';
        }

        $unreadCount = $user->notifications()->forAudience($context)->unread()->count();

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $unreadCount,
                'context' => $context,
            ],
        ]);
    }

    /**
     * Show single notification detail and idempotently mark it as read.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var AppNotification $notification */
        $notification = $request->user()->notifications()
            ->with('subject')
            ->where('id', $id)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'data' => $notification,
        ]);
    }

    /**
     * Idempotently mark a specific notification as read.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        /** @var AppNotification $notification */
        $notification = $request->user()->notifications()
            ->where('id', $id)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'data' => $notification->fresh(['subject']),
        ]);
    }

    /**
     * Mark all notifications in the given context as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $context = $request->input('context', 'customer');

        $query = $user->unreadNotifications();

        if ($context && $context !== 'all') {
            $query->where('audience', $context);
        }

        $affected = $query->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => "Marked {$affected} notification(s) as read.",
            'data' => [
                'marked_count' => $affected,
                'context' => $context,
            ],
        ]);
    }
}
