<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SiCaramController extends Controller
{
    /**
     * Proxy endpoint to fetch rekening perjadin from SiCaram API.
     * Returns list of sub kegiatan with their kode rekening.
     *
     * GET /sicaram/rekening-perjadin?year=2026&month=3&instance_id=123
     */
    public function getRekeningPerjadin(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
            'instance_id' => 'required|integer',
        ]);

        $year = $request->input('year');
        $month = $request->input('month');
        $instanceId = $request->input('instance_id');

        $cacheKey = "sicaram_rekening_{$year}_{$month}_{$instanceId}";

        try {
            $data = Cache::remember($cacheKey, 300, function () use ($year, $month, $instanceId) {
                $uri = 'https://sicaramapis.oganilirkab.go.id/api/local/sppd/getRekeningPerjadin';

                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'PostmanRuntime/7.44.1',
                ])->timeout(60)->get($uri, [
                    'year' => $year,
                    'month' => $month,
                    'instance_id' => $instanceId,
                ]);

                if ($response->successful()) {
                    return $response->json();
                }

                Log::warning('SiCaram API returned non-success status', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            });

            if ($data === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengambil data dari SiCaram.',
                ], 502);
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('SiCaram getRekeningPerjadin error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghubungi server SiCaram.',
            ], 502);
        }
    }
}
