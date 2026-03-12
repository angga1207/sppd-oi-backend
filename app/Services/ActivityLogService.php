<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ActivityLogService
{
    /**
     * Log an activity from a request context
     */
    public static function log(
        Request $request,
        string $action,
        string $description,
        ?array $properties = null
    ): ?ActivityLog {
        try {
            $user = $request->user();
            if (!$user) {
                return null;
            }

            return ActivityLog::log(
                $user->id,
                $action,
                $description,
                $properties,
                $request->ip(),
                $request->userAgent()
            );
        } catch (\Exception $e) {
            Log::error('ActivityLogService error: ' . $e->getMessage());
            return null;
        }
    }
}
