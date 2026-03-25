<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncEmployeesJob;
use App\Models\Employee;
use App\Models\EmployeeSyncLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    /**
     * List all employees with search, filter, and pagination.
     * Super Admin only.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $query = Employee::with('instance:id,name,alias')
            ->search($request->input('search'))
            ->when($request->input('instance_id'), fn($q, $id) => $q->where('instance_id', $id))
            ->when($request->input('jenis_pegawai'), fn($q, $jenis) => $q->where('jenis_pegawai', $jenis))
            ->orderBy($request->input('sort_by', 'nama_lengkap'), $request->input('sort_dir', 'asc'));

        $perPage = min((int) $request->input('per_page', 20), 100);
        $employees = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $employees->items(),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
            ],
        ]);
    }

    /**
     * Get sync logs.
     */
    public function syncLogs(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $logs = EmployeeSyncLog::with('instance:id,name,alias')
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Trigger manual sync.
     */
    public function triggerSync(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $instanceId = $request->input('instance_id') ? (int) $request->input('instance_id') : null;

        SyncEmployeesJob::dispatch($instanceId);

        return response()->json([
            'success' => true,
            'message' => 'Sync job telah dijalankan. Silakan cek log sync untuk progress.',
        ]);
    }

    /**
     * Employee stats for admin dashboard.
     */
    public function stats(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin($request)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $totalEmployees = Employee::count();
        $totalInstances = Employee::distinct('instance_id')->count('instance_id');
        $lastSync = EmployeeSyncLog::where('status', 'success')->latest()->first();

        return response()->json([
            'success' => true,
            'data' => [
                'total_employees' => $totalEmployees,
                'total_instances' => $totalInstances,
                'last_sync_at' => $lastSync?->finished_at,
                'last_sync_status' => $lastSync?->status,
            ],
        ]);
    }

    private function isSuperAdmin(Request $request): bool
    {
        $user = $request->user();
        $user->loadMissing('role');
        return ($user->role->slug ?? '') === 'super-admin';
    }
}
