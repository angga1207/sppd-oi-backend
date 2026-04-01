<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuratTugas;
use App\Models\SuratTugasPegawai;
use App\Models\SuratPerjalananDinas;
use App\Models\Employee;
use App\Models\LogSurat;
use App\Services\DocumentService;
use App\Services\NotificationService;
use App\Services\ActivityLogService;
use App\Models\ActivityLog;
use App\Traits\ConvertHtmlListToText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SuratTugasController extends Controller
{
    use ConvertHtmlListToText;

    protected DocumentService $documentService;

    public function __construct(DocumentService $documentService)
    {
        $this->documentService = $documentService;
    }

    /**
     * Check if user is the creator of the surat tugas
     */
    private function isCreator($user, SuratTugas $suratTugas): bool
    {
        return (int) $suratTugas->created_by === (int) $user->id;
    }

    /**
     * Check if user is the signer (penandatangan) of the surat tugas
     */
    private function isSigner($user, SuratTugas $suratTugas): bool
    {
        return $suratTugas->penandatangan_nip === $user->username;
    }

    /**
     * Check if user is super admin
     */
    private function isSuperAdmin($user): bool
    {
        $user->loadMissing('role');
        return ($user->role->slug ?? '') === 'super-admin';
    }

    /**
     * Check if user is allowed to modify (creator, signer, or super admin)
     */
    private function canModify($user, SuratTugas $suratTugas): bool
    {
        return $this->isSuperAdmin($user) || $this->isCreator($user, $suratTugas) || $this->isSigner($user, $suratTugas);
    }

    /**
     * List surat tugas — paginated, searchable, filtered by user role
     *
     * - Super Admin: semua surat
     * - Bupati (username 1000): surat yang ditandatangani oleh Bupati
     * - Kepala OPD (jenis_pegawai == 'kepala'): surat ditandatangani + SPD atas nama diri
     * - Staff: surat yang dibuat sendiri + SPD atas nama diri
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $user->load('employee', 'role');
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

            // Role-based filtering
            $roleSLug = $user->role->slug ?? 'staff';
            $isBupati = $roleSLug === 'bupati' || $user->username === '1000';

            if ($roleSLug === 'super-admin') {
                // Super admin sees everything — no additional filter
            } elseif ($isBupati) {
                // Bupati: surat yang dia tandatangani, tapi jika bukan pembuat maka hanya yang sudah dikirim/ditandatangani/ditolak/selesai
                $userId = $user->id;
                $query->where('penandatangan_nip', $user->username)
                    ->whereIn('status', ['dikirim', 'ditandatangani', 'ditolak', 'selesai']);
            } elseif ($user->employee && $user->employee->jenis_pegawai == 'kepala') {
                // Kepala OPD: surat yang dia tandatangani (non-draft jika bukan pembuat) + surat yang ada SPD atas nama dirinya
                $nip = $user->employee->nip ?? $user->username;
                $userId = $user->id;
                $query->where(function ($q) use ($nip, $userId) {
                    // Sebagai penandatangan: tampilkan hanya yang sudah dikirim jika bukan pembuat
                    $q->where(function ($sub) use ($nip, $userId) {
                        $sub->where('penandatangan_nip', $nip)
                            ->whereIn('status', ['dikirim', 'ditandatangani', 'ditolak', 'selesai']);
                    })
                        // Sebagai pembuat: tampilkan semua status
                        ->orWhere('created_by', $userId)
                        // Sebagai pegawai yang ditugaskan
                        ->orWhereHas('pegawai', function ($sub) use ($nip) {
                            $sub->where('nip', $nip)
                                ->whereIn('status', ['ditandatangani', 'selesai']);
                        });
                });
            } else {
                // // Staff: surat yang dia buat + surat yang ada SPD atas nama dirinya
                // $nip = $user->employee->nip ?? $user->username;
                // $userId = $user->id;
                // $query->where(function ($q) use ($nip, $userId) {
                //     $q->where('created_by', $userId)
                //         ->orWhereHas('pegawai', function ($sub) use ($nip) {
                //             $sub->where('nip', $nip)
                //                 ->whereIn('status', ['ditandatangani', 'selesai']);
                //         });
                // });

                // Kepala OPD: surat yang dia tandatangani (non-draft jika bukan pembuat) + surat yang ada SPD atas nama dirinya
                $nip = $user->employee->nip ?? $user->username;
                $userId = $user->id;
                $query->where(function ($q) use ($nip, $userId) {
                    // Sebagai penandatangan: tampilkan hanya yang sudah dikirim jika bukan pembuat
                    $q->where(function ($sub) use ($nip, $userId) {
                        $sub->where('penandatangan_nip', $nip)
                            ->whereIn('status', ['dikirim', 'ditandatangani', 'ditolak', 'selesai']);
                    })
                        // Sebagai pembuat: tampilkan semua status
                        ->orWhere('created_by', $userId)
                        // Sebagai pegawai yang ditugaskan
                        ->orWhereHas('pegawai', function ($sub) use ($nip) {
                            $sub->where('nip', $nip)
                                ->whereIn('status', ['ditandatangani', 'selesai']);
                        });
                });
            }

            // Search
            if ($search = $request->input('search')) {
                $query->search($search);
            }

            // Filter by status
            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            // Filter by has_spd
            if ($request->has('has_spd')) {
                $query->where('has_spd', filter_var($request->input('has_spd'), FILTER_VALIDATE_BOOLEAN));
            }

            // Filter by year (default: current year)
            $year = $request->input('year', date('Y'));
            if ($year) {
                $query->whereYear('created_at', $year);
            }

            // Filter by month
            if ($month = $request->input('month')) {
                $query->whereMonth('created_at', $month);
            }

            $data = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('SuratTugas index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data surat tugas.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Show single surat tugas with all relations
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
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

            $user = $request->user();
            $user->loadMissing('role', 'employee');

            $isCreator = $this->isCreator($user, $suratTugas);
            $isSigner = $this->isSigner($user, $suratTugas);
            $isAdmin = $this->isSuperAdmin($user);

            $roleSlug = $user->role->slug ?? 'staff';
            $isBupati = $roleSlug === 'bupati' || $user->username === '1000';
            $nip = $user->employee->nip ?? $user->username;

            // Check if user is an assigned pegawai on this surat
            $isPegawai = $suratTugas->pegawai->contains('nip', $nip);

            // Check if user is pimpinan / pemberi perintah
            $isPimpinan = $suratTugas->pemberi_perintah_nip === $nip;

            // Check if user belongs to same OPD
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
                    'can_edit' => $isAdmin || $isCreator,
                    'can_send' => $isAdmin || $isCreator,
                    'can_sign' => $isAdmin || $isSigner,
                    'can_reject' => $isAdmin || $isSigner,
                    'can_revise' => $isAdmin || $isCreator,
                    'can_complete' => $isAdmin || $isCreator || $isSigner,
                    'can_delete' => $isAdmin || $isCreator,
                    'is_creator' => $isCreator,
                    'is_signer' => $isSigner,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Surat tugas tidak ditemukan.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('SuratTugas show error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data surat tugas.',
            ], 500);
        }
    }

    /**
     * Create new surat tugas (draft)
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('role');

        // Always use the user's own instance_id
        $effectiveInstanceId = $user->instance_id;

        $request->validate([
            'klasifikasi_id' => 'nullable|exists:klasifikasi,id',
            'kategori_id' => 'nullable|exists:kategori_surat,id',
            'pemberi_perintah_nama' => 'nullable|string|max:255',
            'pemberi_perintah_nip' => 'nullable|string|max:50',
            'pemberi_perintah_jabatan' => 'nullable|string|max:255',
            'pemberi_perintah_pangkat' => 'nullable|string|max:255',
            'pemberi_perintah_golongan' => 'nullable|string|max:255',
            'pemberi_perintah_instance_id' => 'nullable|exists:instances,id',
            'pemberi_perintah_jenis_pegawai' => 'nullable|string|max:50',
            'dasar' => 'nullable|string',
            'untuk' => 'nullable|string',
            'has_spd' => 'boolean',
            'penandatangan_nama' => 'nullable|string|max:255',
            'penandatangan_nip' => 'nullable|string|max:50',
            'penandatangan_jabatan' => 'nullable|string|max:255',
            'penandatangan_instance_id' => 'nullable|exists:instances,id',
            'ppk_nama' => 'nullable|string|max:255',
            'ppk_nip' => 'nullable|string|max:50',
            'ppk_jabatan' => 'nullable|string|max:255',
            'ppk_pangkat' => 'nullable|string|max:255',
            'ppk_golongan' => 'nullable|string|max:255',
            'ppk_instance_id' => 'nullable|exists:instances,id',
            'jenis_perjalanan' => 'nullable|in:luar_kabupaten,dalam_kabupaten',
            'tujuan_provinsi_id' => 'nullable|string',
            'tujuan_provinsi_nama' => 'nullable|string|max:255',
            'tujuan_kabupaten_id' => 'nullable|string',
            'tujuan_kabupaten_nama' => 'nullable|string|max:255',
            'tujuan_kecamatan_id' => 'nullable|string',
            'tujuan_kecamatan_nama' => 'nullable|string|max:255',
            'lokasi_tujuan' => 'nullable|string|max:500',
            'tanggal_berangkat' => 'nullable|date',
            'lama_perjalanan' => 'nullable|integer|min:1',
            'tanggal_kembali' => 'nullable|date|after_or_equal:tanggal_berangkat',
            'tempat_dikeluarkan' => 'nullable|string|max:255',
            'tanggal_dikeluarkan' => 'nullable|date',
            'alat_angkut' => 'nullable|string|max:255',
            'biaya' => 'nullable|numeric|min:0',
            'sub_kegiatan_kode' => 'nullable|string|max:255',
            'sub_kegiatan_nama' => 'nullable|string|max:500',
            'kode_rekening' => 'nullable|string|max:255',
            'uraian_rekening' => 'nullable|string|max:500',
            'keterangan' => 'nullable|string',
            'nomor_surat' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('surat_tugas', 'nomor_surat')
                    ->where('instance_id', $effectiveInstanceId)
                    ->whereNull('deleted_at'),
            ],
            'pegawai' => 'required|array|min:1',
            'pegawai.*.semesta_pegawai_id' => 'nullable|integer',
            'pegawai.*.nip' => 'required|string',
            'pegawai.*.nama_lengkap' => 'required|string',
            'pegawai.*.jabatan' => 'nullable|string',
            'pegawai.*.pangkat' => 'nullable|string',
            'pegawai.*.golongan' => 'nullable|string',
            'pegawai.*.eselon' => 'nullable|string',
            'pegawai.*.id_skpd' => 'nullable|integer',
            'pegawai.*.nama_skpd' => 'nullable|string',
            'pegawai.*.id_jabatan' => 'nullable|integer',
            'pegawai.*.jenis_pegawai' => 'nullable|string|max:50',
        ]);

        try {
            $suratTugas = DB::transaction(function () use ($request, $user, $effectiveInstanceId) {
                // Create Surat Tugas
                $st = SuratTugas::create([
                    'nomor_surat' => $request->nomor_surat,
                    'klasifikasi_id' => $request->klasifikasi_id,
                    'kategori_id' => $request->kategori_id,
                    'pemberi_perintah_nama' => $request->pemberi_perintah_nama,
                    'pemberi_perintah_nip' => $request->pemberi_perintah_nip,
                    'pemberi_perintah_jabatan' => $request->pemberi_perintah_jabatan,
                    'pemberi_perintah_pangkat' => $request->pemberi_perintah_pangkat,
                    'pemberi_perintah_golongan' => $request->pemberi_perintah_golongan,
                    'pemberi_perintah_instance_id' => $request->pemberi_perintah_instance_id,
                    'dasar' => $request->dasar,
                    'untuk' => $request->untuk,
                    'has_spd' => $request->has_spd ?? false,
                    'penandatangan_nama' => $request->penandatangan_nama,
                    'penandatangan_nip' => $request->penandatangan_nip,
                    'penandatangan_jabatan' => $request->penandatangan_jabatan,
                    'penandatangan_instance_id' => $request->penandatangan_instance_id,
                    'ppk_nama' => $request->ppk_nama,
                    'ppk_nip' => $request->ppk_nip,
                    'ppk_jabatan' => $request->ppk_jabatan,
                    'ppk_pangkat' => $request->ppk_pangkat,
                    'ppk_golongan' => $request->ppk_golongan,
                    'ppk_instance_id' => $request->ppk_instance_id,
                    'instance_id' => $effectiveInstanceId,
                    'jenis_perjalanan' => $request->jenis_perjalanan,
                    'tujuan_provinsi_id' => $request->tujuan_provinsi_id,
                    'tujuan_provinsi_nama' => $request->tujuan_provinsi_nama,
                    'tujuan_kabupaten_id' => $request->tujuan_kabupaten_id,
                    'tujuan_kabupaten_nama' => $request->tujuan_kabupaten_nama,
                    'tujuan_kecamatan_id' => $request->tujuan_kecamatan_id,
                    'tujuan_kecamatan_nama' => $request->tujuan_kecamatan_nama,
                    'lokasi_tujuan' => $request->lokasi_tujuan,
                    'tanggal_berangkat' => $request->tanggal_berangkat,
                    'lama_perjalanan' => $request->lama_perjalanan,
                    'tanggal_kembali' => $request->tanggal_kembali,
                    'tempat_dikeluarkan' => $request->tempat_dikeluarkan,
                    'tanggal_dikeluarkan' => $request->tanggal_dikeluarkan,
                    'alat_angkut' => $request->alat_angkut,
                    'biaya' => $request->biaya,
                    'sub_kegiatan_kode' => $request->sub_kegiatan_kode,
                    'sub_kegiatan_nama' => $request->sub_kegiatan_nama,
                    'kode_rekening' => $request->kode_rekening,
                    'uraian_rekening' => $request->uraian_rekening,
                    'keterangan' => $request->keterangan,
                    'status' => 'draft',
                    'created_by' => $user->id,
                ]);

                // Create pegawai entries
                foreach ($request->pegawai as $p) {
                    $stp = SuratTugasPegawai::create([
                        'surat_tugas_id' => $st->id,
                        'semesta_pegawai_id' => $p['semesta_pegawai_id'] ?? null,
                        'nip' => $p['nip'],
                        'nama_lengkap' => $p['nama_lengkap'],
                        'jabatan' => $p['jabatan'] ?? null,
                        'pangkat' => $p['pangkat'] ?? null,
                        'golongan' => $p['golongan'] ?? null,
                        'eselon' => $p['eselon'] ?? null,
                        'id_skpd' => $p['id_skpd'] ?? null,
                        'nama_skpd' => $p['nama_skpd'] ?? null,
                        'id_jabatan' => $p['id_jabatan'] ?? null,
                    ]);

                    // Auto-create SPD per pegawai if has_spd
                    if ($st->has_spd) {
                        // Lookup kepala_skpd from Employee table
                        $employee = Employee::where('nip', $p['nip'])->first();
                        $tingkatData = [
                            'eselon' => $p['eselon'] ?? '',
                            'golongan' => $p['golongan'] ?? '',
                            'kepala_skpd' => $employee->kepala_skpd ?? null,
                        ];

                        SuratPerjalananDinas::create([
                            'surat_tugas_id' => $st->id,
                            'surat_tugas_pegawai_id' => $stp->id,
                            'tingkat_biaya' => SuratPerjalananDinas::detectCostLevel($tingkatData),
                            'status' => 'draft',
                        ]);
                    }
                }

                return $st;
            });

            $suratTugas->load(['klasifikasi', 'kategori', 'instance', 'pegawai', 'suratPerjalananDinas.suratTugasPegawai']);

            // Log creation
            $this->catatLog($request, $suratTugas->id, 'dibuat');

            // Activity Log
            ActivityLogService::log($request, ActivityLog::ACTION_CREATE_SURAT, "Membuat Surat Tugas baru", [
                'surat_tugas_id' => $suratTugas->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Surat tugas berhasil dibuat.',
                'data' => $suratTugas,
            ], 201);
        } catch (\Exception $e) {
            Log::error('SuratTugas store error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat surat tugas.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update surat tugas (only if draft)
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $suratTugas = SuratTugas::findOrFail($id);
            $user = $request->user();

            // Only creator or super admin can edit
            if (!$this->isSuperAdmin($user) && !$this->isCreator($user, $suratTugas)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk mengedit surat tugas ini.',
                ], 403);
            }

            if (!$suratTugas->canEdit()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Surat tugas hanya bisa diedit saat status draft.',
                ], 422);
            }

            $request->validate([
                'klasifikasi_id' => 'nullable|exists:klasifikasi,id',
                'kategori_id' => 'nullable|exists:kategori_surat,id',
                'pemberi_perintah_nama' => 'nullable|string|max:255',
                'pemberi_perintah_nip' => 'nullable|string|max:50',
                'pemberi_perintah_jabatan' => 'nullable|string|max:255',
                'pemberi_perintah_pangkat' => 'nullable|string|max:255',
                'pemberi_perintah_golongan' => 'nullable|string|max:255',
                'pemberi_perintah_instance_id' => 'nullable|exists:instances,id',
                'pemberi_perintah_jenis_pegawai' => 'nullable|string|max:50',
                'dasar' => 'nullable|string',
                'untuk' => 'nullable|string',
                'has_spd' => 'boolean',
                'penandatangan_nama' => 'nullable|string|max:255',
                'penandatangan_nip' => 'nullable|string|max:50',
                'penandatangan_jabatan' => 'nullable|string|max:255',
                'penandatangan_instance_id' => 'nullable|exists:instances,id',
                'ppk_nama' => 'nullable|string|max:255',
                'ppk_nip' => 'nullable|string|max:50',
                'ppk_jabatan' => 'nullable|string|max:255',
                'ppk_pangkat' => 'nullable|string|max:255',
                'ppk_golongan' => 'nullable|string|max:255',
                'ppk_instance_id' => 'nullable|exists:instances,id',
                'nomor_surat' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('surat_tugas', 'nomor_surat')
                        ->where('instance_id', $suratTugas->instance_id)
                        ->whereNull('deleted_at')
                        ->ignore($suratTugas->id),
                ],
                'jenis_perjalanan' => 'nullable|in:luar_kabupaten,dalam_kabupaten',
                'tujuan_provinsi_id' => 'nullable|string',
                'tujuan_provinsi_nama' => 'nullable|string|max:255',
                'tujuan_kabupaten_id' => 'nullable|string',
                'tujuan_kabupaten_nama' => 'nullable|string|max:255',
                'tujuan_kecamatan_id' => 'nullable|string',
                'tujuan_kecamatan_nama' => 'nullable|string|max:255',
                'lokasi_tujuan' => 'nullable|string|max:500',
                'tanggal_berangkat' => 'nullable|date',
                'lama_perjalanan' => 'nullable|integer|min:1',
                'tanggal_kembali' => 'nullable|date|after_or_equal:tanggal_berangkat',
                'tempat_dikeluarkan' => 'nullable|string|max:255',
                'tanggal_dikeluarkan' => 'nullable|date',
                'alat_angkut' => 'nullable|string|max:255',
                'biaya' => 'nullable|numeric|min:0',
                'sub_kegiatan_kode' => 'nullable|string|max:255',
                'sub_kegiatan_nama' => 'nullable|string|max:500',
                'kode_rekening' => 'nullable|string|max:255',
                'uraian_rekening' => 'nullable|string|max:500',
                'keterangan' => 'nullable|string',
                'pegawai' => 'sometimes|array|min:1',
                'pegawai.*.semesta_pegawai_id' => 'nullable|integer',
                'pegawai.*.nip' => 'required|string',
                'pegawai.*.nama_lengkap' => 'required|string',
                'pegawai.*.jabatan' => 'nullable|string',
                'pegawai.*.pangkat' => 'nullable|string',
                'pegawai.*.golongan' => 'nullable|string',
                'pegawai.*.eselon' => 'nullable|string',
                'pegawai.*.id_skpd' => 'nullable|integer',
                'pegawai.*.nama_skpd' => 'nullable|string',
                'pegawai.*.id_jabatan' => 'nullable|integer',
                'pegawai.*.jenis_pegawai' => 'nullable|string|max:50',
            ]);

            DB::transaction(function () use ($request, $suratTugas) {
                $wasHasSpd = $suratTugas->has_spd;
                $oldNomorSurat = $suratTugas->nomor_surat;

                $suratTugas->update($request->except(['pegawai', 'status', 'pemberi_perintah_jenis_pegawai']));

                // Check if nomor_surat changed — if yes, nomor_spd should be reset
                $nomorSuratChanged = $request->has('nomor_surat') && $request->nomor_surat !== $oldNomorSurat;

                // If pegawai list updated, sync
                if ($request->has('pegawai')) {
                    // Preserve existing SPD nomor_spd mapped by pegawai NIP (unless nomor_surat changed)
                    $existingSpdMap = [];
                    if (!$nomorSuratChanged) {
                        foreach ($suratTugas->suratPerjalananDinas as $existingSpd) {
                            $pegawaiNip = $existingSpd->suratTugasPegawai?->nip;
                            if ($pegawaiNip) {
                                $existingSpdMap[$pegawaiNip] = $existingSpd->nomor_spd;
                            }
                        }
                    }

                    // Delete old SPDs first (cascaded from surat_tugas_pegawai)
                    $suratTugas->suratPerjalananDinas()->forceDelete();
                    $suratTugas->pegawai()->delete();

                    foreach ($request->pegawai as $p) {
                        $stp = SuratTugasPegawai::create([
                            'surat_tugas_id' => $suratTugas->id,
                            'semesta_pegawai_id' => $p['semesta_pegawai_id'] ?? null,
                            'nip' => $p['nip'],
                            'nama_lengkap' => $p['nama_lengkap'],
                            'jabatan' => $p['jabatan'] ?? null,
                            'pangkat' => $p['pangkat'] ?? null,
                            'golongan' => $p['golongan'] ?? null,
                            'eselon' => $p['eselon'] ?? null,
                            'id_skpd' => $p['id_skpd'] ?? null,
                            'nama_skpd' => $p['nama_skpd'] ?? null,
                            'id_jabatan' => $p['id_jabatan'] ?? null,
                        ]);

                        if ($suratTugas->has_spd) {
                            // Lookup kepala_skpd from Employee table
                            $employee = Employee::where('nip', $p['nip'])->first();
                            $tingkatData = [
                                'eselon' => $p['eselon'] ?? '',
                                'golongan' => $p['golongan'] ?? '',
                                'kepala_skpd' => $employee->kepala_skpd ?? null,
                            ];

                            SuratPerjalananDinas::create([
                                'surat_tugas_id' => $suratTugas->id,
                                'surat_tugas_pegawai_id' => $stp->id,
                                'nomor_spd' => $existingSpdMap[$p['nip']] ?? null,
                                'tingkat_biaya' => SuratPerjalananDinas::detectCostLevel($tingkatData),
                                'status' => 'draft',
                            ]);
                        }
                    }
                } elseif (!$wasHasSpd && $suratTugas->has_spd) {
                    // has_spd toggled on, create SPDs for existing pegawai
                    foreach ($suratTugas->pegawai as $stp) {
                        if (!$stp->suratPerjalananDinas) {
                            // Lookup kepala_skpd from Employee table
                            $employee = Employee::where('nip', $stp->nip)->first();
                            $tingkatData = [
                                'eselon' => $stp->eselon ?? '',
                                'golongan' => $stp->golongan ?? '',
                                'kepala_skpd' => $employee->kepala_skpd ?? null,
                            ];

                            SuratPerjalananDinas::create([
                                'surat_tugas_id' => $suratTugas->id,
                                'surat_tugas_pegawai_id' => $stp->id,
                                'tingkat_biaya' => SuratPerjalananDinas::detectCostLevel($tingkatData),
                                'status' => 'draft',
                            ]);
                        }
                    }
                } elseif ($wasHasSpd && !$suratTugas->has_spd) {
                    // has_spd toggled off, delete SPDs
                    $suratTugas->suratPerjalananDinas()->forceDelete();
                }
            });

            $suratTugas->refresh()->load([
                'klasifikasi',
                'kategori',
                'instance',
                'pegawai',
                'suratPerjalananDinas.suratTugasPegawai',
            ]);

            // Log update
            $this->catatLog($request, $suratTugas->id, 'diperbarui');

            // Activity Log
            ActivityLogService::log($request, ActivityLog::ACTION_UPDATE_SURAT, "Mengubah Surat Tugas #{$suratTugas->id}", [
                'surat_tugas_id' => $suratTugas->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Surat tugas berhasil diperbarui.',
                'data' => $suratTugas,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Surat tugas tidak ditemukan.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('SuratTugas update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui surat tugas.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Delete surat tugas (soft delete, only if draft)
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $suratTugas = SuratTugas::findOrFail($id);
            $user = $request->user();

            // Only creator or super admin can delete
            if (!$this->isSuperAdmin($user) && !$this->isCreator($user, $suratTugas)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menghapus surat tugas ini.',
                ], 403);
            }

            if (!$suratTugas->canEdit()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Surat tugas hanya bisa dihapus saat status draft.',
                ], 422);
            }

            $suratTugas->suratPerjalananDinas()->delete(); // soft delete SPDs
            $suratTugas->delete(); // soft delete ST

            return response()->json([
                'success' => true,
                'message' => 'Surat tugas berhasil dihapus.',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Surat tugas tidak ditemukan.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('SuratTugas destroy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus surat tugas.',
            ], 500);
        }
    }

    /**
     * Send surat tugas for signing (draft -> dikirim)
     * Also generates nomor surat and SPD files
     */
    public function kirim(Request $request, string $id): JsonResponse
    {
        try {
            $suratTugas = SuratTugas::with(['pegawai', 'klasifikasi', 'instance', 'suratPerjalananDinas'])
                ->findOrFail($id);
            $user = $request->user();

            // Only creator or super admin can send
            if (!$this->isSuperAdmin($user) && !$this->isCreator($user, $suratTugas)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk mengirim surat tugas ini.',
                ], 403);
            }

            if ($suratTugas->status !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya surat tugas berstatus draft yang bisa dikirim.',
                ], 422);
            }

            // Validate required fields before sending
            $missingFields = [];
            if (!$suratTugas->dasar) $missingFields[] = 'Dasar surat';
            if (!$suratTugas->untuk) $missingFields[] = 'Untuk/tujuan surat';
            if (!$suratTugas->penandatangan_nama) $missingFields[] = 'Penandatangan';
            if ($suratTugas->pegawai->isEmpty()) $missingFields[] = 'Pegawai yang ditugaskan';

            if ($suratTugas->has_spd) {
                if (!$suratTugas->tujuan_provinsi_nama && !$suratTugas->lokasi_tujuan) {
                    $missingFields[] = 'Tujuan perjalanan';
                }
                if (!$suratTugas->tanggal_berangkat) $missingFields[] = 'Tanggal berangkat';
                if (!$suratTugas->tanggal_kembali) $missingFields[] = 'Tanggal kembali';

                // Check if all SPD have nomor_spd filled
                $spdWithoutNomor = $suratTugas->suratPerjalananDinas->filter(fn($spd) => empty($spd->nomor_spd));
                if ($spdWithoutNomor->isNotEmpty()) {
                    $missingFields[] = 'Nomor SPD (masih ada ' . $spdWithoutNomor->count() . ' SPD yang belum memiliki nomor)';
                }
            }

            if (!empty($missingFields)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data belum lengkap: ' . implode(', ', $missingFields),
                    'missing_fields' => $missingFields,
                ], 422);
            }

            DB::transaction(function () use ($suratTugas) {
                // Generate nomor surat
                if (!$suratTugas->nomor_surat) {
                    $suratTugas->nomor_surat = $this->generateNomorSurat($suratTugas);
                }

                // Generate nomor SPD for each
                if ($suratTugas->has_spd) {
                    foreach ($suratTugas->suratPerjalananDinas as $i => $spd) {
                        if (!$spd->nomor_spd) {
                            $spd->update([
                                'nomor_spd' => $this->generateNomorSpd($suratTugas, $i + 1),
                                'status' => 'dikirim',
                            ]);
                        }
                    }
                }

                $suratTugas->update(['status' => 'dikirim']);
            });

            // Generate documents (ST + SPD PDFs) after transaction
            try {
                $this->documentService->generateAllDocuments($suratTugas);
            } catch (\Exception $docEx) {
                Log::warning('Document generation failed but surat tugas was sent: ' . $docEx->getMessage());
                // Don't fail the kirim action if document generation fails
            }

            $suratTugas->refresh()->load([
                'klasifikasi',
                'kategori',
                'instance',
                'pegawai',
                'suratPerjalananDinas.suratTugasPegawai',
            ]);

            // Log kirim
            $this->catatLog($request, $suratTugas->id, 'dikirim');

            // Send notification to penandatangan
            NotificationService::notifySuratDikirim($suratTugas, $user);

            // Activity Log
            ActivityLogService::log($request, ActivityLog::ACTION_KIRIM_SURAT, "Mengirim Surat Tugas {$suratTugas->nomor_surat} untuk ditandatangani", [
                'surat_tugas_id' => $suratTugas->id,
                'nomor_surat' => $suratTugas->nomor_surat,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Surat tugas berhasil dikirim untuk ditandatangani.',
                'data' => $suratTugas,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Surat tugas tidak ditemukan.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('SuratTugas kirim error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim surat tugas.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Sign surat tugas (dikirim -> ditandatangani) with TTE eSign
     */
    public function tandatangani(Request $request, string $id): JsonResponse
    {
        try {
            $request->validate([
                'passphrase' => 'required|string',
            ]);

            $suratTugas = SuratTugas::with(['suratPerjalananDinas'])->findOrFail($id);
            $user = $request->user();
            $user->loadMissing('employee');

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

            // Get signer NIK from User model
            $signerNik = $user->nik;
            if (!$signerNik) {
                return response()->json([
                    'success' => false,
                    'message' => 'NIK penandatangan belum terdaftar di sistem. Hubungi administrator.',
                ], 422);
            }

            $passphrase = $request->input('passphrase');
            $esignService = new \App\Services\ESignService();

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
                return response()->json([
                    'success' => false,
                    'message' => $stResult['message'],
                ], 422);
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
                    continue; // Skip SPDs without generated documents
                }

                $spdPdfPath = storage_path('app/' . $spd->file_spd);
                if (!file_exists($spdPdfPath)) {
                    Log::warning("SPD file not found for signing: {$spd->file_spd}");
                    continue;
                }

                $spdResult = $esignService->signPdf($spdPdfPath, $signerNik, $passphrase);
                if (!$spdResult['success']) {
                    Log::warning("Failed to sign SPD {$spd->id}: {$spdResult['message']}");
                    // Continue signing other SPDs — don't fail the whole process
                    continue;
                }

                $spdSignedDir = storage_path('app/documents/spd/signed');
                if (!is_dir($spdSignedDir)) {
                    mkdir($spdSignedDir, 0755, true);
                }
                $spdSignedFilename = 'SPD_SIGNED_' . str_replace(['/', ' '], ['_', '_'], $spd->nomor_spd ?? $spd->id) . '_' . time() . '.pdf';
                $spdSignedLocalPath = $spdSignedDir . '/' . $spdSignedFilename;
                file_put_contents($spdSignedLocalPath, $spdResult['signed_pdf_content']);

                $spdSignedPaths[$spd->id] = 'documents/spd/signed/' . $spdSignedFilename;
            }

            // === Update database in transaction ===
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

            // Log tandatangani
            $this->catatLog($request, $suratTugas->id, 'ditandatangani');

            // Send notifications to creator and pegawai in ST/SPD
            NotificationService::notifySuratDitandatangani($suratTugas, $user);

            // Activity Log
            ActivityLogService::log($request, ActivityLog::ACTION_TANDATANGANI_SURAT, "Menandatangani Surat Tugas {$suratTugas->nomor_surat} dengan Tanda Tangan Elektronik", [
                'surat_tugas_id' => $suratTugas->id,
                'nomor_surat' => $suratTugas->nomor_surat,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Surat tugas berhasil ditandatangani secara elektronik (TTE).',
                'data' => $suratTugas,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Passphrase wajib diisi.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Surat tugas tidak ditemukan.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('SuratTugas tandatangani error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal menandatangani surat tugas.',
            ], 500);
        }
    }

    /**
     * Reject surat tugas (dikirim -> ditolak)
     */
    public function tolak(Request $request, string $id): JsonResponse
    {
        try {
            $request->validate([
                'alasan' => 'nullable|string|max:500',
            ]);

            $suratTugas = SuratTugas::with(['suratPerjalananDinas'])->findOrFail($id);
            $user = $request->user();

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
                    'keterangan' => $request->alasan ?? $suratTugas->keterangan,
                ]);

                $suratTugas->suratPerjalananDinas()->update(['status' => 'ditolak']);
            });

            $suratTugas->refresh();

            // Log tolak
            $this->catatLog($request, $suratTugas->id, 'ditolak', $request->alasan);

            // Send notification to creator and pegawai
            NotificationService::notifySuratDitolak($suratTugas, $user, $request->alasan);

            // Activity Log
            ActivityLogService::log($request, ActivityLog::ACTION_TOLAK_SURAT, "Menolak Surat Tugas {$suratTugas->nomor_surat}", [
                'surat_tugas_id' => $suratTugas->id,
                'nomor_surat' => $suratTugas->nomor_surat,
                'alasan' => $request->alasan,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Surat tugas berhasil ditolak.',
                'data' => $suratTugas,
            ]);
        } catch (\Exception $e) {
            Log::error('SuratTugas tolak error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menolak surat tugas.',
            ], 500);
        }
    }

    /**
     * Revise rejected surat tugas back to draft (ditolak -> draft)
     */
    public function revisi(Request $request, string $id): JsonResponse
    {
        try {
            $suratTugas = SuratTugas::with(['suratPerjalananDinas'])->findOrFail($id);
            $user = $request->user();

            // Only creator or super admin can revise
            if (!$this->isSuperAdmin($user) && !$this->isCreator($user, $suratTugas)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk merevisi surat tugas ini.',
                ], 403);
            }

            if ($suratTugas->status !== 'ditolak') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya surat tugas berstatus ditolak yang bisa direvisi.',
                ], 422);
            }

            DB::transaction(function () use ($suratTugas) {
                $suratTugas->update([
                    'status' => 'draft',
                    'nomor_surat' => null, // Reset nomor for re-generation
                    'signed_at' => null,
                ]);

                $suratTugas->suratPerjalananDinas()->update([
                    'status' => 'draft',
                    'nomor_spd' => null,
                    'signed_at' => null,
                ]);
            });

            $suratTugas->refresh();

            // Log revisi
            $this->catatLog($request, $suratTugas->id, 'direvisi');

            // Activity Log
            ActivityLogService::log($request, ActivityLog::ACTION_REVISI_SURAT, "Merevisi Surat Tugas #{$suratTugas->id} kembali ke draft", [
                'surat_tugas_id' => $suratTugas->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Surat tugas dikembalikan ke draft untuk direvisi.',
                'data' => $suratTugas,
            ]);
        } catch (\Exception $e) {
            Log::error('SuratTugas revisi error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal merevisi surat tugas.',
            ], 500);
        }
    }

    /**
     * Mark surat tugas as selesai (ditandatangani -> selesai)
     */
    public function selesai(Request $request, string $id): JsonResponse
    {
        try {
            $suratTugas = SuratTugas::with(['suratPerjalananDinas'])->findOrFail($id);
            $user = $request->user();

            // Only creator, signer, or super admin can mark as done
            if (!$this->canModify($user, $suratTugas)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk menyelesaikan surat tugas ini.',
                ], 403);
            }

            if ($suratTugas->status !== 'ditandatangani') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya surat tugas berstatus ditandatangani yang bisa diselesaikan.',
                ], 422);
            }

            DB::transaction(function () use ($suratTugas) {
                $suratTugas->update(['status' => 'selesai']);
                $suratTugas->suratPerjalananDinas()->update(['status' => 'selesai']);
            });

            // Log selesai
            $this->catatLog($request, $suratTugas->id, 'diselesaikan');

            // Activity Log
            ActivityLogService::log($request, ActivityLog::ACTION_SELESAI_SURAT, "Menyelesaikan Surat Tugas {$suratTugas->nomor_surat}", [
                'surat_tugas_id' => $suratTugas->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Surat tugas berhasil diselesaikan.',
                'data' => $suratTugas->refresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('SuratTugas selesai error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyelesaikan surat tugas.',
            ], 500);
        }
    }

    /**
     * Download Surat Tugas document (PDF or DOCX)
     * Only allowed when status is 'ditandatangani' or 'selesai', unless secret key is provided
     */
    public function download(Request $request, string $id)
    {
        try {
            $suratTugas = SuratTugas::findOrFail($id);

            // Block download if not yet signed — no exceptions
            $allowedStatuses = ['ditandatangani', 'selesai'];
            if (!in_array($suratTugas->status, $allowedStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dokumen hanya bisa diunduh setelah ditandatangani.',
                ], 403);
            }

            // Only serve the signed PDF
            $fileField = $suratTugas->file_surat_tugas_signed;
            if (!$fileField) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dokumen yang telah ditandatangani belum tersedia.',
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
            $filename = 'Surat_Tugas_' . str_replace(['/', ' '], ['_', '_'], $suratTugas->nomor_surat ?? $suratTugas->id) . '.' . $extension;

            $contentType = $extension === 'pdf'
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

            // // Log download
            // $this->catatLog($request, $suratTugas->id, 'diunduh');

            return response()->download($filePath, $filename, [
                'Content-Type' => $contentType,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Surat tugas tidak ditemukan.',
            ], 404);
        }
    }

    /**
     * Regenerate all documents for a Surat Tugas
     */
    public function regenerateDocument(Request $request, string $id): JsonResponse
    {
        try {
            $suratTugas = SuratTugas::findOrFail($id);

            if ($suratTugas->status === 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'Surat tugas berstatus draft belum bisa digenerate dokumennya.',
                ], 422);
            }

            $results = $this->documentService->generateAllDocuments($suratTugas);

            // Log regenerate
            $this->catatLog($request, $suratTugas->id, 'digenerate_ulang');

            return response()->json([
                'success' => true,
                'message' => 'Dokumen berhasil digenerate ulang.',
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            Log::error('Regenerate document error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengenerate ulang dokumen.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get dashboard statistics
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $instanceId = $user->instance_id;

            $stats = [
                'total' => SuratTugas::where('instance_id', $instanceId)->count(),
                'draft' => SuratTugas::where('instance_id', $instanceId)->where('status', 'draft')->count(),
                'dikirim' => SuratTugas::where('instance_id', $instanceId)->where('status', 'dikirim')->count(),
                'ditandatangani' => SuratTugas::where('instance_id', $instanceId)->where('status', 'ditandatangani')->count(),
                'ditolak' => SuratTugas::where('instance_id', $instanceId)->where('status', 'ditolak')->count(),
                'selesai' => SuratTugas::where('instance_id', $instanceId)->where('status', 'selesai')->count(),
                'total_spd' => SuratPerjalananDinas::whereHas('suratTugas', fn($q) => $q->where('instance_id', $instanceId))->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('SuratTugas stats error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat statistik.',
            ], 500);
        }
    }

    /**
     * Get log/history for a surat tugas
     */
    public function logSurat(string $id): JsonResponse
    {
        try {
            $suratTugas = SuratTugas::findOrFail($id);

            $logs = LogSurat::with('user:id,name,username')
                ->where('surat_tugas_id', $id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'aksi' => $log->aksi,
                        'label' => $log->label,
                        'color' => $log->color,
                        'keterangan' => $log->keterangan,
                        'user' => $log->user ? [
                            'id' => $log->user->id,
                            'name' => $log->user->name,
                            'username' => $log->user->username,
                        ] : null,
                        'ip_address' => $log->ip_address,
                        'created_at' => $log->created_at->toISOString(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $logs,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Surat tugas tidak ditemukan.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('LogSurat error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat log surat.',
            ], 500);
        }
    }

    /**
     * Helper: record a log entry for surat tugas
     */
    private function catatLog(Request $request, string $suratTugasId, string $aksi, ?string $keterangan = null): void
    {
        try {
            LogSurat::catat(
                $suratTugasId,
                $aksi,
                $request->user()?->id,
                $keterangan,
                $request->ip(),
                $request->userAgent()
            );
        } catch (\Exception $e) {
            Log::warning("Failed to log surat action '{$aksi}' for ST #{$suratTugasId}: " . $e->getMessage());
        }
    }

    /**
     * Generate nomor surat based on klasifikasi and sequence
     */
    private function generateNomorSurat(SuratTugas $st): string
    {
        $year = date('Y');
        $kode = $st->klasifikasi ? $st->klasifikasi->kode : '000';

        // Count existing surat in the same year and instance
        $count = SuratTugas::where('instance_id', $st->instance_id)
            ->whereYear('created_at', $year)
            ->whereNotNull('nomor_surat')
            ->count();

        $sequence = str_pad($count + 1, 3, '0', STR_PAD_LEFT);
        $instanceCode = $st->instance ? ($st->instance->code ?? '') : '';

        return "{$sequence}/{$kode}/{$instanceCode}/{$year}";
    }

    /**
     * Generate nomor SPD
     */
    private function generateNomorSpd(SuratTugas $st, int $index): string
    {
        $year = date('Y');

        $count = SuratPerjalananDinas::whereHas('suratTugas', function ($q) use ($st) {
            $q->where('instance_id', $st->instance_id);
        })
            ->whereYear('created_at', $year)
            ->whereNotNull('nomor_spd')
            ->count();

        $sequence = str_pad($count + $index, 3, '0', STR_PAD_LEFT);
        $instanceCode = $st->instance ? ($st->instance->code ?? '') : '';

        return "SPD-{$sequence}/{$instanceCode}/{$year}";
    }

}
