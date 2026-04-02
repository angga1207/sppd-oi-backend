<?php

namespace App\Http\Controllers;

use App\Models\SuratTugas;
use App\Models\SuratPerjalananDinas;
use Illuminate\Http\JsonResponse;

class ScanVerificationController extends Controller
{
    /**
     * Verify Surat Tugas by ID (QR code scan)
     */
    public function verifySuratTugas(string $id): JsonResponse
    {
        $suratTugas = SuratTugas::with(['instance', 'pegawai'])->find($id);

        if (!$suratTugas) {
            return response()->json([
                'valid' => false,
                'message' => 'Surat Tugas tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'valid' => true,
            'message' => 'Surat Tugas terverifikasi.',
            'data' => [
                'nomor_surat' => $suratTugas->nomor_surat,
                'status' => $suratTugas->status,
                'tanggal_dikeluarkan' => $suratTugas->tanggal_dikeluarkan?->format('Y-m-d'),
                'pemberi_perintah' => $suratTugas->pemberi_perintah_nama,
                'penandatangan' => $suratTugas->penandatangan_nama,
                'instansi' => $suratTugas->instance->name ?? '-',
                'untuk' => $suratTugas->untuk,
                'tanggal_berangkat' => $suratTugas->tanggal_berangkat?->format('Y-m-d'),
                'tanggal_kembali' => $suratTugas->tanggal_kembali?->format('Y-m-d'),
                'pegawai' => $suratTugas->pegawai->map(fn ($p) => [
                    'nama' => $p->nama_lengkap,
                    'nip' => $p->nip,
                    'jabatan' => $p->jabatan,
                ]),
            ],
        ]);
    }

    /**
     * Verify Surat Perjalanan Dinas by ID (QR code scan)
     */
    public function verifySpd(string $id): JsonResponse
    {
        $spd = SuratPerjalananDinas::with(['suratTugas.instance', 'suratTugasPegawai'])->find($id);

        if (!$spd) {
            return response()->json([
                'valid' => false,
                'message' => 'Surat Perjalanan Dinas tidak ditemukan.',
            ], 404);
        }

        $st = $spd->suratTugas;

        return response()->json([
            'valid' => true,
            'message' => 'Surat Perjalanan Dinas terverifikasi.',
            'data' => [
                'nomor_spd' => $spd->nomor_spd,
                'nomor_surat_tugas' => $st->nomor_surat ?? '-',
                'status' => $spd->status,
                'tingkat_biaya' => $spd->tingkat_biaya_label,
                'instansi' => $st->instance->name ?? '-',
                'penandatangan' => $st->penandatangan_nama,
                'pegawai' => [
                    'nama' => $spd->suratTugasPegawai->nama_lengkap ?? '-',
                    'nip' => $spd->suratTugasPegawai->nip ?? '-',
                    'jabatan' => $spd->suratTugasPegawai->jabatan ?? '-',
                ],
                'tujuan' => $st->lokasi_tujuan ?: ($st->tujuan_kecamatan_nama ?: ($st->tujuan_kabupaten_nama ?: ($st->tujuan_provinsi_nama ?? '-'))),
                'tanggal_berangkat' => $st->tanggal_berangkat?->format('Y-m-d'),
                'tanggal_kembali' => $st->tanggal_kembali?->format('Y-m-d'),
                'lama_perjalanan' => $st->lama_perjalanan ? ($st->lama_perjalanan . ' hari') : '-',
            ],
        ]);
    }
}
