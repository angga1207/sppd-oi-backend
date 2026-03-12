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
        $uri = 'https://sicaramapis.oganilirkab.go.id/api/local/caram/realisasi/listInstance';
        $response = Http::get($uri);
        if ($response->successful()) {
            $instances = $response->json()['data'] ?? [];
            foreach ($instances as $instance) {
                if (Instance::where('code', $instance['code'])->exists()) {
                    continue;
                }
                Instance::create([
                    'id_eoffice' => $instance['id_eoffice'],
                    'name' => $instance['name'],
                    'alias' => $instance['alias'],
                    'code' => $instance['code'],
                    'logo' => $instance['logo'],
                    'status' => 'active',
                    'description' => $instance['description'] ?? null,
                    'address' => $instance['address'] ?? null,
                    'phone' => $instance['phone'] ?? null,
                    'fax' => $instance['fax'] ?? null,
                    'email' => $instance['email'] ?? null,
                    'website' => $instance['website'] ?? null,
                    'facebook' => $instance['facebook'] ?? null,
                    'instagram' => $instance['instagram'] ?? null,
                    'youtube' => $instance['youtube'] ?? null,
                ]);
            }
        }

        $uri = 'https://semesta.oganilirkab.go.id/api/referensi-skpd';
        $response = Http::post($uri);
        if ($response->successful()) {
            $instances = $response->json()['data'] ?? [];
            foreach ($instances as $instance) {
                $data = Instance::where('id_eoffice', $instance['id'])->first();
                if ($data) {
                    $data->update([
                        'description' => $instance['code'],
                        'phone' => $instance['telepon_skpd'],
                        'fax' => $instance['fax'],
                        'kode_pos' => $instance['kode_pos'],
                        'email' => $instance['email_skpd'],
                        'website' => $instance['website'],
                    ]);
                }
            }
        }
    }
}
