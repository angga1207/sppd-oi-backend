<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RegionController;
use App\Http\Controllers\Api\SuratTugasController;
use App\Http\Controllers\Api\SpdController;
use App\Http\Controllers\Api\PegawaiController;
use App\Http\Controllers\Api\KlasifikasiController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\KategoriSuratController;
use App\Http\Controllers\Api\SiCaramController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\GlobalSearchController;
use App\Http\Controllers\Api\MobileController;
use App\Http\Controllers\Api\PpkController;

/*
|--------------------------------------------------------------------------
| API Routes - e-SPD Ogan Ilir
|--------------------------------------------------------------------------
*/

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/login-status', [AuthController::class, 'loginStatus']);

// Region data (public, cached)
Route::prefix('region')->group(function () {
    Route::get('/provinces', [RegionController::class, 'provinces']);
    Route::get('/kabupaten/{provinsiId}', [RegionController::class, 'kabupaten']);
    Route::get('/kecamatan/{kabupatenId}', [RegionController::class, 'kecamatan']);
    Route::get('/kelurahan/{kecamatanId}', [RegionController::class, 'kelurahan']);
});

// Protected routes (Bearer Token required)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Login Attempts Management (Super Admin)
    Route::prefix('login-attempts')->group(function () {
        Route::get('/', [AuthController::class, 'blockedUsers']);
        Route::post('/{id}/unblock', [AuthController::class, 'unblockUser']);
        Route::post('/unblock-all', [AuthController::class, 'unblockAll']);
    });

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Global Search
    Route::get('/search', [GlobalSearchController::class, 'search']);
    Route::get('/search/full', [GlobalSearchController::class, 'fullSearch']);

    // Surat Tugas
    Route::prefix('surat-tugas')->group(function () {
        Route::get('/', [SuratTugasController::class, 'index']);
        Route::get('/stats', [SuratTugasController::class, 'stats']);
        Route::post('/', [SuratTugasController::class, 'store']);
        Route::get('/{id}', [SuratTugasController::class, 'show']);
        Route::put('/{id}', [SuratTugasController::class, 'update']);
        Route::delete('/{id}', [SuratTugasController::class, 'destroy']);
        Route::post('/{id}/kirim', [SuratTugasController::class, 'kirim']);
        Route::post('/{id}/tandatangani', [SuratTugasController::class, 'tandatangani']);
        Route::post('/{id}/tolak', [SuratTugasController::class, 'tolak']);
        Route::post('/{id}/revisi', [SuratTugasController::class, 'revisi']);
        Route::post('/{id}/selesai', [SuratTugasController::class, 'selesai']);
        Route::get('/{id}/download', [SuratTugasController::class, 'download']);
        Route::get('/{id}/regenerate', [SuratTugasController::class, 'regenerateDocument']);
        Route::get('/{id}/log', [SuratTugasController::class, 'logSurat']);
    });

    // Surat Perjalanan Dinas
    Route::prefix('spd')->group(function () {
        Route::get('/tingkat-options', [SpdController::class, 'tingkatOptions']);
        Route::get('/saya', [SpdController::class, 'spdSaya']);
        Route::get('/', [SpdController::class, 'index']);
        Route::get('/{id}', [SpdController::class, 'show']);
        Route::put('/{id}', [SpdController::class, 'update']);
        Route::post('/{id}/laporan', [SpdController::class, 'submitLaporan']);
        Route::put('/{id}/pengikut', [SpdController::class, 'syncPengikut']);
        Route::get('/{id}/download', [SpdController::class, 'download']);
    });

    // Pegawai (from Semesta API)
    Route::get('/pegawai', [PegawaiController::class, 'index']);
    Route::get('/pegawai/{id}', [PegawaiController::class, 'show']);
    Route::get('/employees/bupati', [PegawaiController::class, 'bupati']);
    Route::get('/instances', [PegawaiController::class, 'instances']);

    // Employee Management (Super Admin only)
    Route::prefix('employees')->group(function () {
        Route::get('/', [EmployeeController::class, 'index']);
        Route::get('/stats', [EmployeeController::class, 'stats']);
        Route::get('/sync-logs', [EmployeeController::class, 'syncLogs']);
        Route::post('/sync', [EmployeeController::class, 'triggerSync']);
    });

    // Klasifikasi Nomor Surat
    Route::prefix('klasifikasi')->group(function () {
        Route::get('/', [KlasifikasiController::class, 'index']);
        Route::get('/{id}', [KlasifikasiController::class, 'show']);
    });

    // Kategori Surat (reference table)
    Route::get('/kategori-surat', [KategoriSuratController::class, 'index']);

    // SiCaram (proxy to SiCaram API)
    Route::get('/sicaram/rekening-perjadin', [SiCaramController::class, 'getRekeningPerjadin']);

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
        Route::post('/fcm-token', [NotificationController::class, 'saveFcmToken']);
    });

    // Activity Log
    Route::prefix('activity-log')->group(function () {
        Route::get('/', [ActivityLogController::class, 'index']);
        Route::get('/action-types', [ActivityLogController::class, 'actionTypes']);
        Route::get('/auto-delete-status', [ActivityLogController::class, 'autoDeleteStatus']);
    });

    // Reports / Laporan
    Route::prefix('reports')->group(function () {
        Route::get('/', [ReportController::class, 'index']);
        Route::get('/detail-table', [ReportController::class, 'detailTable']);
        Route::get('/instances', [ReportController::class, 'instances']);
    });

    // Pejabat Pembuat Komitmen (PPK)
    Route::prefix('ppk')->group(function () {
        Route::get('/', [PpkController::class, 'index']);
        Route::get('/instance/{instanceId}', [PpkController::class, 'getByInstance']);
        Route::post('/', [PpkController::class, 'store']);
        Route::put('/{id}', [PpkController::class, 'update']);
        Route::delete('/{id}', [PpkController::class, 'destroy']);
        Route::post('/{id}/set-active', [PpkController::class, 'setActive']);
    });

});

// Mobile API routes for Semesta Android app (no Bearer token — uses NIP param)
Route::prefix('mobile')->group(function () {
    // GET endpoints — nip via query parameter (?nip=xxx)
    Route::get('/surat-tugas', [MobileController::class, 'listSuratTugas']);
    Route::get('/surat-tugas/{id}', [MobileController::class, 'detailSuratTugas']);
    Route::get('/spd-saya', [MobileController::class, 'listSpdSaya']);
    Route::get('/spd/{id}', [MobileController::class, 'detailSpd']);

    // POST endpoints — nip via request body
    Route::post('/surat-tugas/{id}/tandatangani', [MobileController::class, 'tandatangani']);
    Route::post('/surat-tugas/{id}/tolak', [MobileController::class, 'tolak']);
});
