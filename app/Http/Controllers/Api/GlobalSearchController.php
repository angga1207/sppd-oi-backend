<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuratTugas;
use App\Models\SuratPerjalananDinas;
use App\Models\Employee;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    /**
     * Determine if the user can search across all OPDs (bupati / super-admin).
     * Non-privileged users are limited to their own instance_id.
     *
     * @return int|null  null = unrestricted, int = OPD instance_id
     */
    private function resolveInstanceScope(Request $request): ?int
    {
        $user = $request->user();
        $user->loadMissing('role', 'employee');

        $roleSlug = $user->role->slug ?? 'staff';
        $isBupati = $roleSlug === 'bupati' || $user->username === '1000';

        if ($roleSlug === 'super-admin' || $isBupati) {
            return null; // unrestricted
        }

        return $user->instance_id;
    }

    /**
     * Quick search (for command palette dropdown) — limited results
     */
    public function search(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:2|max:100',
        ]);

        $term = $request->input('q');
        $limit = 5;
        $instanceId = $this->resolveInstanceScope($request);

        $suratTugas = $this->searchSuratTugas($term, $limit, $instanceId);
        $spd = $this->searchSpd($term, $limit, $instanceId);
        $pegawai = $this->searchPegawai($term, $limit, $instanceId);

        return response()->json([
            'success' => true,
            'data' => [
                'surat_tugas' => $suratTugas,
                'spd' => $spd,
                'pegawai' => $pegawai,
            ],
            'total' => $suratTugas->count() + $spd->count() + $pegawai->count(),
        ]);
    }

    /**
     * Full search (for dedicated search page) — paginated + AI summary
     */
    public function fullSearch(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:2|max:100',
            'type' => 'nullable|string|in:all,surat_tugas,spd,pegawai',
        ]);

        $term = $request->input('q');
        $type = $request->input('type', 'all');
        $limit = 20;
        $instanceId = $this->resolveInstanceScope($request);

        $data = [];
        $counts = [];

        if ($type === 'all' || $type === 'surat_tugas') {
            $st = $this->searchSuratTugas($term, $limit, $instanceId);
            $data['surat_tugas'] = $st;
            $counts['surat_tugas'] = $st->count();
        }

        if ($type === 'all' || $type === 'spd') {
            $spd = $this->searchSpd($term, $limit, $instanceId);
            $data['spd'] = $spd;
            $counts['spd'] = $spd->count();
        }

        if ($type === 'all' || $type === 'pegawai') {
            $peg = $this->searchPegawai($term, $limit, $instanceId);
            $data['pegawai'] = $peg;
            $counts['pegawai'] = $peg->count();
        }

        $total = array_sum($counts);

        // Generate AI summary
        $aiSummary = $this->generateAiSummary($term, $data, $counts, $total);

        return response()->json([
            'success' => true,
            'data' => $data,
            'counts' => $counts,
            'total' => $total,
            'ai_summary' => $aiSummary,
        ]);
    }

    // ─── Private helpers ──────────────────────────────────

    private function searchSuratTugas(string $term, int $limit, ?int $instanceId = null)
    {
        return SuratTugas::query()
            ->when($instanceId, fn ($q) => $q->where('instance_id', $instanceId))
            ->where(function ($q) use ($term) {
                $q->where('nomor_surat', 'ILIKE', "%{$term}%")
                  ->orWhere('untuk', 'ILIKE', "%{$term}%")
                  ->orWhere('dasar', 'ILIKE', "%{$term}%")
                  ->orWhere('tujuan_kabupaten_nama', 'ILIKE', "%{$term}%")
                  ->orWhere('lokasi_tujuan', 'ILIKE', "%{$term}%")
                  ->orWhere('penandatangan_nama', 'ILIKE', "%{$term}%")
                  ->orWhereHas('pegawai', function ($pq) use ($term) {
                      $pq->where('nama_lengkap', 'ILIKE', "%{$term}%")
                         ->orWhere('nip', 'ILIKE', "%{$term}%");
                  });
            })
            ->with(['kategori:id,nama', 'pegawai:id,surat_tugas_id,nama_lengkap,nip'])
            ->select('id', 'nomor_surat', 'untuk', 'dasar', 'status', 'kategori_id', 'tujuan_kabupaten_nama', 'lokasi_tujuan', 'tanggal_berangkat', 'tanggal_kembali', 'penandatangan_nama', 'created_at')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($st) => [
                'id' => $st->id,
                'type' => 'surat_tugas',
                'title' => $st->nomor_surat ?: 'Draft (belum bernomor)',
                'subtitle' => $st->untuk,
                'dasar' => $st->dasar,
                'status' => $st->status,
                'meta' => $st->tujuan_kabupaten_nama,
                'lokasi_tujuan' => $st->lokasi_tujuan,
                'date' => $st->tanggal_berangkat?->format('d M Y'),
                'date_end' => $st->tanggal_kembali?->format('d M Y'),
                'kategori' => $st->kategori?->nama,
                'penandatangan' => $st->penandatangan_nama,
                'pegawai' => $st->pegawai->pluck('nama_lengkap')->take(5)->toArray(),
                'created_at' => $st->created_at?->format('d M Y H:i'),
            ]);
    }

    private function searchSpd(string $term, int $limit, ?int $instanceId = null)
    {
        return SuratPerjalananDinas::query()
            ->when($instanceId, function ($q) use ($instanceId) {
                $q->whereHas('suratTugas', fn ($sq) => $sq->where('instance_id', $instanceId));
            })
            ->where(function ($q) use ($term) {
                $q->where('nomor_spd', 'ILIKE', "%{$term}%")
                  ->orWhereHas('suratTugasPegawai', function ($pq) use ($term) {
                      $pq->where('nama_lengkap', 'ILIKE', "%{$term}%")
                         ->orWhere('nip', 'ILIKE', "%{$term}%");
                  })
                  ->orWhereHas('suratTugas', function ($stq) use ($term) {
                      $stq->where('tujuan_kabupaten_nama', 'ILIKE', "%{$term}%")
                          ->orWhere('lokasi_tujuan', 'ILIKE', "%{$term}%")
                          ->orWhere('untuk', 'ILIKE', "%{$term}%");
                  });
            })
            ->with([
                'suratTugasPegawai:id,nama_lengkap,nip,jabatan',
                'suratTugas:id,nomor_surat,untuk,tujuan_kabupaten_nama,lokasi_tujuan,tanggal_berangkat,tanggal_kembali',
            ])
            ->select('id', 'nomor_spd', 'tingkat_biaya', 'surat_tugas_id', 'surat_tugas_pegawai_id', 'status')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'type' => 'spd',
                'title' => $s->nomor_spd ?: 'SPD (belum bernomor)',
                'subtitle' => $s->suratTugas?->untuk,
                'status' => $s->status,
                'meta' => $s->suratTugas?->tujuan_kabupaten_nama,
                'lokasi_tujuan' => $s->suratTugas?->lokasi_tujuan,
                'date' => $s->suratTugas?->tanggal_berangkat?->format('d M Y'),
                'date_end' => $s->suratTugas?->tanggal_kembali?->format('d M Y'),
                'pegawai_nama' => $s->suratTugasPegawai?->nama_lengkap,
                'pegawai_nip' => $s->suratTugasPegawai?->nip,
                'pegawai_jabatan' => $s->suratTugasPegawai?->jabatan,
                'surat_tugas_id' => $s->surat_tugas_id,
                'tingkat_biaya' => $s->tingkat_biaya,
            ]);
    }

    private function searchPegawai(string $term, int $limit, ?int $instanceId = null)
    {
        return Employee::query()
            ->when($instanceId, fn ($q) => $q->where('instance_id', $instanceId))
            ->where(function ($q) use ($term) {
                $q->where('nama_lengkap', 'ILIKE', "%{$term}%")
                  ->orWhere('nip', 'ILIKE', "%{$term}%")
                  ->orWhere('jabatan', 'ILIKE', "%{$term}%");
            })
            ->with('instance:id,name')
            ->select('id', 'nama_lengkap', 'nip', 'jabatan', 'instance_id', 'foto_pegawai', 'pangkat', 'golongan', 'eselon')
            ->limit($limit)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'type' => 'pegawai',
                'title' => $e->nama_lengkap,
                'subtitle' => $e->jabatan,
                'nip' => $e->nip,
                'instance' => $e->instance?->name,
                'foto' => $e->foto_pegawai,
                'pangkat' => $e->pangkat,
                'golongan' => $e->golongan,
                'eselon' => $e->eselon,
            ]);
    }

    /**
     * Generate a helpful AI summary based on search results
     */
    private function generateAiSummary(string $term, array $data, array $counts, int $total): string
    {
        if ($total === 0) {
            return "Tidak ditemukan hasil untuk \"{$term}\". Coba gunakan kata kunci yang berbeda, misalnya nomor surat, nama pegawai, NIP, atau nama daerah tujuan.";
        }

        $parts = [];

        // Surat Tugas insights
        if (!empty($counts['surat_tugas']) && $counts['surat_tugas'] > 0) {
            $stItems = collect($data['surat_tugas']);
            $statusGroups = $stItems->groupBy('status');
            $statusInfo = $statusGroups->map(fn ($items, $status) => count($items) . ' ' . $status)->values()->join(', ');
            $parts[] = "**{$counts['surat_tugas']} Surat Tugas** ditemukan ({$statusInfo})";

            // Destinations
            $destinations = $stItems->pluck('meta')->filter()->unique()->take(3)->values();
            if ($destinations->isNotEmpty()) {
                $parts[] = "Tujuan meliputi: " . $destinations->join(', ');
            }
        }

        // SPD insights
        if (!empty($counts['spd']) && $counts['spd'] > 0) {
            $spdItems = collect($data['spd']);
            $statusGroups = $spdItems->groupBy('status');
            $statusInfo = $statusGroups->map(fn ($items, $status) => count($items) . ' ' . $status)->values()->join(', ');
            $parts[] = "**{$counts['spd']} SPD** ditemukan ({$statusInfo})";

            $pegawaiNames = $spdItems->pluck('pegawai_nama')->filter()->unique()->take(3)->values();
            if ($pegawaiNames->isNotEmpty()) {
                $parts[] = "Pegawai terkait: " . $pegawaiNames->join(', ');
            }
        }

        // Pegawai insights
        if (!empty($counts['pegawai']) && $counts['pegawai'] > 0) {
            $pegItems = collect($data['pegawai']);
            $instances = $pegItems->pluck('instance')->filter()->unique()->take(3)->values();
            $parts[] = "**{$counts['pegawai']} Pegawai** cocok";
            if ($instances->isNotEmpty()) {
                $parts[] = "Dari instansi: " . $instances->join(', ');
            }
        }

        $summary = "Ditemukan **{$total} hasil** untuk \"{$term}\". " . implode('. ', $parts) . '.';

        return $summary;
    }
}
