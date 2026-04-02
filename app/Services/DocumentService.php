<?php

namespace App\Services;

use App\Models\SuratTugas;
use App\Models\SuratPerjalananDinas;
use App\Services\QrCodeService;
use App\Traits\ConvertHtmlListToText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\TemplateProcessor;
use Carbon\Carbon;

class DocumentService
{
    use ConvertHtmlListToText;

    private QrCodeService $qrCodeService;

    public function __construct(QrCodeService $qrCodeService)
    {
        $this->qrCodeService = $qrCodeService;
    }

    /**
     * Template type mapping based on pemberi perintah (Bupati/Sekda/Perangkat Daerah)
     */
    private const TEMPLATE_MAP = [
        'bupati' => [
            'st' => 'surat-tugas/st_kop_bupati.docx',
            'spd' => 'spd/spd_kop_bupati.docx',
        ],
        'sekda' => [
            'st' => 'surat-tugas/st_kop_sekda.docx',
            'spd' => 'spd/spd_kop_sekda.docx',
        ],
        'perangkat_daerah' => [
            'st' => 'surat-tugas/st_kop_perangkat_daerah.docx',
            'spd' => 'spd/spd_kop_perangkat_daerah.docx',
        ],
    ];

    /**
     * Generate all documents for a Surat Tugas (ST + SPDs if applicable)
     */
    public function generateAllDocuments(SuratTugas $suratTugas): array
    {
        $results = [
            'surat_tugas' => null,
            'spd' => [],
        ];

        // Load required relations
        $suratTugas->load([
            'klasifikasi',
            'instance',
            'pemberiPerintahInstance',
            'penandatanganInstance',
            'ppkInstance',
            'pegawai',
            'suratPerjalananDinas.suratTugasPegawai',
        ]);

        // Generate Surat Tugas document
        $stPath = $this->generateSuratTugas($suratTugas);
        if ($stPath) {
            $results['surat_tugas'] = $stPath;
            $suratTugas->update(['file_surat_tugas' => $stPath]);
        }

        // Generate SPD documents if applicable
        if ($suratTugas->has_spd) {
            foreach ($suratTugas->suratPerjalananDinas as $spd) {
                $spdPath = $this->generateSpd($suratTugas, $spd);
                if ($spdPath) {
                    $results['spd'][] = $spdPath;
                    $spd->update(['file_spd' => $spdPath]);
                }
            }
        }

        return $results;
    }

