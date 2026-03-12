<?php

// Test Semesta API - check kepala_skpd field
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$apiKey = config('services.semesta.api_key');
$url = config('services.semesta.url') . '/daftar-pegawai';

// Test with a few SKPD IDs to find kepala_skpd data
$instances = \App\Models\Instance::whereNotNull('id_eoffice')->take(5)->get();

foreach ($instances as $inst) {
    echo "=== {$inst->name} (id_eoffice: {$inst->id_eoffice}) ===\n";
    $r = Http::timeout(30)->withHeaders([
        'Accept' => 'application/json',
        'User-Agent' => 'PostmanRuntime/7.44.1',
        'x-api-key' => $apiKey
    ])->post($url, ['id_skpd' => $inst->id_eoffice]);

    $data = $r->json()['data'] ?? [];
    echo "Total: " . count($data) . "\n";

    if (count($data) > 0) {
        echo "Keys: " . json_encode(array_keys($data[0])) . "\n";
    }

    foreach ($data as $p) {
        if (!empty($p['kepala_skpd'])) {
            echo "  KEPALA: " . json_encode([
                'nip' => $p['nip'],
                'nama' => $p['nama_lengkap'],
                'jabatan' => $p['jabatan'],
                'kepala_skpd' => $p['kepala_skpd'],
                'jenis_pegawai' => $p['jenis_pegawai'] ?? null,
            ], JSON_PRETTY_PRINT) . "\n";
        }
    }
    echo "\n";
}
