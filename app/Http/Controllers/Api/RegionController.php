<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Province;
use App\Models\Kabupaten;
use App\Models\Kecamatan;
use App\Models\Kelurahan;
use Illuminate\Http\JsonResponse;

class RegionController extends Controller
{
    /**
     * Get all provinces
     */
    public function provinces(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Province::orderBy('nama')->get(),
        ]);
    }

    /**
     * Get kabupaten/kota by province ID
     */
    public function kabupaten(string $provinsiId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Kabupaten::where('id_provinsi', $provinsiId)
                ->orderBy('nama')
                ->get(),
        ]);
    }

    /**
     * Get kecamatan by kabupaten ID
     */
    public function kecamatan(string $kabupatenId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Kecamatan::where('id_kabupaten', $kabupatenId)
                ->orderBy('nama')
                ->get(),
        ]);
    }

    /**
     * Get kelurahan by kecamatan ID
     */
    public function kelurahan(string $kecamatanId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Kelurahan::where('id_kecamatan', $kecamatanId)
                ->orderBy('nama')
                ->get(),
        ]);
    }
}
