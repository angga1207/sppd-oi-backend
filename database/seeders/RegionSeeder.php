<?php

namespace Database\Seeders;

use App\Models\Province;
use App\Models\Kabupaten;
use App\Models\Kecamatan;
use App\Models\Kelurahan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class RegionSeeder extends Seeder
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.indonesia.url', 'https://ibnux.github.io/data-indonesia');
    }

    public function run(): void
    {
        $this->command->info('📍 Mengambil data wilayah Indonesia dari API ibnux...');
        $this->command->newLine();

        // 1. Fetch Provinces
        $this->command->info('🏛️  Mengambil data Provinsi...');
        $provinces = $this->fetchJson('/provinsi.json');

        if (empty($provinces)) {
            $this->command->error('Gagal mengambil data provinsi!');
            return;
        }

        DB::transaction(function () use ($provinces) {
            foreach ($provinces as $prov) {
                Province::updateOrCreate(
                    ['id' => $prov['id']],
                    ['nama' => $prov['nama']]
                );
            }
        });

        $this->command->info("   ✅ {$this->count(Province::class)} provinsi tersimpan.");
        $this->command->newLine();

        // 2. Fetch Kabupaten/Kota per Province
        $this->command->info('🏙️  Mengambil data Kabupaten/Kota...');
        $bar = $this->command->getOutput()->createProgressBar(count($provinces));
        $bar->start();

        foreach ($provinces as $prov) {
            $kabupatens = $this->fetchJson("/kabupaten/{$prov['id']}.json");

            if (!empty($kabupatens)) {
                DB::transaction(function () use ($kabupatens, $prov) {
                    foreach ($kabupatens as $kab) {
                        Kabupaten::updateOrCreate(
                            ['id' => $kab['id']],
                            [
                                'id_provinsi' => $prov['id'],
                                'nama' => $kab['nama'],
                            ]
                        );
                    }
                });
            }

            $bar->advance();
            usleep(100000); // 100ms delay to avoid rate limit
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("   ✅ {$this->count(Kabupaten::class)} kabupaten/kota tersimpan.");
        $this->command->newLine();

        // 3. Fetch Kecamatan khusus Kabupaten Ogan Ilir saja
        $oganIlir = Kabupaten::where('nama', 'LIKE', '%OGAN ILIR%')->first();

        if ($oganIlir) {
            $this->command->info("🏘️  Mengambil data Kecamatan Kabupaten Ogan Ilir ({$oganIlir->id})...");

            $kecamatans = $this->fetchJson("/kecamatan/{$oganIlir->id}.json");

            if (!empty($kecamatans)) {
                DB::transaction(function () use ($kecamatans, $oganIlir) {
                    foreach ($kecamatans as $kec) {
                        Kecamatan::updateOrCreate(
                            ['id' => $kec['id']],
                            [
                                'id_kabupaten' => $oganIlir->id,
                                'nama' => $kec['nama'],
                            ]
                        );
                    }
                });
            }

            $this->command->info("   ✅ {$this->count(Kecamatan::class)} kecamatan (Ogan Ilir) tersimpan.");
            $this->command->newLine();
        } else {
            $this->command->warn('⚠️  Kabupaten Ogan Ilir tidak ditemukan, kecamatan dilewati.');
            $this->command->newLine();
        }

        $this->command->info('🎉 Selesai! Semua data wilayah berhasil disimpan ke database.');
    }

    private function fetchJson(string $path): array
    {
        try {
            $response = Http::timeout(30)->get($this->baseUrl . $path);

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (\Exception $e) {
            // Silently skip on error
        }

        return [];
    }

    private function count(string $model): int
    {
        return $model::count();
    }
}
