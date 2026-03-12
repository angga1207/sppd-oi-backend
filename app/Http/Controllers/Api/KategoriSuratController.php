<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KategoriSurat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KategoriSuratController extends Controller
{
    /**
     * List semua kategori surat yang aktif.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $kategori = KategoriSurat::active()->get();

            return response()->json([
                'success' => true,
                'data' => $kategori,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data kategori surat.',
            ], 500);
        }
    }
}
