<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KlasifikasiNomorSurat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class KlasifikasiController extends Controller
{
    /**
     * Search klasifikasi nomor surat
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $data = KlasifikasiNomorSurat::orderBy('id', 'asc')
                ->with('children')
                ->whereIn('id', ['822558', '822562'])
                ->get();

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Klasifikasi index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data klasifikasi.',
            ], 500);
        }
    }

    /**
     * Get specific klasifikasi with children
     */
    public function show(int $id): JsonResponse
    {
        try {
            $klasifikasi = KlasifikasiNomorSurat::with('children')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $klasifikasi,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Klasifikasi tidak ditemukan.',
            ], 404);
        }
    }
}
