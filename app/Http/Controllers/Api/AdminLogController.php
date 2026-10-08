<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminLogController extends Controller
{
    /**
     * Enforce strict administrative log-viewing permission.
     */
    protected function authorizeLogViewer(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Unauthorized. Administrative credentials required to access audit logs.');
        }
    }

    /**
     * Get paginated audit business logs with flexible filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeLogViewer($request);

        $filters = $request->only([
            'category',
            'event',
            'outcome',
            'actor_id',
            'actor_role',
            'subject_type',
            'subject_id',
            'request_id',
            'start_date',
            'end_date',
            'q',
        ]);

        $perPage = min(100, max(5, $request->integer('per_page', 20)));

        $query = BusinessLog::query()
            ->filtered($filters)
            ->with([
                'actorUser:id,name,username,phone',
                'subject',
            ])
            ->latest('occurred_at')
            ->latest('id');

        $logs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->getCollection(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Get a single business log detail by ID.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeLogViewer($request);

        $log = BusinessLog::with([
            'actorUser:id,name,username,phone,email',
            'subject',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $log,
        ]);
    }

    /**
     * Get aggregated KPI summary totals and breakdown for the given filters.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorizeLogViewer($request);

        $filters = $request->only([
            'category',
            'event',
            'outcome',
            'actor_id',
            'actor_role',
            'subject_type',
            'subject_id',
            'request_id',
            'start_date',
            'end_date',
            'q',
        ]);

        $baseQuery = BusinessLog::query()->filtered($filters);

        $totalLogs = (clone $baseQuery)->count();
        $successCount = (clone $baseQuery)->where('outcome', 'success')->count();
        $failureCount = (clone $baseQuery)->where('outcome', 'failure')->count();

        // Group counts by category
        $byCategory = (clone $baseQuery)
            ->select('category', DB::raw('count(*) as count'))
            ->groupBy('category')
            ->pluck('count', 'category')
            ->toArray();

        // Top 5 recorded events
        $topEvents = (clone $baseQuery)
            ->select('event', DB::raw('count(*) as count'))
            ->groupBy('event')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'event' => $row->event,
                'count' => (int) $row->count,
            ]);

        // Recent 5 failure entries
        $recentFailures = (clone $baseQuery)
            ->where('outcome', 'failure')
            ->with('actorUser:id,name,username')
            ->latest('occurred_at')
            ->latest('id')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_logs' => $totalLogs,
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'by_category' => [
                    'authentication' => $byCategory['authentication'] ?? 0,
                    'order' => $byCategory['order'] ?? 0,
                    'product' => $byCategory['product'] ?? 0,
                ],
                'top_events' => $topEvents,
                'recent_failures' => $recentFailures,
            ],
        ]);
    }
}
