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

class DashboardController extends Controller
{
    /**
     * Determine scope: Bupati & super-admin see all OPD, others see own instance only.
     * Returns instance_id or null (null = all).
     */
    private function getScopeInstanceId(Request $request): ?int
    {
        $user = $request->user();
        if (!$user) return null;

        $user->loadMissing('role');
        $roleSlug = $user->role->slug ?? 'staff';
        $isBupati = $roleSlug === 'bupati' || $roleSlug === 'super-admin' || $user->username === '1000';

        if ($isBupati) {
            return null; // see everything
        }

        return $user->instance_id;
    }

    /**
     * Apply instance scope to a SuratTugas query builder.
     */
    private function applyScope($query, ?int $instanceId)
    {
        if ($instanceId) {
            $query->where('instance_id', $instanceId);
        }
        return $query;
    }

    /**
     * Dashboard: ringkasan statistik + chart data
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $year = (int) ($request->input('year') ?: Carbon::now()->year);
            $instanceId = $this->getScopeInstanceId($request);

            // ─── 1. Summary cards: Tahun Ini & Bulan Ini ───
            $summary = $this->getSummary($year, $instanceId);

            // ─── 2. Chart: Bulanan (Jan–Des) per Jenis Surat ───
            $chartJenisSurat = $this->getChartJenisSurat($year, $instanceId);

            // ─── 3. Chart: Dalam Negeri vs Luar Negeri (berdasarkan Klasifikasi) ───
            $chartDomestikInternasional = $this->getChartDomestikInternasional($year, $instanceId);

            // ─── 4. Chart: Per Perangkat Daerah (OPD) ───
            $chartPerangkatDaerah = $this->getChartPerangkatDaerah($year, $instanceId);

            // ─── 5. Top Pegawai paling sering ditugaskan ───
            $topPegawai = $this->getTopPegawai($year, $instanceId);

            // ─── 6. Provinsi & Kabupaten Tujuan ───
            $topProvinsi = $this->getTopProvinsi($year, $instanceId);
            $topKabupaten = $this->getTopKabupaten($year, $instanceId);

            // ─── 7. Berdasarkan Klasifikasi Surat ───
            $chartKlasifikasi = $this->getChartKlasifikasi($year, $instanceId);

            // ─── 8. Berdasarkan Alat Angkut ───
            $chartAlatAngkut = $this->getChartAlatAngkut($year, $instanceId);

            // ─── 9. OPD paling sering Perjalanan Dinas ───
            $topOpdPerjalanan = $this->getTopOpdPerjalanan($year, $instanceId);

            // ─── 10. OPD dengan Biaya Terbesar ───
            $topOpdBiaya = $this->getTopOpdBiaya($year, $instanceId);

            // ─── 11. Berdasarkan Kategori Surat ───
            $chartKategori = $this->getChartKategori($year, $instanceId);

            // ─── 12. SPD Berdasarkan Jenis Perjalanan Dinas ───
            $chartJenisPerjalanan = $this->getChartJenisPerjalanan($year, $instanceId);

            // ─── 13. Perjalanan Dinas Aktif (sedang berlangsung) ───
            $activeTrips = $this->getActiveTrips($year, $instanceId);

            return response()->json([
                'success' => true,
                'data' => [
                    'year' => $year,
                    'summary' => $summary,
                    'chart_jenis_surat' => $chartJenisSurat,
                    'chart_domestik_internasional' => $chartDomestikInternasional,
                    'chart_perangkat_daerah' => $chartPerangkatDaerah,
                    'top_pegawai' => $topPegawai,
                    'top_provinsi' => $topProvinsi,
                    'top_kabupaten' => $topKabupaten,
                    'chart_klasifikasi' => $chartKlasifikasi,
                    'chart_alat_angkut' => $chartAlatAngkut,
                    'top_opd_perjalanan' => $topOpdPerjalanan,
                    'top_opd_biaya' => $topOpdBiaya,
                    'chart_kategori' => $chartKategori,
                    'chart_jenis_perjalanan' => $chartJenisPerjalanan,
                    'active_trips' => $activeTrips,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Dashboard error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data dashboard.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ──────────────────────────────────────
    // Helper methods
    // ──────────────────────────────────────

    private function getSummary(int $year, ?int $instanceId): array
    {
        $now = Carbon::now();
        $currentMonth = $now->month;

        // Tahun ini
        $stYear = $this->applyScope(SuratTugas::whereYear('created_at', $year), $instanceId);
        $spdYear = SuratPerjalananDinas::whereHas('suratTugas', function ($q) use ($year, $instanceId) {
            $q->whereYear('created_at', $year);
            if ($instanceId) $q->where('instance_id', $instanceId);
        });

        // Bulan ini
        $stMonth = $this->applyScope(SuratTugas::whereYear('created_at', $year)->whereMonth('created_at', $currentMonth), $instanceId);
        $spdMonth = SuratPerjalananDinas::whereHas('suratTugas', function ($q) use ($year, $currentMonth, $instanceId) {
            $q->whereYear('created_at', $year)->whereMonth('created_at', $currentMonth);
            if ($instanceId) $q->where('instance_id', $instanceId);
        });

        // SPD aktif: status ditandatangani (belum selesai) dan tanggal kembali >= hari ini
        $spdAktif = SuratPerjalananDinas::whereHas('suratTugas', function ($q) use ($year, $instanceId) {
            $q->whereYear('created_at', $year)
              ->where('status', 'ditandatangani')
              ->where('tanggal_kembali', '>=', Carbon::today()->toDateString());
            if ($instanceId) $q->where('instance_id', $instanceId);
        })->count();

        return [
            'tahun_ini' => [
                'total_st' => (clone $stYear)->count(),
                'total_spd' => (clone $spdYear)->count(),
                'draft' => (clone $stYear)->where('status', 'draft')->count(),
                'dikirim' => (clone $stYear)->where('status', 'dikirim')->count(),
                'ditandatangani' => (clone $stYear)->where('status', 'ditandatangani')->count(),
                'ditolak' => (clone $stYear)->where('status', 'ditolak')->count(),
                'selesai' => (clone $stYear)->where('status', 'selesai')->count(),
            ],
            'bulan_ini' => [
                'total_st' => (clone $stMonth)->count(),
                'total_spd' => (clone $spdMonth)->count(),
                'draft' => (clone $stMonth)->where('status', 'draft')->count(),
                'dikirim' => (clone $stMonth)->where('status', 'dikirim')->count(),
                'ditandatangani' => (clone $stMonth)->where('status', 'ditandatangani')->count(),
                'ditolak' => (clone $stMonth)->where('status', 'ditolak')->count(),
                'selesai' => (clone $stMonth)->where('status', 'selesai')->count(),
            ],
            'spd_aktif' => $spdAktif,
        ];
    }

    /**
     * Bulanan: Jenis Surat (ST saja vs ST + SPD)
     */
    private function getChartJenisSurat(int $year, ?int $instanceId): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $stOnly = $this->applyScope(SuratTugas::whereYear('created_at', $year)
                ->whereMonth('created_at', $m)
                ->where('has_spd', false), $instanceId)
                ->count();

