<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Instance;
use App\Models\SuratTugas;
use App\Models\SuratPerjalananDinas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PegawaiController extends Controller
{
    /**
     * Get employees from Semesta API by SKPD
     * Uses the daftar-pegawai endpoint with x-api-key header
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Use provided id_skpd or fall back to user's instance
            $idSkpd = $request->input('id_skpd');

            if (!$idSkpd && $user->instance) {
                $idSkpd = $user->instance->id_eoffice;
            }

            if (!$idSkpd) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID SKPD tidak ditemukan.',
                ], 422);
            }

            $semestaUrl = config('services.semesta.url') . '/daftar-pegawai';
            $apiKey = config('services.semesta.api_key');

            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'PostmanRuntime/7.44.1',
                    'x-api-key' => $apiKey,
                ])
                ->post($semestaUrl, [
                    'id_skpd' => (int) $idSkpd,
                ]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengambil data pegawai dari Semesta.',
                ], 502);
            }

            $data = $response->json();
            $pegawai = $data['data'] ?? $data ?? [];

            // Ensure it's an array
            if (!is_array($pegawai)) {
                $pegawai = [];
            }

            // Filter kepala_skpd only (used when Bupati is pemberi perintah)
            if ($request->boolean('kepala_skpd_only')) {
                $pegawai = array_filter($pegawai, function ($p) {
                    return ($p['kepala_skpd'] ?? '') === 'Y';
                });
                $pegawai = array_values($pegawai);
            }

            // Apply search filter if provided
            $search = $request->input('search');
            if ($search) {
                $search = strtolower($search);
                $pegawai = array_filter($pegawai, function ($p) use ($search) {
                    return str_contains(strtolower($p['nama_lengkap'] ?? ''), $search)
                        || str_contains(strtolower($p['nip'] ?? ''), $search)
                        || str_contains(strtolower($p['jabatan'] ?? ''), $search);
                });
                $pegawai = array_values($pegawai);
            }

            return response()->json([
                'success' => true,
                'data' => $pegawai,
                'total' => count($pegawai),
            ]);
        } catch (\Exception $e) {
            Log::error('Pegawai fetch error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pegawai.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get Bupati data from local Employee table
     * Used when Semesta API doesn't have Bupati data
     */
    public function bupati(): JsonResponse
    {
        try {
            $bupati = Employee::where('nip', '1000')
                ->orWhere(function ($q) {
                    $q->where('jabatan', 'like', '%bupati%')
                      ->where('jabatan', 'not like', '%wakil%');
                })
                ->first();

            if (!$bupati) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data Bupati tidak ditemukan.',
                ], 404);
            }

            // Map to SemestaPegawai-like format for frontend compatibility
            $instance = $bupati->instance;

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $bupati->id,
                    'nip' => $bupati->nip,
                    'nama_lengkap' => $bupati->nama_lengkap,
                    'jabatan' => $bupati->jabatan,
                    'golongan' => $bupati->golongan,
                    'pangkat' => $bupati->pangkat,
                    'eselon' => $bupati->eselon,
                    'jenis_pegawai' => $bupati->jenis_pegawai,
                    'id_skpd' => $instance?->id_eoffice,
                    'instance_id' => $bupati->instance_id,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Bupati fetch error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data Bupati.',
            ], 500);
        }
    }

    /**
     * Show employee detail with their surat tugas & SPD history
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();
            $user->loadMissing('role', 'employee');

            $employee = Employee::with('instance:id,name,alias')->findOrFail($id);

            // OPD scope check: non-bupati/non-admin can only view employees in their own OPD
            $roleSlug = $user->role->slug ?? 'staff';
            $isBupati = $roleSlug === 'bupati' || $user->username === '1000';
            $isAdmin = $roleSlug === 'super-admin';

            if (!$isAdmin && !$isBupati && $user->instance_id !== $employee->instance_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses untuk melihat data pegawai ini.',
                ], 403);
            }

            $nip = $employee->nip;

            // Surat Tugas where this employee is assigned
            $suratTugas = SuratTugas::with([
                'kategori:id,nama',
                'instance:id,name,alias',
            ])
                ->whereHas('pegawai', fn ($q) => $q->where('nip', $nip))
                ->select('id', 'nomor_surat', 'untuk', 'status', 'kategori_id', 'instance_id',
                    'tujuan_kabupaten_nama', 'lokasi_tujuan', 'tanggal_berangkat', 'tanggal_kembali',
                    'penandatangan_nama', 'created_at')
                ->latest()
                ->limit(50)
                ->get();

            // SPD where this employee is the assigned pegawai
            $spd = SuratPerjalananDinas::with([
                'suratTugas:id,nomor_surat,untuk,tujuan_kabupaten_nama,lokasi_tujuan,tanggal_berangkat,tanggal_kembali,instance_id',
                'suratTugas.instance:id,name,alias',
                'suratTugasPegawai:id,nama_lengkap,nip,jabatan',
                'laporanPerjalananDinas',
            ])
                ->whereHas('suratTugasPegawai', fn ($q) => $q->where('nip', $nip))
                ->select('id', 'nomor_spd', 'tingkat_biaya', 'surat_tugas_id', 'surat_tugas_pegawai_id', 'status', 'created_at')
                ->latest()
                ->limit(50)
                ->get();

            // Stats
            $stCounts = $suratTugas->groupBy('status')->map->count();
            $spdCounts = $spd->groupBy('status')->map->count();

            // Active trips: ST that are signed and currently within travel dates
            $today = Carbon::today()->toDateString();
            $activeTrips = $suratTugas->filter(function ($st) use ($today) {
                return $st->status === 'ditandatangani'
                    && $st->tanggal_berangkat !== null
                    && $st->tanggal_kembali !== null
                    && $st->tanggal_berangkat <= $today
                    && $st->tanggal_kembali >= $today;
            })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'employee' => $employee,
                    'surat_tugas' => $suratTugas,
                    'spd' => $spd,
                    'active_trips' => $activeTrips,
                    'stats' => [
                        'total_surat_tugas' => $suratTugas->count(),
                        'total_spd' => $spd->count(),
                        'active_trip_count' => $activeTrips->count(),
                        'surat_tugas_by_status' => $stCounts,
                        'spd_by_status' => $spdCounts,
                    ],
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data pegawai tidak ditemukan.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Pegawai show error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail pegawai.',
            ], 500);
        }
    }

    /**
     * Get instances (SKPD) list for cross-SKPD employee lookup
     */
    public function instances(Request $request): JsonResponse
    {
        try {
            $query = Instance::query();

            if ($search = $request->input('search')) {
                $query->search($search);
            }

            $instances = $query->orderBy('name')
                ->select(['id', 'id_eoffice', 'name', 'alias', 'code'])
                ->get();

            return response()->json([
                'success' => true,
                'data' => $instances,
            ]);
        } catch (\Exception $e) {
            Log::error('Instances fetch error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data instansi.',
            ], 500);
        }
    }
}
