<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PejabatPembuatKomitmen;
use App\Services\ActivityLogService;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PpkController extends Controller
{
    /**
     * List all PPK entries (optionally filtered by instance_id)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $user->loadMissing(['role', 'employee']);
            $isSuperAdmin = ($user->role->slug ?? '') === 'super-admin';

            $query = PejabatPembuatKomitmen::with('instance');

            if (!$isSuperAdmin) {
                // Non-super-admin can only see their own OPD
                $query->where('instance_id', $user->instance_id);
            } elseif ($instanceId = $request->input('instance_id')) {
                $query->where('instance_id', $instanceId);
            }

            if ($request->has('is_active')) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            }

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('nama', 'ilike', "%{$search}%")
                      ->orWhere('nip', 'ilike', "%{$search}%")
                      ->orWhere('jabatan', 'ilike', "%{$search}%");
                });
            }

            $data = $query->orderBy('instance_id')->orderBy('nama')->get();

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('PPK index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data PPK.',
            ], 500);
        }
    }

    /**
     * Get all active PPK for a specific OPD
     */
    public function getByInstance(Request $request, int $instanceId): JsonResponse
    {
        try {
            $ppkList = PejabatPembuatKomitmen::where('instance_id', $instanceId)
                ->where('is_active', true)
                ->orderBy('nama')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $ppkList,
            ]);
        } catch (\Exception $e) {
            Log::error('PPK getByInstance error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data PPK.',
            ], 500);
        }
    }

    /**
     * Create or update PPK for an OPD
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['role', 'employee']);

        $isSuperAdmin = ($user->role->slug ?? '') === 'super-admin';
        $isKepegawaian = $user->employee && $user->employee->is_kepegawaian;

        if (!$isSuperAdmin && !$isKepegawaian) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengelola PPK.',
            ], 403);
        }

        // Non-super-admin can only create PPK for their own OPD
        if (!$isSuperAdmin) {
            $request->merge(['instance_id' => $user->instance_id]);
        }

        $request->validate([
            'instance_id' => 'required|exists:instances,id',
            'nama' => 'required|string|max:255',
            'nip' => 'required|string|max:50',
            'jabatan' => 'nullable|string|max:255',
            'pangkat' => 'nullable|string|max:255',
            'golongan' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        try {
            $ppk = PejabatPembuatKomitmen::create([
                'instance_id' => $request->instance_id,
                'nama' => $request->nama,
                'nip' => $request->nip,
                'jabatan' => $request->jabatan,
                'pangkat' => $request->pangkat,
                'golongan' => $request->golongan,
                'is_active' => $request->is_active ?? true,
            ]);

            $ppk->load('instance');

            ActivityLogService::log($request, ActivityLog::ACTION_CREATE_SURAT, "Menambahkan PPK baru: {$ppk->nama} untuk OPD {$ppk->instance->name}", [
                'ppk_id' => $ppk->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'PPK berhasil ditambahkan.',
                'data' => $ppk,
            ], 201);
        } catch (\Exception $e) {
            Log::error('PPK store error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan PPK.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update existing PPK
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['role', 'employee']);

        $isSuperAdmin = ($user->role->slug ?? '') === 'super-admin';
        $isKepegawaian = $user->employee && $user->employee->is_kepegawaian;

        if (!$isSuperAdmin && !$isKepegawaian) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengelola PPK.',
            ], 403);
        }

        $ppk = PejabatPembuatKomitmen::findOrFail($id);

        // Non-super-admin can only update PPK for their own OPD
        if (!$isSuperAdmin && $ppk->instance_id !== $user->instance_id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda hanya dapat mengelola PPK untuk OPD Anda sendiri.',
            ], 403);
        }

        // Non-super-admin cannot change instance_id
        if (!$isSuperAdmin) {
            $request->merge(['instance_id' => $user->instance_id]);
        }

        $request->validate([
            'instance_id' => 'required|exists:instances,id',
            'nama' => 'required|string|max:255',
            'nip' => 'required|string|max:50',
            'jabatan' => 'nullable|string|max:255',
            'pangkat' => 'nullable|string|max:255',
            'golongan' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        try {
            // If activating this PPK, deactivate others for the same instance
            if ($request->is_active && !$ppk->is_active) {
                PejabatPembuatKomitmen::where('instance_id', $request->instance_id)
                    ->where('id', '!=', $id)
                    ->update(['is_active' => false]);
            }

            $ppk->update([
                'instance_id' => $request->instance_id,
                'nama' => $request->nama,
                'nip' => $request->nip,
                'jabatan' => $request->jabatan,
                'pangkat' => $request->pangkat,
                'golongan' => $request->golongan,
                'is_active' => $request->is_active ?? $ppk->is_active,
            ]);

            $ppk->load('instance');

            return response()->json([
                'success' => true,
                'message' => 'PPK berhasil diperbarui.',
                'data' => $ppk,
            ]);
        } catch (\Exception $e) {
            Log::error('PPK update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui PPK.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Delete PPK
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['role', 'employee']);

        $isSuperAdmin = ($user->role->slug ?? '') === 'super-admin';
        $isKepegawaian = $user->employee && $user->employee->is_kepegawaian;

        if (!$isSuperAdmin && !$isKepegawaian) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengelola PPK.',
            ], 403);
        }

        try {
            $ppk = PejabatPembuatKomitmen::findOrFail($id);

            // Non-super-admin can only delete PPK for their own OPD
            if (!$isSuperAdmin && $ppk->instance_id !== $user->instance_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda hanya dapat mengelola PPK untuk OPD Anda sendiri.',
                ], 403);
            }

            $ppk->delete();

            return response()->json([
                'success' => true,
                'message' => 'PPK berhasil dihapus.',
            ]);
        } catch (\Exception $e) {
            Log::error('PPK destroy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus PPK.',
            ], 500);
        }
    }

    /**
     * Set active PPK for an instance (deactivate others)
     */
    public function setActive(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing(['role', 'employee']);

        $isSuperAdmin = ($user->role->slug ?? '') === 'super-admin';
        $isKepegawaian = $user->employee && $user->employee->is_kepegawaian;

        if (!$isSuperAdmin && !$isKepegawaian) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengelola PPK.',
            ], 403);
        }

        try {
            $ppk = PejabatPembuatKomitmen::findOrFail($id);

            // Non-super-admin can only set active PPK for their own OPD
            if (!$isSuperAdmin && $ppk->instance_id !== $user->instance_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda hanya dapat mengelola PPK untuk OPD Anda sendiri.',
                ], 403);
            }

            // Deactivate all PPK for the same instance
            PejabatPembuatKomitmen::where('instance_id', $ppk->instance_id)
                ->update(['is_active' => false]);

            // Activate the selected one
            $ppk->update(['is_active' => true]);
            $ppk->load('instance');

            return response()->json([
                'success' => true,
                'message' => 'PPK aktif berhasil diubah.',
                'data' => $ppk,
            ]);
        } catch (\Exception $e) {
            Log::error('PPK setActive error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengubah PPK aktif.',
            ], 500);
        }
    }
}
