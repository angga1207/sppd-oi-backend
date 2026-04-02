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

            $pngData = $this->generateQrPng($url, $logoPath);
            if (!$pngData) {
                return null;
            }

            $fileName = 'QR_ST_' . $suratTugasId . '.png';
            $filePath = 'qrcodes/surat-tugas/' . $fileName;

            Storage::disk('local')->put($filePath, $pngData);

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

            $pngData = $this->generateQrPng($url, $logoPath);
            if (!$pngData) {
                return null;
            }

            $fileName = 'QR_SPD_' . $spdId . '.png';
            $filePath = 'qrcodes/spd/' . $fileName;

            Storage::disk('local')->put($filePath, $pngData);

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
     * Generate QR code as PNG data.
     * Uses SVG format + GD conversion to avoid imagick dependency.
     */
    private function generateQrPng(string $url, string $logoPath, int $size = 300): ?string
    {
        // Try imagick-based PNG first (if extension available)
        if (extension_loaded('imagick')) {
            $qrCode = QrCode::format('png')
                ->size($size)
                ->margin(2)
                ->errorCorrection('H')
                ->color(0, 0, 0)
                ->backgroundColor(255, 255, 255)
                ->merge($logoPath, 0.3, true)
                ->generate($url);

            return $qrCode;
        }

        // Fallback: generate SVG and convert to PNG using GD
        $svgData = QrCode::format('svg')
            ->size($size)
            ->margin(2)
            ->errorCorrection('H')
            ->color(0, 0, 0)
            ->backgroundColor(255, 255, 255)
            ->generate($url);

        // Convert SVG to PNG using GD
        $pngImage = $this->svgToPng($svgData, $size);
        if (!$pngImage) {
            Log::warning('SVG to PNG conversion failed, using SVG QR without logo');
            return null;
        }

        // Overlay logo in center
        if (file_exists($logoPath)) {
            $this->overlayLogo($pngImage, $logoPath, $size);
        }

        ob_start();
        imagepng($pngImage);
        $pngData = ob_get_clean();
        imagedestroy($pngImage);

        return $pngData;
    }

    /**
     * Convert SVG string to GD image resource
     */
    private function svgToPng(string $svgData, int $size): ?\GdImage
    {
        // Write SVG to temp file
        $tmpSvg = tempnam(sys_get_temp_dir(), 'qr_svg_');
        file_put_contents($tmpSvg, $svgData);

        // Try using external tools to convert SVG to PNG
        $tmpPng = tempnam(sys_get_temp_dir(), 'qr_png_') . '.png';
        $converted = false;

        // Method 1: ImageMagick CLI (magick/convert)
        if (!$converted) {
            $magick = trim(shell_exec('which magick 2>/dev/null') ?? '');
            if (!$magick) {
                $magick = trim(shell_exec('which convert 2>/dev/null') ?? '');
            }
            if ($magick && file_exists($magick)) {
                shell_exec(sprintf(
                    '%s -background white -density 300 %s -resize %dx%d %s 2>/dev/null',
                    escapeshellarg($magick),
                    escapeshellarg($tmpSvg),
                    $size,
                    $size,
                    escapeshellarg($tmpPng)
                ));
                $converted = file_exists($tmpPng) && filesize($tmpPng) > 0;
            }
        }

        // Method 2: rsvg-convert (common on Linux)
        if (!$converted) {
            $rsvg = trim(shell_exec('which rsvg-convert 2>/dev/null') ?? '');
            if ($rsvg && file_exists($rsvg)) {
                shell_exec(sprintf(
                    '%s -w %d -h %d -f png -o %s %s 2>/dev/null',
                    escapeshellarg($rsvg),
                    $size,
                    $size,
                    escapeshellarg($tmpPng),
                    escapeshellarg($tmpSvg)
                ));
                $converted = file_exists($tmpPng) && filesize($tmpPng) > 0;
            }
        }

        // Method 3: Inkscape
        if (!$converted) {
            $inkscape = trim(shell_exec('which inkscape 2>/dev/null') ?? '');
            if ($inkscape && file_exists($inkscape)) {
                shell_exec(sprintf(
                    '%s %s --export-type=png --export-filename=%s -w %d -h %d 2>/dev/null',
                    escapeshellarg($inkscape),
                    escapeshellarg($tmpSvg),
                    escapeshellarg($tmpPng),
                    $size,
                    $size
                ));
                $converted = file_exists($tmpPng) && filesize($tmpPng) > 0;
            }
        }

        // Method 4: LibreOffice
        if (!$converted) {
            $soffice = $this->findSoffice();
            if ($soffice) {
                $tmpDir = sys_get_temp_dir();
                shell_exec(sprintf(
                    '%s --headless --convert-to png --outdir %s %s 2>/dev/null',
                    escapeshellarg($soffice),
                    escapeshellarg($tmpDir),
                    escapeshellarg($tmpSvg)
                ));
                $possiblePng = preg_replace('/\.[^.]+$/', '.png', $tmpSvg);
                if (file_exists($possiblePng) && filesize($possiblePng) > 0) {
                    rename($possiblePng, $tmpPng);
                    $converted = true;
                }
            }
        }

        // Method 5: Pure GD fallback — parse SVG paths manually (basic QR rendering)
        if (!$converted) {
            $img = $this->renderQrFromSvg($svgData, $size);
            @unlink($tmpSvg);
            @unlink($tmpPng);
            return $img;
        }

        @unlink($tmpSvg);

        if ($converted && file_exists($tmpPng)) {
            $img = imagecreatefrompng($tmpPng);
            @unlink($tmpPng);
            return $img ?: null;
        }

        @unlink($tmpPng);
        return null;
    }

    /**
     * Pure GD fallback: render QR code from SVG rect/path data
     */
    private function renderQrFromSvg(string $svgData, int $targetSize): ?\GdImage
    {
        // Parse SVG to find viewBox and rect elements
        $xml = @simplexml_load_string($svgData);
        if (!$xml) {
            return null;
        }

        // Get viewBox dimensions
        $viewBox = (string) ($xml['viewBox'] ?? '');
        $parts = preg_split('/[\s,]+/', $viewBox);
        $svgWidth = isset($parts[2]) ? (float) $parts[2] : (float) ($xml['width'] ?? $targetSize);
        $svgHeight = isset($parts[3]) ? (float) $parts[3] : (float) ($xml['height'] ?? $targetSize);

        // Create image
        $img = imagecreatetruecolor($targetSize, $targetSize);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);

        $scaleX = $targetSize / $svgWidth;
        $scaleY = $targetSize / $svgHeight;

        // Parse rect elements (QR modules)
        $xml->registerXPathNamespace('svg', 'http://www.w3.org/2000/svg');

        // Look for rect elements in the default namespace
        foreach ($xml->xpath('//svg:rect') as $rect) {
            $x = (float) ($rect['x'] ?? 0);
            $y = (float) ($rect['y'] ?? 0);
            $w = (float) ($rect['width'] ?? 0);
            $h = (float) ($rect['height'] ?? 0);
            $fill = (string) ($rect['fill'] ?? '#000000');

            // Skip white/background rects
            if ($fill === '#ffffff' || $fill === 'white') {
                continue;
            }

            imagefilledrectangle(
                $img,
                (int) round($x * $scaleX),
                (int) round($y * $scaleY),
                (int) round(($x + $w) * $scaleX),
                (int) round(($y + $h) * $scaleY),
                $black
            );
        }

        // Also check for path elements with fill
        foreach ($xml->xpath('//svg:path') as $path) {
            // Simple path parsing for QR code — typically uses 'M x y h w v h ...' for modules
            $d = (string) ($path['d'] ?? '');
            $fill = (string) ($path['fill'] ?? '#000000');
            if ($fill === '#ffffff' || $fill === 'white' || empty($d)) {
                continue;
            }

            // Parse simple M/h/v/H/V commands
            preg_match_all('/([MmLlHhVvZz])([^MmLlHhVvZz]*)/', $d, $matches, PREG_SET_ORDER);
            $curX = 0;
            $curY = 0;
            $startX = 0;
            $startY = 0;
            $polyPoints = [];

            foreach ($matches as $m) {
                $cmd = $m[1];
                $vals = array_filter(preg_split('/[\s,]+/', trim($m[2])), fn($v) => $v !== '');
                $vals = array_values(array_map('floatval', $vals));

                switch ($cmd) {
                    case 'M':
                        if (count($vals) >= 2) {
                            $curX = $vals[0];
                            $curY = $vals[1];
                            $startX = $curX;
                            $startY = $curY;
                        }
                        break;
                    case 'm':
                        if (count($vals) >= 2) {
                            $curX += $vals[0];
                            $curY += $vals[1];
                            $startX = $curX;
                            $startY = $curY;
                        }
                        break;
                    case 'h':
                        foreach ($vals as $v) {
                            $x1 = $curX;
                            $curX += $v;
                            // Draw filled rect from (x1, curY) to (curX, curY+1)
                            imagefilledrectangle($img,
                                (int) round(min($x1, $curX) * $scaleX),
                                (int) round($curY * $scaleY),
                                (int) round(max($x1, $curX) * $scaleX),
                                (int) round(($curY + 1) * $scaleY),
                                $black
                            );
                        }
                        break;
                    case 'H':
                        foreach ($vals as $v) {
                            $x1 = $curX;
                            $curX = $v;
                            imagefilledrectangle($img,
                                (int) round(min($x1, $curX) * $scaleX),
                                (int) round($curY * $scaleY),
                                (int) round(max($x1, $curX) * $scaleX),
                                (int) round(($curY + 1) * $scaleY),
                                $black
                            );
                        }
                        break;
                    case 'v':
                        foreach ($vals as $v) {
                            $curY += $v;
                        }
                        break;
                    case 'V':
                        foreach ($vals as $v) {
                            $curY = $v;
                        }
                        break;
                    case 'Z':
                    case 'z':
                        $curX = $startX;
                        $curY = $startY;
                        break;
                }
            }
        }

        return $img;
    }

    /**
     * Overlay logo in center of QR image
     */
    private function overlayLogo(\GdImage &$qrImage, string $logoPath, int $qrSize): void
    {
        $logoInfo = @getimagesize($logoPath);
        if (!$logoInfo) {
            return;
        }

        $logoImg = match ($logoInfo[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($logoPath),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($logoPath),
            IMAGETYPE_GIF => @imagecreatefromgif($logoPath),
            default => null,
        };

        if (!$logoImg) {
            return;
        }

        // Logo should be ~30% of QR size
        $logoSize = (int) ($qrSize * 0.3);
        $logoX = (int) (($qrSize - $logoSize) / 2);
        $logoY = (int) (($qrSize - $logoSize) / 2);

        // Draw white background behind logo
        $white = imagecolorallocate($qrImage, 255, 255, 255);
        $padding = 4;
        imagefilledrectangle(
            $qrImage,
            $logoX - $padding,
            $logoY - $padding,
            $logoX + $logoSize + $padding,
            $logoY + $logoSize + $padding,
            $white
        );

        imagecopyresampled(
            $qrImage,
            $logoImg,
            $logoX,
            $logoY,
            0,
            0,
            $logoSize,
            $logoSize,
            imagesx($logoImg),
            imagesy($logoImg)
        );

        imagedestroy($logoImg);
    }

    /**
     * Find soffice binary
     */
    private function findSoffice(): ?string
    {
        $possiblePaths = [
            '/usr/local/bin/soffice',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
            '/usr/bin/soffice',
            '/usr/bin/libreoffice',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        $which = trim(shell_exec('which soffice 2>/dev/null') ?? '');
        return ($which && file_exists($which)) ? $which : null;
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
