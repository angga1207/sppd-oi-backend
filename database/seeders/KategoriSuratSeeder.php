<?php

namespace Database\Seeders;

use App\Models\KategoriSurat;
use Illuminate\Database\Seeder;

class KategoriSuratSeeder extends Seeder
{
    /**
     * Seed kategori surat reference data.
     */
    public function run(): void
    {
        $kategori = [
            ['nama' => 'Menghadiri Undangan Kegiatan/Rapat', 'urutan' => 1],
            ['nama' => 'Konsultasi/Koordinasi', 'urutan' => 2],
            ['nama' => 'Bimbingan Teknis', 'urutan' => 3],
            ['nama' => 'Diklat Struktural/Fungsional', 'urutan' => 4],
            ['nama' => 'Sosialisasi/Seminar/Workshop', 'urutan' => 5],
            ['nama' => 'Monitoring dan Evaluasi', 'urutan' => 6],
            ['nama' => 'Pembinaan/Pendampingan', 'urutan' => 7],
            ['nama' => 'Survey/Pengumpulan Data', 'urutan' => 8],
            ['nama' => 'Inspeksi/Peninjauan Lapangan', 'urutan' => 9],
            ['nama' => 'Studi Banding', 'urutan' => 10],
        ];

        foreach ($kategori as $item) {
            KategoriSurat::firstOrCreate(
                ['nama' => $item['nama']],
                [
                    'urutan' => $item['urutan'],
                    'is_active' => true,
                ]
            );
        }
    }
}
