<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuratTugas;
use App\Models\SuratPerjalananDinas;
use App\Models\SuratTugasPegawai;
use App\Models\Instance;
use App\Models\KlasifikasiNomorSurat;
use App\Models\KategoriSurat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ReportController extends Controller
{
    // ──────────────────────────────────────
    // Scope helpers (same pattern as DashboardController)
    // ──────────────────────────────────────

    private function getScopeInstanceId(Request $request): ?int
    {
        $user = $request->user();
        if (!$user) return null;

        $user->loadMissing('role');
        $roleSlug = $user->role->slug ?? 'staff';
        $isBupati = $roleSlug === 'bupati' || $roleSlug === 'super-admin' || $user->username === '1000';

        return $isBupati ? null : $user->instance_id;
    }

    private function applyScope($query, ?int $instanceId)
    {
        if ($instanceId) {
            $query->where('instance_id', $instanceId);
        }
        return $query;
    }

    private function namaBulan(int $m): string
    {
        return ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'][$m] ?? '';
    }

    private function namaBulanFull(int $m): string
    {
        return ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$m] ?? '';
    }

    // ──────────────────────────────────────
    // Main Report Endpoint
    // ──────────────────────────────────────

    /**
     * GET /api/reports
     *
     * Query params:
     * - year (required): tahun
     * - month (optional): bulan (1-12), jika kosong = semua bulan
     * - instance_id (optional): filter per OPD (Bupati only)
     * - jenis_perjalanan (optional): luar_kabupaten | dalam_kabupaten
     * - status (optional): draft | dikirim | ditandatangani | ditolak | selesai
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $year = (int) ($request->input('year') ?: Carbon::now()->year);
            $month = $request->input('month') ? (int) $request->input('month') : null;
            $filterInstanceId = $request->input('instance_id') ? (int) $request->input('instance_id') : null;
            $jenisPerjalanan = $request->input('jenis_perjalanan');
            $status = $request->input('status');

            $scopeInstanceId = $this->getScopeInstanceId($request);

            // If user is scoped to their instance, override filter
            $effectiveInstanceId = $scopeInstanceId ?? $filterInstanceId;

            return response()->json([
                'success' => true,
                'data' => [
                    'year' => $year,
                    'month' => $month,
                    'filters_applied' => [
                        'instance_id' => $effectiveInstanceId,
                        'jenis_perjalanan' => $jenisPerjalanan,
                        'status' => $status,
                    ],
                    'is_all_opd' => $scopeInstanceId === null,
                    'overview' => $this->getOverview($year, $month, $effectiveInstanceId, $jenisPerjalanan, $status),
                    'trend_bulanan' => $this->getTrendBulanan($year, $effectiveInstanceId, $jenisPerjalanan, $status),
                    'status_distribution' => $this->getStatusDistribution($year, $month, $effectiveInstanceId, $jenisPerjalanan),
                    'jenis_perjalanan_breakdown' => $this->getJenisPerjalananBreakdown($year, $month, $effectiveInstanceId, $status),
                    'tingkat_biaya_distribution' => $this->getTingkatBiayaDistribution($year, $month, $effectiveInstanceId, $jenisPerjalanan),
                    'top_destinations' => $this->getTopDestinations($year, $month, $effectiveInstanceId, $jenisPerjalanan, $status),
                    'top_pegawai' => $this->getTopPegawaiReport($year, $month, $effectiveInstanceId, $jenisPerjalanan),
                    'opd_ranking' => $this->getOpdRanking($year, $month, $jenisPerjalanan, $status, $scopeInstanceId),
                    'biaya_analysis' => $this->getBiayaAnalysis($year, $month, $effectiveInstanceId, $jenisPerjalanan),
                    'alat_angkut_distribution' => $this->getAlatAngkutDistribution($year, $month, $effectiveInstanceId, $jenisPerjalanan),
                    'klasifikasi_breakdown' => $this->getKlasifikasiBreakdown($year, $month, $effectiveInstanceId, $jenisPerjalanan),
                    'kategori_breakdown' => $this->getKategoriBreakdown($year, $month, $effectiveInstanceId, $jenisPerjalanan),
                    'durasi_analysis' => $this->getDurasiAnalysis($year, $month, $effectiveInstanceId, $jenisPerjalanan),
                    'daily_heatmap' => $this->getDailyHeatmap($year, $month, $effectiveInstanceId),
                    'comparison_prev_year' => $this->getComparisonPrevYear($year, $effectiveInstanceId),
                    'active_trips' => $this->getActiveTrips($year, $effectiveInstanceId),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Report error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data laporan.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET /api/reports/detail-table
     * Paginated table data for detailed report view
     */
    public function detailTable(Request $request): JsonResponse
    {
        try {
            $year = (int) ($request->input('year') ?: Carbon::now()->year);
            $month = $request->input('month') ? (int) $request->input('month') : null;
            $filterInstanceId = $request->input('instance_id') ? (int) $request->input('instance_id') : null;
            $jenisPerjalanan = $request->input('jenis_perjalanan');
            $status = $request->input('status');
            $search = $request->input('search');
            $perPage = min((int) ($request->input('per_page') ?: 15), 100);
            $sortBy = $request->input('sort_by', 'tanggal_dikeluarkan');
            $sortDir = $request->input('sort_dir', 'desc');

            $scopeInstanceId = $this->getScopeInstanceId($request);
            $effectiveInstanceId = $scopeInstanceId ?? $filterInstanceId;

            $allowedSorts = [
                'nomor_surat', 'tanggal_dikeluarkan', 'tanggal_berangkat',
                'tanggal_kembali', 'lama_perjalanan', 'biaya', 'status', 'created_at'
            ];
            if (!in_array($sortBy, $allowedSorts)) {
                $sortBy = 'tanggal_dikeluarkan';
            }
            $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

            $query = SuratTugas::whereYear('created_at', $year)
                ->with(['instance:id,name,alias', 'kategori:id,nama', 'klasifikasi:id,kode,klasifikasi', 'pegawai:id,surat_tugas_id,nama_lengkap,nip,jabatan,nama_skpd']);

            if ($effectiveInstanceId) {
                $query->where('instance_id', $effectiveInstanceId);
            }
            if ($month) {
                $query->whereMonth('created_at', $month);
            }
            if ($jenisPerjalanan) {
                $query->where('jenis_perjalanan', $jenisPerjalanan);
            }
            if ($status) {
                $query->where('status', $status);
            }
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('nomor_surat', 'like', "%{$search}%")
                      ->orWhere('tujuan_kabupaten_nama', 'like', "%{$search}%")
                      ->orWhere('tujuan_provinsi_nama', 'like', "%{$search}%")
                      ->orWhere('lokasi_tujuan', 'like', "%{$search}%")
                      ->orWhere('penandatangan_nama', 'like', "%{$search}%")
                      ->orWhereHas('pegawai', function ($pq) use ($search) {
                          $pq->where('nama_lengkap', 'like', "%{$search}%")
                             ->orWhere('nip', 'like', "%{$search}%");
                      });
                });
            }

            $query->orderBy($sortBy, $sortDir);

            $paginated = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $paginated,
            ]);
        } catch (\Exception $e) {
            Log::error('Report detail-table error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data tabel laporan.',
            ], 500);
        }
    }

    /**
     * GET /api/reports/instances
     * List of instances for filter dropdown (Bupati only)
     */
    public function instances(Request $request): JsonResponse
    {
        $scopeInstanceId = $this->getScopeInstanceId($request);

        if ($scopeInstanceId) {
            $instances = Instance::where('id', $scopeInstanceId)
                ->where('status', 'active')
                ->select('id', 'name', 'alias')
                ->get();
        } else {
            $instances = Instance::where('status', 'active')
                ->orderBy('name')
                ->select('id', 'name', 'alias')
                ->get();
        }

        return response()->json([
            'success' => true,
            'data' => $instances,
        ]);
    }

    // ──────────────────────────────────────
    // Report Data Generators
    // ──────────────────────────────────────

    private function baseQuery(int $year, ?int $month, ?int $instanceId, ?string $jenisPerjalanan = null, ?string $status = null)
    {
        $query = SuratTugas::whereYear('created_at', $year);
        if ($month) $query->whereMonth('created_at', $month);
        if ($instanceId) $query->where('instance_id', $instanceId);
        if ($jenisPerjalanan) $query->where('jenis_perjalanan', $jenisPerjalanan);
        if ($status) $query->where('status', $status);
        return $query;
    }

    /**
     * Overview summary numbers
     */
    private function getOverview(int $year, ?int $month, ?int $instanceId, ?string $jenisPerjalanan, ?string $status): array
    {
        $base = $this->baseQuery($year, $month, $instanceId, $jenisPerjalanan, $status);

        $totalSt = (clone $base)->count();
        $totalSpd = SuratPerjalananDinas::whereHas('suratTugas', function ($q) use ($year, $month, $instanceId, $jenisPerjalanan, $status) {
            $q->whereYear('created_at', $year);
            if ($month) $q->whereMonth('created_at', $month);
            if ($instanceId) $q->where('instance_id', $instanceId);
            if ($jenisPerjalanan) $q->where('jenis_perjalanan', $jenisPerjalanan);
            if ($status) $q->where('status', $status);
        })->count();

        $totalPegawai = SuratTugasPegawai::whereHas('suratTugas', function ($q) use ($year, $month, $instanceId, $jenisPerjalanan, $status) {
            $q->whereYear('created_at', $year);
            if ($month) $q->whereMonth('created_at', $month);
            if ($instanceId) $q->where('instance_id', $instanceId);
            if ($jenisPerjalanan) $q->where('jenis_perjalanan', $jenisPerjalanan);
            if ($status) $q->where('status', $status);
        })->distinct('nip')->count('nip');

        $totalBiaya = (clone $base)->where('has_spd', true)->sum('biaya');

        $avgLama = (clone $base)->whereNotNull('lama_perjalanan')->avg('lama_perjalanan');

        $totalDest = (clone $base)->where('has_spd', true)
            ->distinct('tujuan_kabupaten_nama')
            ->count('tujuan_kabupaten_nama');

        return [
            'total_surat_tugas' => $totalSt,
            'total_spd' => $totalSpd,
            'total_pegawai_ditugaskan' => $totalPegawai,
            'total_biaya' => (float) $totalBiaya,
            'rata_rata_lama_perjalanan' => $avgLama ? round((float) $avgLama, 1) : 0,
            'total_destinasi' => $totalDest,
        ];
    }

    /**
     * Monthly trend (Jan-Dec) for line/area chart
     */
    private function getTrendBulanan(int $year, ?int $instanceId, ?string $jenisPerjalanan, ?string $status): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $base = $this->baseQuery($year, $m, $instanceId, $jenisPerjalanan, $status);
            $baseSpdOnly = $this->baseQuery($year, $m, $instanceId, $jenisPerjalanan, $status)->where('has_spd', true);

            $months[] = [
                'bulan' => $this->namaBulan($m),
                'bulan_full' => $this->namaBulanFull($m),
                'nomor' => $m,
                'total_st' => (clone $base)->count(),
                'total_spd' => SuratPerjalananDinas::whereHas('suratTugas', function ($q) use ($year, $m, $instanceId, $jenisPerjalanan, $status) {
                    $q->whereYear('created_at', $year)->whereMonth('created_at', $m);
                    if ($instanceId) $q->where('instance_id', $instanceId);
                    if ($jenisPerjalanan) $q->where('jenis_perjalanan', $jenisPerjalanan);
                    if ($status) $q->where('status', $status);
                })->count(),
                'total_biaya' => (float) (clone $baseSpdOnly)->sum('biaya'),
                'ditandatangani' => (clone $this->baseQuery($year, $m, $instanceId, $jenisPerjalanan, null))
                    ->where('status', 'ditandatangani')->count(),
                'selesai' => (clone $this->baseQuery($year, $m, $instanceId, $jenisPerjalanan, null))
                    ->where('status', 'selesai')->count(),
            ];
        }
        return $months;
    }

    /**
     * Status distribution for pie/donut chart
     */
    private function getStatusDistribution(int $year, ?int $month, ?int $instanceId, ?string $jenisPerjalanan): array
    {
        $statuses = ['draft', 'dikirim', 'ditandatangani', 'ditolak', 'selesai'];
        $result = [];

        foreach ($statuses as $s) {
            $count = $this->baseQuery($year, $month, $instanceId, $jenisPerjalanan, $s)->count();
            $result[] = [
                'status' => $s,
                'label' => $this->statusLabel($s),
                'total' => $count,
            ];
        }
        return $result;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'dikirim' => 'Dikirim',
            'ditandatangani' => 'Ditandatangani',
            'ditolak' => 'Ditolak',
            'selesai' => 'Selesai',
            default => ucfirst($status),
        };
    }

    /**
     * Jenis perjalanan breakdown
     */
    private function getJenisPerjalananBreakdown(int $year, ?int $month, ?int $instanceId, ?string $status): array
    {
        $base = $this->baseQuery($year, $month, $instanceId, null, $status)->where('has_spd', true);

        $luarKab = (clone $base)->where('jenis_perjalanan', 'luar_kabupaten');
        $dalamKab = (clone $base)->where('jenis_perjalanan', 'dalam_kabupaten');

        return [
            [
                'jenis' => 'luar_kabupaten',
                'label' => 'Perjalanan Dinas Biasa (Luar Kabupaten)',
                'total_st' => (clone $luarKab)->count(),
                'total_biaya' => (float) (clone $luarKab)->sum('biaya'),
            ],
            [
                'jenis' => 'dalam_kabupaten',
                'label' => 'Perjalanan Dinas Dalam Kota',
                'total_st' => (clone $dalamKab)->count(),
                'total_biaya' => (float) (clone $dalamKab)->sum('biaya'),
            ],
        ];
    }

    /**
     * Tingkat biaya SPD distribution
     */
    private function getTingkatBiayaDistribution(int $year, ?int $month, ?int $instanceId, ?string $jenisPerjalanan): array
    {
        $query = SuratPerjalananDinas::whereHas('suratTugas', function ($q) use ($year, $month, $instanceId, $jenisPerjalanan) {
            $q->whereYear('created_at', $year);
            if ($month) $q->whereMonth('created_at', $month);
            if ($instanceId) $q->where('instance_id', $instanceId);
            if ($jenisPerjalanan) $q->where('jenis_perjalanan', $jenisPerjalanan);
        })->whereNotNull('tingkat_biaya');

        $tingkatLabels = [
            'A' => 'Tingkat A (Bupati/Wakil/DPRD)',
            'B' => 'Tingkat B (Eselon II/Anggota DPRD)',
            'C' => 'Tingkat C (Eselon IIb)',
            'D' => 'Tingkat D (Eselon III)',
            'E' => 'Tingkat E (Eselon IV/Gol. IV)',
            'F' => 'Tingkat F (Gol. III & II)',
            'G' => 'Tingkat G (Gol. I)',
        ];

        return (clone $query)
            ->select('tingkat_biaya', DB::raw('COUNT(*) as total'))
            ->groupBy('tingkat_biaya')
            ->orderBy('tingkat_biaya')
            ->get()
            ->map(function ($row) use ($tingkatLabels) {
                return [
                    'tingkat' => $row->tingkat_biaya,
                    'label' => $tingkatLabels[$row->tingkat_biaya] ?? $row->tingkat_biaya,
                    'total' => $row->total,
                ];
            })
            ->toArray();
    }

    /**
     * Top destinations (provinsi + kabupaten combined)
     */
    private function getTopDestinations(int $year, ?int $month, ?int $instanceId, ?string $jenisPerjalanan, ?string $status): array
    {
        $base = $this->baseQuery($year, $month, $instanceId, $jenisPerjalanan, $status)->where('has_spd', true);

        $provinces = (clone $base)->whereNotNull('tujuan_provinsi_nama')
            ->select('tujuan_provinsi_nama', DB::raw('COUNT(*) as total'), DB::raw('SUM(biaya) as total_biaya'))
            ->groupBy('tujuan_provinsi_nama')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->toArray();

        $cities = (clone $base)->whereNotNull('tujuan_kabupaten_nama')
            ->select('tujuan_kabupaten_nama', 'tujuan_provinsi_nama', DB::raw('COUNT(*) as total'), DB::raw('SUM(biaya) as total_biaya'))
            ->groupBy('tujuan_kabupaten_nama', 'tujuan_provinsi_nama')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->toArray();

        return [
            'provinsi' => $provinces,
            'kabupaten' => $cities,
        ];
    }

    /**
     * Top pegawai with detail stats
     */
    private function getTopPegawaiReport(int $year, ?int $month, ?int $instanceId, ?string $jenisPerjalanan): array
    {
        return SuratTugasPegawai::whereHas('suratTugas', function ($q) use ($year, $month, $instanceId, $jenisPerjalanan) {
            $q->whereYear('created_at', $year);
            if ($month) $q->whereMonth('created_at', $month);
            if ($instanceId) $q->where('instance_id', $instanceId);
            if ($jenisPerjalanan) $q->where('jenis_perjalanan', $jenisPerjalanan);
        })
            ->select(
                'nip',
                'nama_lengkap',
                'jabatan',
                'nama_skpd',
                'golongan',
                'eselon',
                DB::raw('COUNT(*) as total_tugas')
            )
            ->groupBy('nip', 'nama_lengkap', 'jabatan', 'nama_skpd', 'golongan', 'eselon')
            ->orderByDesc('total_tugas')
            ->limit(15)
            ->get()
            ->toArray();
    }

    /**
     * OPD ranking by total ST, SPD, and biaya
     */
    private function getOpdRanking(int $year, ?int $month, ?string $jenisPerjalanan, ?string $status, ?int $scopeInstanceId): array
    {
        // Only show full OPD ranking if user can see all
        if ($scopeInstanceId) {
            return [];
        }

        $query = SuratTugas::whereYear('created_at', $year)
            ->whereNotNull('instance_id');

        if ($month) $query->whereMonth('created_at', $month);
        if ($jenisPerjalanan) $query->where('jenis_perjalanan', $jenisPerjalanan);
        if ($status) $query->where('status', $status);

        return $query->select(
                'instance_id',
                DB::raw('COUNT(*) as total_st'),
                DB::raw('SUM(CASE WHEN has_spd = 1 THEN 1 ELSE 0 END) as total_spd'),
                DB::raw('SUM(CASE WHEN has_spd = 1 THEN biaya ELSE 0 END) as total_biaya')
            )
            ->groupBy('instance_id')
            ->orderByDesc('total_st')
            ->limit(15)
            ->get()
            ->map(function ($row) {
                $instance = Instance::find($row->instance_id);
                return [
                    'instance_id' => $row->instance_id,
                    'nama' => $instance ? ($instance->alias ?? $instance->name) : 'Tidak diketahui',
                    'total_st' => $row->total_st,
                    'total_spd' => (int) $row->total_spd,
                    'total_biaya' => (float) $row->total_biaya,
                ];
            })
            ->toArray();
    }

    /**
     * Biaya analysis: monthly trend + avg + total
     */
    private function getBiayaAnalysis(int $year, ?int $month, ?int $instanceId, ?string $jenisPerjalanan): array
    {
        $monthlyBiaya = [];
        $total = 0;
        $count = 0;

        for ($m = 1; $m <= 12; $m++) {
            $base = $this->baseQuery($year, $m, $instanceId, $jenisPerjalanan, null)
                ->where('has_spd', true)
                ->whereNotNull('biaya');

            $sum = (float) (clone $base)->sum('biaya');
            $cnt = (clone $base)->count();

            $total += $sum;
            $count += $cnt;

            $monthlyBiaya[] = [
                'bulan' => $this->namaBulan($m),
                'bulan_full' => $this->namaBulanFull($m),
                'total_biaya' => $sum,
                'jumlah_spd' => $cnt,
                'rata_rata' => $cnt > 0 ? round($sum / $cnt, 0) : 0,
            ];
        }

        // Target biaya if month selected
        $targetBiaya = null;
        if ($month) {
            $base = $this->baseQuery($year, $month, $instanceId, $jenisPerjalanan, null)
                ->where('has_spd', true)->whereNotNull('biaya');
            $targetBiaya = (float) $base->sum('biaya');
        }

        return [
            'bulanan' => $monthlyBiaya,
            'total_biaya' => $total,
            'total_spd' => $count,
            'rata_rata_per_spd' => $count > 0 ? round($total / $count, 0) : 0,
        ];
    }

    /**
     * Alat angkut distribution
     */
    private function getAlatAngkutDistribution(int $year, ?int $month, ?int $instanceId, ?string $jenisPerjalanan): array
    {
        $base = $this->baseQuery($year, $month, $instanceId, $jenisPerjalanan, null)
            ->where('has_spd', true)
            ->whereNotNull('alat_angkut')
            ->where('alat_angkut', '!=', '');

        return $base->select('alat_angkut', DB::raw('COUNT(*) as total'), DB::raw('SUM(biaya) as total_biaya'))
            ->groupBy('alat_angkut')
            ->orderByDesc('total')
            ->get()
            ->map(fn($row) => [
                'alat_angkut' => $row->alat_angkut,
                'total' => $row->total,
                'total_biaya' => (float) $row->total_biaya,
            ])
            ->toArray();
    }

    /**
     * Klasifikasi surat breakdown
     */
    private function getKlasifikasiBreakdown(int $year, ?int $month, ?int $instanceId, ?string $jenisPerjalanan): array
    {
        $query = SuratTugas::whereYear('surat_tugas.created_at', $year)
            ->whereNotNull('klasifikasi_id')
            ->join('klasifikasi', 'surat_tugas.klasifikasi_id', '=', 'klasifikasi.id');

        if ($month) $query->whereMonth('surat_tugas.created_at', $month);
        if ($instanceId) $query->where('surat_tugas.instance_id', $instanceId);
        if ($jenisPerjalanan) $query->where('surat_tugas.jenis_perjalanan', $jenisPerjalanan);

        return $query->select(
                'klasifikasi.kode',
                'klasifikasi.klasifikasi as nama',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('klasifikasi.kode', 'klasifikasi.klasifikasi')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Kategori surat breakdown
     */
    private function getKategoriBreakdown(int $year, ?int $month, ?int $instanceId, ?string $jenisPerjalanan): array
    {
        $query = SuratTugas::whereYear('surat_tugas.created_at', $year)
            ->whereNotNull('kategori_id')
            ->join('kategori_surat', 'surat_tugas.kategori_id', '=', 'kategori_surat.id');

        if ($month) $query->whereMonth('surat_tugas.created_at', $month);
        if ($instanceId) $query->where('surat_tugas.instance_id', $instanceId);
        if ($jenisPerjalanan) $query->where('surat_tugas.jenis_perjalanan', $jenisPerjalanan);

        return $query->select(
                'kategori_surat.id',
                'kategori_surat.nama',
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('kategori_surat.id', 'kategori_surat.nama')
            ->orderByDesc('total')
            ->get()
            ->toArray();
    }

    /**
     * Durasi perjalanan analysis
     */
    private function getDurasiAnalysis(int $year, ?int $month, ?int $instanceId, ?string $jenisPerjalanan): array
    {
        $base = $this->baseQuery($year, $month, $instanceId, $jenisPerjalanan, null)
            ->where('has_spd', true)
            ->whereNotNull('lama_perjalanan')
            ->where('lama_perjalanan', '>', 0);

        $groups = [
            ['label' => '1 Hari', 'min' => 1, 'max' => 1],
            ['label' => '2-3 Hari', 'min' => 2, 'max' => 3],
            ['label' => '4-7 Hari', 'min' => 4, 'max' => 7],
            ['label' => '8-14 Hari', 'min' => 8, 'max' => 14],
            ['label' => '> 14 Hari', 'min' => 15, 'max' => 999],
        ];

        $result = [];
        foreach ($groups as $g) {
            $result[] = [
                'label' => $g['label'],
                'total' => (clone $base)
                    ->whereBetween('lama_perjalanan', [$g['min'], $g['max']])
                    ->count(),
            ];
        }

        $avg = (clone $base)->avg('lama_perjalanan');
        $max = (clone $base)->max('lama_perjalanan');
        $min = (clone $base)->min('lama_perjalanan');

        return [
            'distribusi' => $result,
            'rata_rata' => $avg ? round((float) $avg, 1) : 0,
            'terlama' => $max ? (int) $max : 0,
            'tersingkat' => $min ? (int) $min : 0,
        ];
    }

    /**
     * Daily heatmap data (for calendar/heatmap chart)
     */
    private function getDailyHeatmap(int $year, ?int $month, ?int $instanceId): array
    {
        $query = SuratTugas::whereYear('created_at', $year);
        if ($month) $query->whereMonth('created_at', $month);
        if ($instanceId) $query->where('instance_id', $instanceId);

        return $query->select(
                DB::raw('DATE(created_at) as tanggal'),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('tanggal')
            ->get()
            ->map(fn($row) => [
                'date' => $row->tanggal,
                'count' => $row->total,
            ])
            ->toArray();
    }

    /**
     * Year-over-year comparison
     */
    private function getComparisonPrevYear(int $year, ?int $instanceId): array
    {
        $prevYear = $year - 1;

        $currentSt = $this->baseQuery($year, null, $instanceId)->count();
        $prevSt = $this->baseQuery($prevYear, null, $instanceId)->count();

        $currentSpd = $this->baseQuery($year, null, $instanceId)->where('has_spd', true)->count();
        $prevSpd = $this->baseQuery($prevYear, null, $instanceId)->where('has_spd', true)->count();

        $currentBiaya = (float) $this->baseQuery($year, null, $instanceId)->where('has_spd', true)->sum('biaya');
        $prevBiaya = (float) $this->baseQuery($prevYear, null, $instanceId)->where('has_spd', true)->sum('biaya');

        $growthSt = $prevSt > 0 ? round((($currentSt - $prevSt) / $prevSt) * 100, 1) : ($currentSt > 0 ? 100 : 0);
        $growthSpd = $prevSpd > 0 ? round((($currentSpd - $prevSpd) / $prevSpd) * 100, 1) : ($currentSpd > 0 ? 100 : 0);
        $growthBiaya = $prevBiaya > 0 ? round((($currentBiaya - $prevBiaya) / $prevBiaya) * 100, 1) : ($currentBiaya > 0 ? 100 : 0);

        return [
            'tahun_ini' => $year,
            'tahun_lalu' => $prevYear,
            'surat_tugas' => [
                'current' => $currentSt,
                'previous' => $prevSt,
                'growth_percent' => $growthSt,
            ],
            'spd' => [
                'current' => $currentSpd,
                'previous' => $prevSpd,
                'growth_percent' => $growthSpd,
            ],
            'biaya' => [
                'current' => $currentBiaya,
                'previous' => $prevBiaya,
                'growth_percent' => $growthBiaya,
            ],
        ];
    }

    /**
     * Perjalanan Dinas Aktif: ST yang sudah ditandatangani dan tanggal perjalanan sedang berlangsung
     */
    private function getActiveTrips(int $year, ?int $instanceId): array
    {
        $today = Carbon::today()->toDateString();

        return $this->applyScope(
            SuratTugas::whereYear('created_at', $year)
                ->where('status', 'ditandatangani')
                ->where('tanggal_berangkat', '<=', $today)
                ->where('tanggal_kembali', '>=', $today),
            $instanceId
        )
            ->with([
                'instance:id,name,alias',
                'kategori:id,nama',
                'pegawai:id,surat_tugas_id,nama_lengkap,nip,jabatan',
            ])
            ->select('id', 'nomor_surat', 'untuk', 'status', 'kategori_id', 'instance_id',
                'tujuan_kabupaten_nama', 'tujuan_provinsi_nama', 'lokasi_tujuan',
                'tanggal_berangkat', 'tanggal_kembali', 'lama_perjalanan',
                'penandatangan_nama', 'jenis_perjalanan', 'has_spd', 'created_at')
            ->orderBy('tanggal_kembali', 'asc')
            ->limit(20)
            ->get()
            ->toArray();
    }
}
