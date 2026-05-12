<?php

namespace Database\Seeders;

use App\Models\Instance;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class InstanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $uri = 'https://semesta.oganilirkab.go.id/api/referensi-skpd';
        $response = Http::post($uri);

        if (!$response->successful()) {
            $this->command->error('Gagal mengambil data dari Semesta API: ' . $response->status());
            return;
        }

        $instances = $response->json()['data'] ?? [];

        foreach ($instances as $instance) {
            Instance::updateOrCreate(
                ['id_eoffice' => $instance['id']],
                [
                    'name'        => $instance['nama_skpd'],
                    'alias'       => $instance['nama_skpd_alias'] ?? null,
                    'code'        => $instance['code'] ?? null,
                    'logo'        => $instance['logo_skpd'] ?? null,
                    'status'      => 'active',
                    'address'     => $instance['alamat_skpd'] ?? null,
                    'phone'       => $instance['telepon_skpd'] ?? null,
                    'fax'         => $instance['fax'] ?? null,
                    'kode_pos'    => $instance['kode_pos'] ?? null,
                    'email'       => $instance['email_skpd'] ?? null,
                    'website'     => $instance['website'] ?? null,
                    'facebook'    => $instance['facebook_skpd'] ?? null,
                    'instagram'   => $instance['instagram_skpd'] ?? null,
                ]
            );
        }

        $this->command->info('Berhasil memuat ' . count($instances) . ' data instansi dari Semesta.');
    }
}
