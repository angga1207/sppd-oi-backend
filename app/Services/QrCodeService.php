<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QrCodeService
{
    /**
     * Generate QR Code for Surat Tugas (ST)
     *
     * @param string $templateType 'bupati' | 'sekda' | 'perangkat_daerah'
     * @param string $suratTugasId
     * @return string|null Absolute path to QR code image
     */
    public function generateQrCodeST(string $templateType, string $suratTugasId): ?string
    {
        try {
            $frontendUrl = rtrim(config('app.frontend_url'), '/');
            $url = $frontendUrl . '/scan/st/' . $suratTugasId;

            $logoPath = $this->getLogoPath($templateType);

            $qrCode = QrCode::format('png')
                ->size(300)
                ->margin(2)
                ->errorCorrection('H')
                ->color(0, 0, 0)
                ->backgroundColor(255, 255, 255)
                ->merge($logoPath, 0.3, true)
                ->generate($url);

            $fileName = 'QR_ST_' . $suratTugasId . '.png';
            $filePath = 'qrcodes/surat-tugas/' . $fileName;

            Storage::disk('local')->put($filePath, $qrCode);

            return storage_path('app/' . $filePath);
        } catch (\Exception $e) {
            Log::error('Failed to generate QR Code for ST', [
                'error' => $e->getMessage(),
                'surat_tugas_id' => $suratTugasId,
            ]);
            return null;
        }
    }

    /**
     * Generate QR Code for Surat Perjalanan Dinas (SPD)
     *
     * @param string $templateType 'bupati' | 'sekda' | 'perangkat_daerah'
     * @param string $spdId
     * @return string|null Absolute path to QR code image
     */
    public function generateQrCodeSPD(string $templateType, string $spdId): ?string
    {
        try {
            $frontendUrl = rtrim(config('app.frontend_url'), '/');
            $url = $frontendUrl . '/scan/spd/' . $spdId;

            $logoPath = $this->getLogoPath($templateType);

            $qrCode = QrCode::format('png')
                ->size(300)
                ->margin(2)
                ->errorCorrection('H')
                ->color(0, 0, 0)
                ->backgroundColor(255, 255, 255)
                ->merge($logoPath, 0.3, true)
                ->generate($url);

            $fileName = 'QR_SPD_' . $spdId . '.png';
            $filePath = 'qrcodes/spd/' . $fileName;

            Storage::disk('local')->put($filePath, $qrCode);

            return storage_path('app/' . $filePath);
        } catch (\Exception $e) {
            Log::error('Failed to generate QR Code for SPD', [
                'error' => $e->getMessage(),
                'spd_id' => $spdId,
            ]);
            return null;
        }
    }

    /**
     * Get the logo path based on template type (kop surat)
     */
    private function getLogoPath(string $templateType): string
    {
        if ($templateType === 'bupati') {
            return storage_path('app/templates/logo-pancasila.png');
        }

        return storage_path('app/templates/logo-oi.png');
    }
}
