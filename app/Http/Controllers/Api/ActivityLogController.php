<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ActivityLogController extends Controller
{
    /**
     * List activity logs for current user (paginated)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $perPage = $request->input('per_page', 20);

            $query = ActivityLog::where('user_id', $user->id);

            // Filter by action
            if ($action = $request->input('action')) {
                $query->where('action', $action);
            }

            // Filter by date range
            if ($from = $request->input('from')) {
                $query->whereDate('created_at', '>=', $from);
            }
            if ($to = $request->input('to')) {
                $query->whereDate('created_at', '<=', $to);
            }

            $logs = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            // Append label and color
            $logs->getCollection()->transform(function ($log) {
                $log->label = $log->label;
                $log->color = $log->color;
                return $log;
            });

            return response()->json([
                'success' => true,
                'data' => $logs,
            ]);
        } catch (\Exception $e) {
            Log::error('ActivityLog index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat riwayat aktivitas.',
            ], 500);
        }
    }

    /**
     * Get available action types for filter
     */
    public function actionTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ActivityLog::ACTION_LABELS,
        ]);
    }

    /**
     * Get auto-delete setting status
     */
    public function autoDeleteStatus(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => config('app.activity_log_auto_delete', true),
                'retention_days' => config('app.activity_log_retention_days', 365),
            ],
        ]);
    }
}
