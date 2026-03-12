<?php

use App\Http\Controllers\ScanVerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
    return redirect('https://sppd.oganilirkab.go.id');
});

// QR Code scan verification (public routes)
Route::get('/scan/st/{id}', [ScanVerificationController::class, 'verifySuratTugas'])->name('scan.st');
Route::get('/scan/spd/{id}', [ScanVerificationController::class, 'verifySpd'])->name('scan.spd');