    /**
     * Generate Surat Tugas document (.docx + PDF)
     */
    public function generateSuratTugas(SuratTugas $suratTugas): ?string
    {
        try {
            $templateType = $this->determineTemplateType($suratTugas);
            $templatePath = storage_path('app/templates/' . self::TEMPLATE_MAP[$templateType]['st']);

            if (!file_exists($templatePath)) {
                Log::error("ST template not found: {$templatePath}");
                return null;
            }

            $tp = new TemplateProcessor($templatePath);

            // Fill instance header variables (perangkat_daerah template only)
            if ($templateType === 'perangkat_daerah') {
                $instance = $suratTugas->instance;
                $tp->setValue('INSTANSI', strtoupper($instance->name ?? ''));
                $tp->setValue('alamat', $instance->address ?? '');
                $tp->setValue('telp', $instance->phone ?? '');
                $tp->setValue('faximile', $instance->fax ?? '');
                $tp->setValue('kode_pos', $instance->kode_pos ?? '');
                $tp->setValue('email_pos', $instance->email ?? '');
                $tp->setValue('website', $instance->website ?? '');
            }

            // Fill main variables
            $tp->setValue('nomor_surat', $suratTugas->nomor_surat ?? '-');
            $tp->setValue('dasar', $this->ConvertHtmlListToText($suratTugas->dasar ?? ''));
            $tp->setValue('untuk', $this->ConvertHtmlListToText($suratTugas->untuk ?? ''));
            $tp->setValue('tanggal_surat', $this->formatTanggal($suratTugas->tanggal_dikeluarkan));

            // Fill penandatangan
            $tp->setValue('jabatan_penandatangan', $suratTugas->penandatangan_jabatan ?? '');
            $tp->setValue('nama_penandatangan', $suratTugas->penandatangan_nama ?? '');
            $tp->setValue('nip_penandatangan', $suratTugas->penandatangan_nip ?? '');

            // QR Code
            $qrPath = $this->qrCodeService->generateQrCodeST($templateType, $suratTugas->id);
            if ($qrPath && file_exists($qrPath)) {
                $tp->setImageValue('qr_code', [
                    'path' => $qrPath,
                    'width' => 70,
                    'height' => 70,
                    'ratio' => true,
                ]);
            } else {
                $tp->setValue('qr_code', '');
            }
            $tp->setValue('nomor_registrasi', $suratTugas->nomor_surat ?? '');

            // Clone rows for pegawai table
            $pegawaiList = $suratTugas->pegawai;
            $rowCount = $pegawaiList->count();

            if ($rowCount > 0) {
                $tp->cloneRow('no', $rowCount);

                foreach ($pegawaiList as $i => $pegawai) {
                    $idx = $i + 1;
                    $tp->setValue("no#{$idx}", $idx);
                    $tp->setValue("sppd_nama#{$idx}", $pegawai->nama_lengkap ?? '');
                    $tp->setValue("sppd_nip#{$idx}", $pegawai->nip ?? '');
                    $tp->setValue("sppd_pangkat_golongan#{$idx}", trim(($pegawai->pangkat ?? '') . ' / ' . ($pegawai->golongan ?? ''), ' /'));
                    $tp->setValue("sppd_jabatan#{$idx}", $pegawai->jabatan ?? '');
                }
            } else {
                // Single empty row
                $tp->cloneRow('no', 1);
                $tp->setValue('no#1', '-');
                $tp->setValue('sppd_nama#1', '-');
                $tp->setValue('sppd_nip#1', '-');
                $tp->setValue('sppd_pangkat_golongan#1', '-');
                $tp->setValue('sppd_jabatan#1', '-');
            }

            // Save docx
            $filename = 'ST_' . str_replace(['/', ' '], ['_', '_'], $suratTugas->nomor_surat ?? $suratTugas->id) . '_' . time();
            $outputDir = storage_path('app/documents/surat-tugas');

            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $docxPath = "{$outputDir}/{$filename}.docx";
            $tp->saveAs($docxPath);

            // Convert to PDF
            $pdfPath = $this->convertToPdf($docxPath, $outputDir);

            // Return relative path for storage
            $relativePath = 'documents/surat-tugas/' . ($pdfPath ? "{$filename}.pdf" : "{$filename}.docx");

            return $relativePath;
        } catch (\Exception $e) {
            Log::error('Generate Surat Tugas error: ' . $e->getMessage(), [
                'surat_tugas_id' => $suratTugas->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Generate SPD document (.docx + PDF)
     */
    public function generateSpd(SuratTugas $suratTugas, SuratPerjalananDinas $spd): ?string
    {
        try {
            $templateType = $this->determineTemplateType($suratTugas);
            $templatePath = storage_path('app/templates/' . self::TEMPLATE_MAP[$templateType]['spd']);

            if (!file_exists($templatePath)) {
                Log::error("SPD template not found: {$templatePath}");
                return null;
            }

            $tp = new TemplateProcessor($templatePath);

            $pegawai = $spd->suratTugasPegawai;

            // Fill instance header variables (perangkat_daerah template only)
            if ($templateType === 'perangkat_daerah') {
                $instance = $suratTugas->instance;
                $tp->setValue('INSTANSI', strtoupper($instance->name ?? ''));
                $tp->setValue('alamat', $instance->address ?? '');
                $tp->setValue('telp', $instance->phone ?? '');
                $tp->setValue('faximile', $instance->fax ?? '');
                $tp->setValue('kode_pos', $instance->kode_pos ?? '');
                $tp->setValue('email_pos', $instance->email ?? '');
                $tp->setValue('website', $instance->website ?? '');
            }

            // Fill SPD main variables
            $tp->setValue('nomor_surat', $spd->nomor_spd ?? '-');

            // PPK: Use dedicated PPK fields, fallback to penandatangan for backward compatibility
            $ppkNama = $suratTugas->ppk_nama ?: $suratTugas->penandatangan_nama;
            $tp->setValue('pejabat_pembuat_komitmen', $ppkNama ?? '');

            // Pegawai data
            $tp->setValue('pegawai_name', $pegawai->nama_lengkap ?? '');
            $tp->setValue('pegawai_nip', $pegawai->nip ?? '');
            $tp->setValue('pegawai_pangkat', trim(($pegawai->pangkat ?? '') . ' / ' . ($pegawai->golongan ?? ''), ' /'));
            $tp->setValue('pegawai_jabatan', $pegawai->jabatan ?? '');

            // Travel details
            $tp->setValue('tingkat_biaya', $spd->tingkat_biaya_label ?? '-');
            $tp->setValue('maksud_perjalanan', $this->ConvertHtmlListToText($suratTugas->untuk ?? ''));
            $tp->setValue('alat_angkutan', $suratTugas->alat_angkut ?? '-');

            // Location
            $tempat_berangkat = $suratTugas->instance->name ?? 'Indralaya';
            $tempat_tujuan = $suratTugas->lokasi_tujuan ?: ($suratTugas->tujuan_kecamatan_nama ?: ($suratTugas->tujuan_kabupaten_nama ?: ($suratTugas->tujuan_provinsi_nama ?? '-')));
            $tp->setValue('tempat_berangkat', $tempat_berangkat);

            // $tp->setValue('tempat_tujuan', $tempat_tujuan);
            // tempat tujuan $tempat_tujuan + kecamatan (jika ada) + kabupaten + provinsi
            $tempatTujuanFull = $tempat_tujuan;
            if ($suratTugas->tujuan_kecamatan_nama) {
                $tempatTujuanFull .= ', ' . $suratTugas->tujuan_kecamatan_nama;
            }
            if ($suratTugas->tujuan_kabupaten_nama) {
                $tempatTujuanFull .= ', ' . $suratTugas->tujuan_kabupaten_nama;
            }
            if ($suratTugas->tujuan_provinsi_nama) {
                $tempatTujuanFull .= ', ' . $suratTugas->tujuan_provinsi_nama;
            }
            $tp->setValue('tempat_tujuan', $tempatTujuanFull);

            // Duration & dates
            $tp->setValue('lama_perjalanan', ($suratTugas->lama_perjalanan ?? 0) . ' hari');
            $tp->setValue('tanggal_berangkat', $this->formatTanggal($suratTugas->tanggal_berangkat));
            $tp->setValue('tanggal_pulang', $this->formatTanggal($suratTugas->tanggal_kembali));

            // Pengikut (followers) - empty by default since each SPD is per person
            $tp->cloneRow('p_no', 1);
            $tp->setValue('p_no#1', '-');
            $tp->setValue('pengikut_nama#1', '-');
            $tp->setValue('pengikut_tanggal_lahir#1', '-');
            $tp->setValue('pengikut_keterangan#1', '-');

            // Financial
            $tp->setValue('pembebanan_instansi', $suratTugas->instance->name ?? '');
            $tp->setValue('kode_rekening', $suratTugas->kode_rekening ?: '-');
            $tp->setValue('uraian_rekening', $suratTugas->uraian_rekening ?: '-');
            $tp->setValue('keterangan_lain', $suratTugas->keterangan ?? '-');

            // Penandatangan & date
            $tp->setValue('tanggal_surat', $this->formatTanggal($suratTugas->tanggal_dikeluarkan));
            $tp->setValue('jabatan_penandatangan', $suratTugas->penandatangan_jabatan ?? '');
            $tp->setValue('nama_penandatangan', $suratTugas->penandatangan_nama ?? '');
            $tp->setValue('nip_penandatangan', $suratTugas->penandatangan_nip ?? '');
            $tp->setValue('pangkat_penandatangan', '');

            // QR Code
            $qrPath = $this->qrCodeService->generateQrCodeSPD($templateType, $spd->id);
            if ($qrPath && file_exists($qrPath)) {
                $tp->setImageValue('qr_code', [
                    'path' => $qrPath,
                    'width' => 70,
                    'height' => 70,
                    'ratio' => true,
                ]);
            } else {
                $tp->setValue('qr_code', '');
            }
            $tp->setValue('nomor_registrasi', $spd->nomor_spd ?? '');

            // Save docx
            $nomorClean = str_replace(['/', ' '], ['_', '_'], $spd->nomor_spd ?? $spd->id);
            $filename = 'SPD_' . $nomorClean . '_' . ($pegawai->nip ?? '') . '_' . time();
            $outputDir = storage_path('app/documents/spd');

            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $docxPath = "{$outputDir}/{$filename}.docx";
            $tp->saveAs($docxPath);

            // Convert to PDF
            $pdfPath = $this->convertToPdf($docxPath, $outputDir);

            $relativePath = 'documents/spd/' . ($pdfPath ? "{$filename}.pdf" : "{$filename}.docx");

            return $relativePath;
        } catch (\Exception $e) {
            Log::error('Generate SPD error: ' . $e->getMessage(), [
                'spd_id' => $spd->id,
                'surat_tugas_id' => $suratTugas->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Determine which template to use based on PEMBERI PERINTAH (not penandatangan).
     *
     * KOP Surat ditentukan oleh Pejabat Pemberi Perintah:
     * - Bupati           → KOP Bupati
     * - Sekretaris Daerah → KOP Sekretariat Daerah
     * - Lainnya          → KOP Perangkat Daerah
     */
    private function determineTemplateType(SuratTugas $suratTugas): string
    {
        // 1. Check pemberi perintah jabatan first (covers Bupati without matching instance)
        $jabatan = strtolower($suratTugas->pemberi_perintah_jabatan ?? '');
        if (str_contains($jabatan, 'bupati') && !str_contains($jabatan, 'wakil')) {
            return 'bupati';
        }
        if (str_contains($jabatan, 'sekretaris daerah') || str_contains($jabatan, 'sekda')) {
            return 'sekda';
        }

        // 2. Check pemberi perintah instance_id (Sekretariat Daerah = 15)
        if ((int) $suratTugas->pemberi_perintah_instance_id === 15) {
            return 'sekda';
        }

        // 3. Fallback: check pemberi perintah instance name
        $pemberiPerintahInstance = $suratTugas->pemberiPerintahInstance;

        if ($pemberiPerintahInstance) {
            $name = strtolower($pemberiPerintahInstance->name ?? '');

            if (str_contains($name, 'bupati') && !str_contains($name, 'wakil')) {
                return 'bupati';
            }

            if (str_contains($name, 'sekretaris daerah') || str_contains($name, 'sekda') || str_contains($name, 'sekretariat daerah')) {
                return 'sekda';
            }
        }

        return 'perangkat_daerah';
    }

    /**
     * Convert .docx to PDF using LibreOffice headless
     */
    private function convertToPdf(string $docxPath, string $outputDir): ?string
    {
        if (!file_exists($docxPath)) {
            Log::error("DOCX file not found for PDF conversion: {$docxPath}");
            return null;
        }


        // Linux LibreOffice shell command to convert doc to pdf
        // $command = "libreoffice --headless --convert-to pdf {$path} --outdir {$savePath} 2>&1";

        // Find soffice binary
        $soffice = null;

        // PHP-FPM typically has a very limited PATH, so we expand it manually
        $fullPath = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

        // Try `command -v` with expanded PATH (more reliable than `which` under PHP-FPM)
        $which = trim(shell_exec("PATH={$fullPath}:\$PATH command -v soffice 2>/dev/null") ?? '');
        if ($which) {
            $soffice = $which;
        }

        if (!$soffice) {
            $which = trim(shell_exec("PATH={$fullPath}:\$PATH command -v libreoffice 2>/dev/null") ?? '');
            if ($which) {
                $soffice = $which;
            }
        }

        // Fallback: try common absolute paths directly via shell test -x (avoids open_basedir)
        if (!$soffice) {
            $possiblePaths = [
                '/usr/bin/libreoffice',
                '/usr/bin/soffice',
                '/usr/local/bin/soffice',
                '/usr/local/bin/libreoffice',
                '/Applications/LibreOffice.app/Contents/MacOS/soffice',
            ];

            foreach ($possiblePaths as $path) {
                // Use shell `test -x` to check existence+executable — bypasses open_basedir
                $check = shell_exec("test -x " . escapeshellarg($path) . " && echo 'ok' 2>/dev/null");
                if (trim($check ?? '') === 'ok') {
                    $soffice = $path;
                    break;
                }
            }
        }

        if (!$soffice) {
            Log::warning('LibreOffice not found. Keeping .docx format.');
            return null;
        }

        Log::info("LibreOffice found at: {$soffice}");

        // Use a unique user installation directory to avoid lock conflicts
        $userInstall = storage_path('app/libreoffice_profile_' . getmypid());
        if (!is_dir($userInstall)) {
            mkdir($userInstall, 0755, true);
        }

        $command = sprintf(
            '%s --headless --norestore -env:UserInstallation=file://%s --convert-to pdf --outdir %s %s 2>&1',
            escapeshellarg($soffice),
            $userInstall,
            escapeshellarg($outputDir),
            escapeshellarg($docxPath)
        );

        $output = shell_exec($command);
        Log::info("LibreOffice conversion output: {$output}");

        // Check if PDF was created
        $pdfPath = preg_replace('/\.docx$/i', '.pdf', $docxPath);
        if (file_exists($pdfPath)) {
            // Optionally remove the .docx file after successful conversion
            // unlink($docxPath);

            // Clean up temp profile
            $this->removeDirectory($userInstall);

            return $pdfPath;
        }

        // Clean up temp profile
        $this->removeDirectory($userInstall);

        Log::warning("PDF conversion may have failed. Output: {$output}");
        return null;
    }

    /**
     * Format date to Indonesian format
     */
    private function formatTanggal($date): string
    {
        if (!$date) {
            return '-';
        }

        $months = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->day . ' ' . $months[$carbon->month] . ' ' . $carbon->year;
    }

    /**
     * Recursively remove a directory
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }
}
