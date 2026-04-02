<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuratTugas;
use App\Models\SuratPerjalananDinas;
use Illuminate\Http\JsonResponse;

class ScanController extends Controller
{
    /**
     * Verify and display Surat Tugas data from QR code scan
     */
    public function verifySuratTugas(string $id): JsonResponse
    {
        $st = SuratTugas::with([
            'instance',
            'pegawai',
            'pemberiPerintahInstance',
            'penandatanganInstance',
        ])->where('id', $id)->first();

        if (!$st) {
            return response()->json([
                'success' => false,
                'message' => 'Surat Tugas tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'type' => 'surat_tugas',
                'id' => $st->id,
                'nomor_surat' => $st->nomor_surat,
                'status' => $st->status,
                'pemberi_perintah_nama' => $st->pemberi_perintah_nama,
                'pemberi_perintah_jabatan' => $st->pemberi_perintah_jabatan,
                'penandatangan_nama' => $st->penandatangan_nama,
                'penandatangan_nip' => $st->penandatangan_nip,
                'penandatangan_jabatan' => $st->penandatangan_jabatan,
                'dasar' => $st->dasar,
                'untuk' => $st->untuk,
                'instance' => $st->instance?->name,
                'tanggal_dikeluarkan' => $st->tanggal_dikeluarkan?->format('Y-m-d'),
                'tempat_dikeluarkan' => $st->tempat_dikeluarkan,
                'pegawai' => $st->pegawai->map(fn($p) => [
                    'nama_lengkap' => $p->nama_lengkap,
                    'nip' => $p->nip,
                    'jabatan' => $p->jabatan,
                    'pangkat' => $p->pangkat,
                    'golongan' => $p->golongan,
                ]),
                'is_signed' => $st->status === 'ditandatangani' || $st->status === 'selesai',
                'created_at' => $st->created_at?->toISOString(),
            ],
        ]);
    }

    /**
     * Verify and display SPD data from QR code scan
     */
    public function verifySpd(string $id): JsonResponse
    {
        $spd = SuratPerjalananDinas::with([
            'suratTugas.instance',
            'suratTugas.penandatanganInstance',
            'suratTugasPegawai',
        ])->where('id', $id)->first();

        if (!$spd) {
            return response()->json([
                'success' => false,
                'message' => 'Surat Perjalanan Dinas tidak ditemukan.',
            ], 404);
        }

        $st = $spd->suratTugas;
        $pegawai = $spd->suratTugasPegawai;

        return response()->json([
            'success' => true,
            'data' => [
                'type' => 'spd',
                'id' => $spd->id,
                'nomor_spd' => $spd->nomor_spd,
                'nomor_surat_tugas' => $st->nomor_surat,
                'status' => $spd->status,
                'tingkat_biaya' => $spd->tingkat_biaya,
                'tingkat_biaya_label' => $spd->tingkat_biaya_label,
                'pegawai' => [
                    'nama_lengkap' => $pegawai->nama_lengkap ?? '',
                    'nip' => $pegawai->nip ?? '',
                    'jabatan' => $pegawai->jabatan ?? '',
                    'pangkat' => $pegawai->pangkat ?? '',
                    'golongan' => $pegawai->golongan ?? '',
                ],
                'penandatangan_nama' => $st->penandatangan_nama,
                'penandatangan_nip' => $st->penandatangan_nip,
                'penandatangan_jabatan' => $st->penandatangan_jabatan,
                'instance' => $st->instance?->name,
                'tujuan' => $st->lokasi_tujuan ?: ($st->tujuan_kecamatan_nama ?: ($st->tujuan_kabupaten_nama ?: $st->tujuan_provinsi_nama)),
                'tanggal_berangkat' => $st->tanggal_berangkat?->format('Y-m-d'),
                'tanggal_kembali' => $st->tanggal_kembali?->format('Y-m-d'),
                'lama_perjalanan' => $st->lama_perjalanan,
                'alat_angkut' => $st->alat_angkut,
                'untuk' => $st->untuk,
                'tanggal_dikeluarkan' => $st->tanggal_dikeluarkan?->format('Y-m-d'),
                'is_signed' => $spd->status === 'ditandatangani' || $spd->status === 'selesai',
                'created_at' => $spd->created_at?->toISOString(),
            ],
        ]);
    }
}