            $stWithSpd = $this->applyScope(SuratTugas::whereYear('created_at', $year)
                ->whereMonth('created_at', $m)
                ->where('has_spd', true), $instanceId)
                ->count();

            $months[] = [
                'bulan' => $this->namaBulan($m),
                'st_saja' => $stOnly,
                'st_spd' => $stWithSpd,
            ];
        }
        return $months;
    }

    /**
     * Dalam Negeri vs Luar Negeri per bulan
     * Logika: klasifikasi kode 000.1.2* = Dalam Negeri, 000.1.3* = Luar Negeri
     */
    private function getChartDomestikInternasional(int $year, ?int $instanceId): array
    {
        // Ambil ID klasifikasi DN & LN
        $dnIds = KlasifikasiNomorSurat::where('kode', 'like', '000.1.2%')->pluck('id')->toArray();
        $lnIds = KlasifikasiNomorSurat::where('kode', 'like', '000.1.3%')->pluck('id')->toArray();

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $base = $this->applyScope(
                SuratTugas::whereYear('created_at', $year)
                    ->whereMonth('created_at', $m)
                    ->where('has_spd', true),
                $instanceId
            );

            $dalamNegeri = (clone $base)->whereIn('klasifikasi_id', $dnIds)->count();
            $luarNegeri = (clone $base)->whereIn('klasifikasi_id', $lnIds)->count();

            $months[] = [
                'bulan' => $this->namaBulan($m),
                'dalam_negeri' => $dalamNegeri,
                'luar_negeri' => $luarNegeri,
            ];
        }
        return $months;
    }

    /**
     * Per Perangkat Daerah (instance) — top 10
     */
    private function getChartPerangkatDaerah(int $year, ?int $instanceId): array
    {
        $query = $this->applyScope(SuratTugas::whereYear('created_at', $year)
            ->whereNotNull('instance_id'), $instanceId);

        return $query->select('instance_id', DB::raw('COUNT(*) as total'))
            ->groupBy('instance_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $instance = Instance::find($row->instance_id);
                return [
                    'instance_id' => $row->instance_id,
                    'nama' => $instance ? ($instance->alias ?? $instance->name) : 'Tidak diketahui',
                    'total' => $row->total,
                ];
            })
            ->toArray();
    }

    /**
     * Top 10 Pegawai yang paling sering ditugaskan
     */
    private function getTopPegawai(int $year, ?int $instanceId): array
    {
        return SuratTugasPegawai::whereHas('suratTugas', function ($q) use ($year, $instanceId) {
            $q->whereYear('created_at', $year);
            if ($instanceId) $q->where('instance_id', $instanceId);
        })
            ->select('nip', 'nama_lengkap', 'jabatan', 'nama_skpd', DB::raw('COUNT(*) as total_tugas'))
            ->groupBy('nip', 'nama_lengkap', 'jabatan', 'nama_skpd')
            ->orderByDesc('total_tugas')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Top Provinsi tujuan
     */
    private function getTopProvinsi(int $year, ?int $instanceId): array
    {
        return $this->applyScope(SuratTugas::whereYear('created_at', $year)
            ->where('has_spd', true)
            ->whereNotNull('tujuan_provinsi_nama'), $instanceId)
            ->select('tujuan_provinsi_id', 'tujuan_provinsi_nama', DB::raw('COUNT(*) as total'))
            ->groupBy('tujuan_provinsi_id', 'tujuan_provinsi_nama')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Top Kabupaten tujuan
     */
    private function getTopKabupaten(int $year, ?int $instanceId): array
    {
        return $this->applyScope(SuratTugas::whereYear('created_at', $year)
            ->where('has_spd', true)
            ->whereNotNull('tujuan_kabupaten_nama'), $instanceId)
            ->select('tujuan_kabupaten_id', 'tujuan_kabupaten_nama', DB::raw('COUNT(*) as total'))
            ->groupBy('tujuan_kabupaten_id', 'tujuan_kabupaten_nama')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Berdasarkan Klasifikasi Surat
     */
    private function getChartKlasifikasi(int $year, ?int $instanceId): array
    {
        $query = SuratTugas::whereYear('surat_tugas.created_at', $year)
            ->whereNotNull('klasifikasi_id')
            ->join('klasifikasi', 'surat_tugas.klasifikasi_id', '=', 'klasifikasi.id');

        if ($instanceId) {
            $query->where('surat_tugas.instance_id', $instanceId);
        }

        return $query->select('klasifikasi.kode', 'klasifikasi.klasifikasi as nama', DB::raw('COUNT(*) as total'))
            ->groupBy('klasifikasi.kode', 'klasifikasi.klasifikasi')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Berdasarkan Alat Angkut
     */
    private function getChartAlatAngkut(int $year, ?int $instanceId): array
    {
        return $this->applyScope(SuratTugas::whereYear('created_at', $year)
            ->where('has_spd', true)
            ->whereNotNull('alat_angkut')
            ->where('alat_angkut', '!=', ''), $instanceId)
            ->select('alat_angkut', DB::raw('COUNT(*) as total'))
            ->groupBy('alat_angkut')
            ->orderByDesc('total')
            ->get()
            ->toArray();
    }

    /**
     * OPD paling sering melakukan Perjalanan Dinas (SPD count by instance)
     */
    private function getTopOpdPerjalanan(int $year, ?int $instanceId): array
    {
        return $this->applyScope(SuratTugas::whereYear('created_at', $year)
            ->where('has_spd', true)
            ->whereNotNull('instance_id'), $instanceId)
            ->select('instance_id', DB::raw('COUNT(*) as total'))
            ->groupBy('instance_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $instance = Instance::find($row->instance_id);
                return [
                    'instance_id' => $row->instance_id,
                    'nama' => $instance ? ($instance->alias ?? $instance->name) : 'Tidak diketahui',
                    'total' => $row->total,
                ];
            })
            ->toArray();
    }

    /**
     * OPD dengan Biaya Perjalanan Dinas Terbesar
     */
    private function getTopOpdBiaya(int $year, ?int $instanceId): array
    {
        return $this->applyScope(SuratTugas::whereYear('created_at', $year)
            ->where('has_spd', true)
            ->whereNotNull('instance_id')
            ->whereNotNull('biaya'), $instanceId)
            ->select('instance_id', DB::raw('SUM(biaya) as total_biaya'), DB::raw('COUNT(*) as total_spd'))
            ->groupBy('instance_id')
            ->orderByDesc('total_biaya')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $instance = Instance::find($row->instance_id);
                return [
                    'instance_id' => $row->instance_id,
                    'nama' => $instance ? ($instance->alias ?? $instance->name) : 'Tidak diketahui',
                    'total_biaya' => (float) $row->total_biaya,
                    'total_spd' => $row->total_spd,
                ];
            })
            ->toArray();
    }

    /**
     * Berdasarkan Kategori Surat (reference table)
     */
    private function getChartKategori(int $year, ?int $instanceId): array
    {
        $query = $this->applyScope(
            SuratTugas::whereYear('surat_tugas.created_at', $year)
                ->whereNotNull('kategori_id')
                ->join('kategori_surat', 'surat_tugas.kategori_id', '=', 'kategori_surat.id'),
            $instanceId
        );

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

    private function namaBulan(int $m): string
    {
        $bulan = [
            1 => 'Jan',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'Mei',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Agt',
            9 => 'Sep',
            10 => 'Okt',
            11 => 'Nov',
            12 => 'Des',
        ];
        return $bulan[$m] ?? '';
    }

    /**
     * SPD berdasarkan Jenis Perjalanan Dinas (PD Biasa vs PD Dalam Kota)
     */
    private function getChartJenisPerjalanan(int $year, ?int $instanceId): array
    {
        $pdBiasa = $this->applyScope(
            SuratTugas::whereYear('created_at', $year)
                ->where('has_spd', true)
                ->where('jenis_perjalanan', 'luar_kabupaten'),
            $instanceId
        )->count();

        $pdDalamKota = $this->applyScope(
            SuratTugas::whereYear('created_at', $year)
                ->where('has_spd', true)
                ->where('jenis_perjalanan', 'dalam_kabupaten'),
            $instanceId
        )->count();

        $spdBiasa = SuratPerjalananDinas::whereHas('suratTugas', function ($q) use ($year, $instanceId) {
            $q->whereYear('created_at', $year)->where('jenis_perjalanan', 'luar_kabupaten');
            if ($instanceId) $q->where('instance_id', $instanceId);
        })->count();

        $spdDalamKota = SuratPerjalananDinas::whereHas('suratTugas', function ($q) use ($year, $instanceId) {
            $q->whereYear('created_at', $year)->where('jenis_perjalanan', 'dalam_kabupaten');
            if ($instanceId) $q->where('instance_id', $instanceId);
        })->count();

        return [
            ['jenis' => 'PD Biasa (Luar Kabupaten)', 'total_st' => $pdBiasa, 'total_spd' => $spdBiasa],
            ['jenis' => 'PD Dalam Kota', 'total_st' => $pdDalamKota, 'total_spd' => $spdDalamKota],
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
