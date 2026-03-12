<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuratPerjalananDinas;
use App\Models\LaporanPerjalananDinas;
use App\Models\SpdPengikut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SpdController extends Controller
{
    /**
     * List SPDs — filtered by user instance, paginated
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $perPage = $request->input('per_page', 15);

            $query = SuratPerjalananDinas::with([
                'suratTugas:id,nomor_surat,has_spd,lokasi_tujuan,tanggal_berangkat,tanggal_kembali,status,instance_id',
                'suratTugas.instance:id,name,alias',
                'suratTugasPegawai',
                'laporanPerjalananDinas',
                'pengikut',
            ])
            ->whereHas('suratTugas', function ($q) use ($user) {
                $q->where('instance_id', $user->instance_id);
            });

            // Search
            if ($search = $request->input('search')) {
                $query->search($search);
            }

            // Filter by status
            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            $data = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('SPD index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data SPD.',
            ], 500);
        }
    }

    /**
     * Show single SPD with full detail
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $spd = SuratPerjalananDinas::with([
                'suratTugas.klasifikasi',
                'suratTugas.instance',
                'suratTugas.pegawai',
                'suratTugas.createdBy:id,name,username',
                'suratTugasPegawai',
                'laporanPerjalananDinas',
                'pengikut',
            ])->findOrFail($id);

            // Authorization check
            $user = $request->user();
            $user->loadMissing('role', 'employee');

            $roleSlug = $user->role->slug ?? 'staff';
            $isBupati = $roleSlug === 'bupati' || $user->username === '1000';
            $isAdmin = ($roleSlug === 'super-admin');

            if (!$isAdmin && !$isBupati) {
                $st = $spd->suratTugas;
                $nip = $user->employee->nip ?? $user->username;

                $isCreator = $st && (int) $st->created_by === (int) $user->id;
                $isSigner = $st && $st->penandatangan_nip === $nip;
                $isPimpinan = $st && $st->pemberi_perintah_nip === $nip;
                $isPegawai = $st && $st->pegawai->contains('nip', $nip);
                $isSameOPD = $st && $user->instance_id && (int) $user->instance_id === (int) $st->instance_id;

                if (!$isCreator && !$isSigner && !$isPimpinan && !$isPegawai && !$isSameOPD) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses untuk melihat SPD ini.',
                    ], 403);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $spd,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'SPD tidak ditemukan.',
            ], 404);
        }
    }

    /**
     * SPD Saya — only SPDs where the logged-in user is the assigned pegawai (matched by NIP)
     */
    public function spdSaya(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $perPage = $request->input('per_page', 15);

            // Get the logged-in user's NIP via employee relationship
            $user->loadMissing('employee');
            $userNip = $user->employee?->nip ?? null;

            if (!$userNip) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'data' => [],
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                    ],
                ]);
            }

            $query = SuratPerjalananDinas::with([
                'suratTugas:id,nomor_surat,has_spd,lokasi_tujuan,tanggal_berangkat,tanggal_kembali,status,instance_id,created_by',
                'suratTugas.instance:id,name,alias',
                'suratTugas.createdBy:id,name,username',
                'suratTugasPegawai',
                'laporanPerjalananDinas',
                'pengikut',
            ])
            ->whereHas('suratTugasPegawai', function ($q) use ($userNip) {
                $q->where('nip', $userNip);
            });

            // Search
            if ($search = $request->input('search')) {
                $query->search($search);
            }

            // Filter by status
            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            $data = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('SPD Saya error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data SPD Saya.',
            ], 500);
        }
    }

    /**
     * Submit laporan perjalanan dinas
     */
    public function submitLaporan(Request $request, int $spdId): JsonResponse
    {
        try {
            $request->validate([
                'laporan' => 'required|string',
                'lampiran' => 'nullable|array',
                'lampiran.*' => 'file|max:10240', // Max 10MB per file
            ]);

            $spd = SuratPerjalananDinas::findOrFail($spdId);

            if (!in_array($spd->status, ['ditandatangani', 'selesai'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Laporan hanya bisa dibuat untuk SPD yang sudah ditandatangani.',
                ], 422);
            }

            // Handle file uploads
            $lampiranPaths = [];
            if ($request->hasFile('lampiran')) {
                foreach ($request->file('lampiran') as $file) {
                    $path = $file->store("laporan/spd-{$spdId}", 'public');
                    $lampiranPaths[] = $path;
                }
            }

            $laporan = LaporanPerjalananDinas::updateOrCreate(
                ['spd_id' => $spdId],
                [
                    'laporan' => $request->laporan,
                    'lampiran' => !empty($lampiranPaths) ? $lampiranPaths : null,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Laporan perjalanan dinas berhasil disimpan.',
                'data' => $laporan,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'SPD tidak ditemukan.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('SPD laporan error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan laporan.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update SPD (nomor_spd and/or tingkat_biaya)
     * Only the creator of the parent surat tugas can update
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'nomor_spd' => 'sometimes|required|string|max:255',
                'tingkat_biaya' => 'sometimes|required|string|in:A,B,C,D,E,F,G',
            ]);

            $spd = SuratPerjalananDinas::with('suratTugas')->findOrFail($id);

            // Only the creator of the surat tugas can edit the SPD
            $user = $request->user();
            if ($spd->suratTugas && $spd->suratTugas->created_by !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya pembuat surat tugas yang dapat mengubah SPD ini.',
                ], 403);
            }

            // Only allow editing when the parent surat tugas is in draft
            if ($spd->suratTugas && $spd->suratTugas->status !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'SPD hanya bisa diubah saat surat tugas masih berstatus draft.',
                ], 422);
            }

            $updateData = [];
            if ($request->has('nomor_spd')) {
                $updateData['nomor_spd'] = $request->nomor_spd;
            }
            if ($request->has('tingkat_biaya')) {
                $updateData['tingkat_biaya'] = $request->tingkat_biaya;
            }

            $spd->update($updateData);

            $spd->load(['suratTugas.klasifikasi', 'suratTugas.instance', 'suratTugasPegawai', 'laporanPerjalananDinas', 'pengikut']);

            return response()->json([
                'success' => true,
                'message' => 'SPD berhasil diperbarui.',
                'data' => $spd,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'SPD tidak ditemukan.',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('SPD update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui SPD.',
            ], 500);
        }
    }

    /**
     * Download SPD document (PDF or DOCX)
     * Only allowed when status is 'ditandatangani' or 'selesai', unless secret key is provided
     */
    public function download(Request $request, int $id)
    {
        try {
            $spd = SuratPerjalananDinas::with('suratTugas')->findOrFail($id);

            // Block download if not yet signed — no exceptions
            $allowedStatuses = ['ditandatangani', 'selesai'];
            $currentStatus = $spd->suratTugas ? $spd->suratTugas->status : $spd->status;

            if (!in_array($currentStatus, $allowedStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dokumen SPD hanya bisa diunduh setelah ditandatangani.',
                ], 403);
            }

            // Only serve the signed PDF
            $fileField = $spd->file_spd_signed;
            if (!$fileField) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dokumen SPD yang telah ditandatangani belum tersedia.',
                ], 404);
            }

            $filePath = storage_path('app/' . $fileField);

            if (!file_exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File dokumen tidak ditemukan di server.',
                ], 404);
            }

            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            $filename = 'SPD_' . str_replace(['/', ' '], ['_', '_'], $spd->nomor_spd ?? $spd->id) . '.' . $extension;

            $contentType = $extension === 'pdf'
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

            return response()->download($filePath, $filename, [
                'Content-Type' => $contentType,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'SPD tidak ditemukan.',
            ], 404);
        }
    }

    /**
     * Sync pengikut (keluarga pegawai) for an SPD
     * Replaces all existing pengikut with the provided list
     */
    public function syncPengikut(Request $request, int $spdId): JsonResponse
    {
        try {
            $request->validate([
                'pengikut' => 'present|array',
                'pengikut.*.nama' => 'required|string|max:255',
                'pengikut.*.tanggal_lahir' => 'nullable|date',
                'pengikut.*.keterangan' => 'nullable|string|max:500',
            ]);

            $spd = SuratPerjalananDinas::with('suratTugas')->findOrFail($spdId);

            // Only allow editing when the parent surat tugas is in draft
            if ($spd->suratTugas && $spd->suratTugas->status !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengikut hanya bisa diubah saat surat tugas masih berstatus draft.',
                ], 422);
            }

            DB::transaction(function () use ($spd, $request) {
                // Delete all existing pengikut
                $spd->pengikut()->delete();

                // Create new pengikut
                foreach ($request->pengikut as $p) {
                    SpdPengikut::create([
                        'spd_id' => $spd->id,
                        'nama' => $p['nama'],
                        'tanggal_lahir' => $p['tanggal_lahir'] ?? null,
                        'keterangan' => $p['keterangan'] ?? null,
                    ]);
                }
            });

            $spd->load('pengikut');

            return response()->json([
                'success' => true,
                'message' => 'Pengikut berhasil diperbarui.',
                'data' => $spd->pengikut,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'SPD tidak ditemukan.',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('SPD syncPengikut error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui pengikut.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get tingkat biaya options
     */
    public function tingkatOptions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => SuratPerjalananDinas::TINGKAT_OPTIONS,
        ]);
    }
}
