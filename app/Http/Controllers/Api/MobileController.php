<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuratTugas;
use App\Models\SuratPerjalananDinas;
use App\Models\User;
use App\Models\LogSurat;
use App\Models\ActivityLog;
use App\Services\ESignService;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use App\Services\SemestaUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MobileController extends Controller
{
    /**
     * Resolve user from NIP query parameter.
     * All mobile endpoints use ?nip= instead of Bearer token.
     */
    private function resolveUser(Request $request): ?User
    {
        $nip = $request->input('nip');
        if (!$nip) {
            return null;
        }

        $user = User::with('employee', 'role')->where('username', $nip)->first();

        // If user not found locally, auto-create from Semesta API
        if (!$user) {
            $user = SemestaUserService::createFromSemesta($nip);
        }

        return $user;
    }

    private function isCreator(User $user, SuratTugas $suratTugas): bool
    {
        return (int) $suratTugas->created_by === (int) $user->id;
    }

    private function isSigner(User $user, SuratTugas $suratTugas): bool
    {
        return $suratTugas->penandatangan_nip === $user->username;
    }

    private function isSuperAdmin(User $user): bool
    {
        $user->loadMissing('role');
        return ($user->role->slug ?? '') === 'super-admin';
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. List Surat Tugas (GET /api/mobile/surat-tugas?nip=xxx)
    // ──────────────────────────────────────────────────────────────────────
    public function listSuratTugas(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'NIP wajib diisi. / User Tidak Ditemukan, Silahkan login terlebih dahulu di aplikasi e-SPD Web'], 400);
            }

            $perPage = $request->input('per_page', 15);

            $query = SuratTugas::with([
                'klasifikasi',
                'kategori',
                'instance',
                'penandatanganInstance',
                'pemberiPerintahInstance',
                'ppkInstance',
                'createdBy:id,name,username',
                'pegawai',
            ]);

            // Role-based filtering (same logic as web app)
            $roleSlug = $user->role->slug ?? 'staff';
            $isBupati = $roleSlug === 'bupati' || $user->username === '1000';

            if ($roleSlug === 'super-admin') {
                // Super admin sees everything
            } elseif ($isBupati) {
                $query->where('penandatangan_nip', $user->username)
                    ->whereIn('status', ['dikirim', 'ditandatangani', 'ditolak', 'selesai']);
            } elseif ($user->employee && $user->employee->jenis_pegawai == 'kepala') {
                $nip = $user->employee->nip ?? $user->username;
                $userId = $user->id;
                $query->where(function ($q) use ($nip, $userId) {
                    $q->where(function ($sub) use ($nip) {
                        $sub->where('penandatangan_nip', $nip)
                            ->whereIn('status', ['dikirim', 'ditandatangani', 'ditolak', 'selesai']);
                    })
                        ->orWhere('created_by', $userId)
                        ->orWhereHas('pegawai', function ($sub) use ($nip) {
                            $sub->where('nip', $nip)
                                ->whereIn('status', ['ditandatangani', 'selesai']);
                        });
                });
            } else {
                $nip = $user->employee->nip ?? $user->username;
                $userId = $user->id;
                $query->where(function ($q) use ($nip, $userId) {
                    $q->where(function ($sub) use ($nip) {
                        $sub->where('penandatangan_nip', $nip)
                            ->whereIn('status', ['dikirim', 'ditandatangani', 'ditolak', 'selesai']);
                    })
                        ->orWhere('created_by', $userId)
                        ->orWhereHas('pegawai', function ($sub) use ($nip) {
                            $sub->where('nip', $nip)
                                ->whereIn('status', ['ditandatangani', 'selesai']);
                        });
                });
            }

            if ($search = $request->input('search')) {
                $query->search($search);
            }
            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }
            if ($request->has('has_spd')) {
                $query->where('has_spd', filter_var($request->input('has_spd'), FILTER_VALIDATE_BOOLEAN));
            }

            $year = $request->input('year', date('Y'));
            if ($year) {
                $query->whereYear('created_at', $year);
            }
            if ($month = $request->input('month')) {
                $query->whereMonth('created_at', $month);
            }

            $data = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            Log::error('Mobile listSuratTugas error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data surat tugas.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. Detail Surat Tugas (GET /api/mobile/surat-tugas/{id}?nip=xxx)
    // ──────────────────────────────────────────────────────────────────────
    public function detailSuratTugas(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'NIP wajib diisi. / User Tidak Ditemukan, Silahkan login terlebih dahulu di aplikasi e-SPD Web'], 400);
            }

            $suratTugas = SuratTugas::with([
                'klasifikasi',
                'kategori',
                'instance',
                'penandatanganInstance',
                'pemberiPerintahInstance',
                'ppkInstance',
                'createdBy:id,name,username',
                'pegawai',
                'suratPerjalananDinas.suratTugasPegawai',
                'suratPerjalananDinas.laporanPerjalananDinas',
            ])->findOrFail($id);

            $user->loadMissing('role', 'employee');

            $isCreator = $this->isCreator($user, $suratTugas);
            $isSigner = $this->isSigner($user, $suratTugas);
            $isAdmin = $this->isSuperAdmin($user);

            $roleSlug = $user->role->slug ?? 'staff';
            $isBupati = $roleSlug === 'bupati' || $user->username === '1000';
            $nip = $user->employee->nip ?? $user->username;

            $isPegawai = $suratTugas->pegawai->contains('nip', $nip);
            $isPimpinan = $suratTugas->pemberi_perintah_nip === $nip;
            $isSameOPD = $user->instance_id && (int) $user->instance_id === (int) $suratTugas->instance_id;

            $canView = $isAdmin || $isBupati || $isCreator || $isSigner || $isPimpinan || $isPegawai || $isSameOPD;

            if (!$canView) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk melihat surat tugas ini.',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $suratTugas,
                'permissions' => [
                    'can_sign' => $isAdmin || $isSigner,
                    'can_reject' => $isAdmin || $isSigner,
                    'is_signer' => $isSigner,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Surat tugas tidak ditemukan.'], 404);
        } catch (\Exception $e) {
            Log::error('Mobile detailSuratTugas error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memuat data surat tugas.'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. List SPD Saya (GET /api/mobile/spd-saya?nip=xxx)
    // ──────────────────────────────────────────────────────────────────────
    public function listSpdSaya(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'NIP wajib diisi. / User Tidak Ditemukan, Silahkan login terlebih dahulu di aplikasi e-SPD Web'], 400);
            }

            $user->loadMissing('employee');
            $userNip = $user->employee?->nip ?? $user->username;
            $perPage = $request->input('per_page', 15);

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

            if ($search = $request->input('search')) {
                $query->search($search);
            }
            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            $data = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            Log::error('Mobile listSpdSaya error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data SPD Saya.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Detail SPD (GET /api/mobile/spd/{id}?nip=xxx)
    // ──────────────────────────────────────────────────────────────────────
    public function detailSpd(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'NIP wajib diisi. / User Tidak Ditemukan, Silahkan login terlebih dahulu di aplikasi e-SPD Web'], 400);
            }

            $spd = SuratPerjalananDinas::with([
                'suratTugas.klasifikasi',
                'suratTugas.instance',
                'suratTugas.pegawai',
                'suratTugas.createdBy:id,name,username',
                'suratTugasPegawai',
                'laporanPerjalananDinas',
                'pengikut',
            ])->findOrFail($id);

            $user->loadMissing('role', 'employee');

            $roleSlug = $user->role->slug ?? 'staff';
            $isBupati = $roleSlug === 'bupati' || $user->username === '1000';
            $isAdmin = $roleSlug === 'super-admin';

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

            return response()->json(['success' => true, 'data' => $spd]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'SPD tidak ditemukan.'], 404);
        } catch (\Exception $e) {
            Log::error('Mobile detailSpd error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memuat data SPD.'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. TTE / Tandatangani (POST /api/mobile/surat-tugas/{id}/tandatangani)
    //    Body: { nip, passphrase }
    // ──────────────────────────────────────────────────────────────────────
    public function tandatangani(Request $request, string $id): JsonResponse
    {
        try {
            $request->validate([
                'nip' => 'required|string',
                'passphrase' => 'required|string',
            ]);

            $user = User::with('employee', 'role')->where('username', $request->input('nip'))->first();
            if (!$user) {
                $user = SemestaUserService::createFromSemesta($request->input('nip'));
            }
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User dengan NIP tersebut tidak ditemukan.'], 404);
            }

            $suratTugas = SuratTugas::with(['suratPerjalananDinas'])->findOrFail($id);

            // Only signer or super admin can sign
            if (!$this->isSuperAdmin($user) && !$this->isSigner($user, $suratTugas)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menandatangani surat tugas ini.',
                ], 403);
            }

            if ($suratTugas->status !== 'dikirim') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya surat tugas berstatus dikirim yang bisa ditandatangani.',
                ], 422);
            }

            // Get signer NIK
            $signerNik = $user->nik;
            if (!$signerNik) {
                return response()->json([
                    'success' => false,
                    'message' => 'NIK penandatangan belum terdaftar di sistem. Hubungi administrator.',
                ], 422);
            }

            $passphrase = $request->input('passphrase');
            $esignService = new ESignService();

            // lock nik and passphrase =====================================================================
            $signerNik = '1610071606900001'; // hardcoded for testing, replace with $signerNik in production
            $passphrase = 'Juventini@1897'; // hardcoded for testing, replace with $passphrase in production
            // ============================================================================================

            // === Sign Surat Tugas PDF ===
            if (!$suratTugas->file_surat_tugas) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dokumen Surat Tugas belum di-generate. Silakan generate ulang terlebih dahulu.',
                ], 422);
            }

            $stPdfPath = storage_path('app/' . $suratTugas->file_surat_tugas);
            if (!file_exists($stPdfPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File Surat Tugas tidak ditemukan di server. Silakan generate ulang.',
                ], 422);
            }

            // Sign ST
            $stResult = $esignService->signPdf($stPdfPath, $signerNik, $passphrase);
            if (!$stResult['success']) {
                return response()->json(['success' => false, 'message' => $stResult['message']], 422);
            }

            // Save signed ST PDF
            $stSignedDir = storage_path('app/documents/surat-tugas/signed');
            if (!is_dir($stSignedDir)) {
                mkdir($stSignedDir, 0755, true);
            }
            $stSignedFilename = 'ST_SIGNED_' . str_replace(['/', ' '], ['_', '_'], $suratTugas->nomor_surat ?? $suratTugas->id) . '_' . time() . '.pdf';
            $stSignedPath = $stSignedDir . '/' . $stSignedFilename;
            file_put_contents($stSignedPath, $stResult['signed_pdf_content']);

            // === Sign SPD PDFs ===
            $spdSignedPaths = [];
            foreach ($suratTugas->suratPerjalananDinas as $spd) {
                if (!$spd->file_spd) {
                    continue;
                }

                $spdPdfPath = storage_path('app/' . $spd->file_spd);
                if (!file_exists($spdPdfPath)) {
                    Log::warning("Mobile TTE: SPD file not found for signing: {$spd->file_spd}");
                    continue;
                }

                $spdResult = $esignService->signPdf($spdPdfPath, $signerNik, $passphrase);
                if (!$spdResult['success']) {
                    Log::warning("Mobile TTE: Failed to sign SPD {$spd->id}: {$spdResult['message']}");
                    continue;
                }

                $spdSignedDir = storage_path('app/documents/spd/signed');
                if (!is_dir($spdSignedDir)) {
                    mkdir($spdSignedDir, 0755, true);
                }
                $spdSignedFilename = 'SPD_SIGNED_' . str_replace(['/', ' '], ['_', '_'], $spd->nomor_spd ?? $spd->id) . '_' . time() . '.pdf';
                file_put_contents($spdSignedDir . '/' . $spdSignedFilename, $spdResult['signed_pdf_content']);

                $spdSignedPaths[$spd->id] = 'documents/spd/signed/' . $spdSignedFilename;
            }

            // === Update database ===
            DB::transaction(function () use ($suratTugas, $stSignedFilename, $spdSignedPaths) {
                $suratTugas->update([
                    'status' => 'ditandatangani',
                    'file_surat_tugas_signed' => 'documents/surat-tugas/signed/' . $stSignedFilename,
                    'signed_at' => now(),
                ]);

                foreach ($suratTugas->suratPerjalananDinas as $spd) {
                    $updateData = ['status' => 'ditandatangani', 'signed_at' => now()];
                    if (isset($spdSignedPaths[$spd->id])) {
                        $updateData['file_spd_signed'] = $spdSignedPaths[$spd->id];
                    }
                    $spd->update($updateData);
                }
            });

            $suratTugas->refresh();

            // Log
            $this->catatLog($suratTugas->id, 'ditandatangani', $user, $request);
            NotificationService::notifySuratDitandatangani($suratTugas, $user);

            ActivityLogService::log($request, ActivityLog::ACTION_TANDATANGANI_SURAT, "Menandatangani Surat Tugas {$suratTugas->nomor_surat} via Mobile App", [
                'surat_tugas_id' => $suratTugas->id,
                'nomor_surat' => $suratTugas->nomor_surat,
                'source' => 'mobile',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Surat tugas berhasil ditandatangani secara elektronik (TTE).',
                'data' => $suratTugas,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Surat tugas tidak ditemukan.'], 404);
        } catch (\Exception $e) {
            Log::error('Mobile tandatangani error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Gagal menandatangani surat tugas.'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. Tolak / Reject (POST /api/mobile/surat-tugas/{id}/tolak)
    //    Body: { nip, alasan? }
    // ──────────────────────────────────────────────────────────────────────
    public function tolak(Request $request, string $id): JsonResponse
    {
        try {
            $request->validate([
                'nip' => 'required|string',
                'alasan' => 'nullable|string|max:500',
            ]);

            $user = User::with('employee', 'role')->where('username', $request->input('nip'))->first();
            if (!$user) {
                $user = SemestaUserService::createFromSemesta($request->input('nip'));
            }
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User dengan NIP tersebut tidak ditemukan.'], 404);
            }

            $suratTugas = SuratTugas::with(['suratPerjalananDinas'])->findOrFail($id);

            // Only signer or super admin can reject
            if (!$this->isSuperAdmin($user) && !$this->isSigner($user, $suratTugas)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menolak surat tugas ini.',
                ], 403);
            }

            if ($suratTugas->status !== 'dikirim') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya surat tugas berstatus dikirim yang bisa ditolak.',
                ], 422);
            }

            DB::transaction(function () use ($suratTugas, $request) {
                $suratTugas->update([
                    'status' => 'ditolak',
                    'keterangan' => $request->input('alasan') ?? $suratTugas->keterangan,
                ]);

                $suratTugas->suratPerjalananDinas()->update(['status' => 'ditolak']);
            });

            $suratTugas->refresh();

            // Log
            $this->catatLog($suratTugas->id, 'ditolak', $user, $request, $request->input('alasan'));
            NotificationService::notifySuratDitolak($suratTugas, $user, $request->input('alasan'));

            ActivityLogService::log($request, ActivityLog::ACTION_TOLAK_SURAT, "Menolak Surat Tugas {$suratTugas->nomor_surat} via Mobile App", [
                'surat_tugas_id' => $suratTugas->id,
                'nomor_surat' => $suratTugas->nomor_surat,
                'alasan' => $request->input('alasan'),
                'source' => 'mobile',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Surat tugas berhasil ditolak.',
                'data' => $suratTugas,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Surat tugas tidak ditemukan.'], 404);
        } catch (\Exception $e) {
            Log::error('Mobile tolak error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal menolak surat tugas.'], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helper: record log entry
    // ──────────────────────────────────────────────────────────────────────
    private function catatLog(string $suratTugasId, string $aksi, User $user, Request $request, ?string $keterangan = null): void
    {
        try {
            LogSurat::catat(
                $suratTugasId,
                $aksi,
                $user->id,
                $keterangan,
                $request->ip(),
                $request->userAgent()
            );
        } catch (\Exception $e) {
            Log::warning("Mobile: Failed to log surat action '{$aksi}' for ST #{$suratTugasId}: " . $e->getMessage());
        }
    }
}
