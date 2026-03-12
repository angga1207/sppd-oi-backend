<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ESignService
{
    private string $serverUrl;
    private string $serverUser;
    private string $serverPass;

    public function __construct()
    {
        $this->serverUrl = config('services.esign.url', 'http://103.162.35.72');
        $this->serverUser = config('services.esign.user', 'esign');
        $this->serverPass = config('services.esign.pass', 'qwerty');
    }

    /**
     * Sign a PDF file using the TTE eSign API.
     *
     * @param string $pdfPath Absolute path to the PDF file to sign
     * @param string $nik NIK of the signer
     * @param string $passphrase Passphrase for the signer's digital certificate
     * @return array{success: bool, message: string, signed_pdf_content?: string}
     */
    public function signPdf(string $pdfPath, string $nik, string $passphrase): array
    {
        if (!file_exists($pdfPath)) {
            return [
                'success' => false,
                'message' => 'File PDF tidak ditemukan: ' . basename($pdfPath),
            ];
        }

        try {
            $response = Http::withBasicAuth($this->serverUser, $this->serverPass)
                ->connectTimeout(10)
                ->timeout(30)
                ->attach('file', file_get_contents($pdfPath), basename($pdfPath))
                ->post($this->serverUrl . '/api/sign/pdf', [
                    'nik' => $nik,
                    'passphrase' => $passphrase,
                    'halaman' => 'pertama',
                    'image' => 'false',
                    'linkQR' => config('services.esign.link_qr', 'https://sppd.oganilirkab.go.id/'),
                    'tampilan' => 'invisible',
                ]);

            if ($response->failed()) {
                $body = $response->body();
                $decoded = json_decode($body, true);

                if ($decoded && isset($decoded['error'])) {
                    return [
                        'success' => false,
                        'message' => 'Gagal tanda tangan digital: ' . $decoded['error'],
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'Passphrase atau NIK yang Anda masukkan salah.',
                ];
            }

            $body = $response->body();

            // If the response is JSON (error), parse it
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['error'])) {
                return [
                    'success' => false,
                    'message' => 'Gagal tanda tangan digital: ' . $decoded['error'],
                ];
            }

            // Verify it's a PDF
            if (!str_starts_with($body, '%PDF')) {
                return [
                    'success' => false,
                    'message' => 'Respons dari server TTE tidak valid (bukan file PDF).',
                ];
            }

            return [
                'success' => true,
                'message' => 'Berhasil',
                'signed_pdf_content' => $body,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('ESign connection error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Tidak dapat terhubung ke server Tanda Tangan Elektronik. Silakan coba lagi.',
            ];
        } catch (\Exception $e) {
            Log::error('ESign error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return [
                'success' => false,
                'message' => 'Terjadi kesalahan saat proses tanda tangan digital.',
            ];
        }
    }
}
