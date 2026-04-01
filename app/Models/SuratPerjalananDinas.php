<?php

namespace App\Models;

use App\Traits\HasNanoId;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SuratPerjalananDinas extends Model
{
    use SoftDeletes, Searchable, HasNanoId;

    protected $table = 'surat_perjalanan_dinas';

    protected $fillable = [
        'nomor_spd',
        'tingkat_biaya',
        'surat_tugas_id',
        'surat_tugas_pegawai_id',
        'status',
        'file_spd',
        'file_spd_signed',
        'signed_at',
    ];

    /**
     * Tingkat biaya options for SPD
     */
    public const TINGKAT_OPTIONS = [
        ['value' => 'A', 'label' => 'Tingkat A - Untuk Bupati, Wakil Bupati, dan Pimpinan DPRD'],
        ['value' => 'B', 'label' => 'Tingkat B - Untuk Pejabat Esselon II dan Anggota DPRD'],
        ['value' => 'C', 'label' => 'Tingkat C - Untuk Pejabat Esselon IIb'],
        ['value' => 'D', 'label' => 'Tingkat D - Untuk Pejabat Esselon III'],
        ['value' => 'E', 'label' => 'Tingkat E - Untuk Pejabat Esselon IV atau Golongan IV'],
        ['value' => 'F', 'label' => 'Tingkat F - Untuk Pejabat Golongan III dan II'],
        ['value' => 'G', 'label' => 'Tingkat G - Untuk Pejabat Golongan I'],
    ];

    /**
     * Detect tingkat biaya based on employee eselon & golongan
     *
     * @param array $employeeData Array with keys: eselon, golongan, kepala_skpd
     * @return string Tingkat biaya (A-G)
     */
    public static function detectCostLevel(array $employeeData): string
    {
        $eselon = strtoupper($employeeData['eselon'] ?? '');
        $golongan = strtoupper($employeeData['golongan'] ?? '');
        $kepalaSkpd = $employeeData['kepala_skpd'] ?? null;

        // Khusus untuk Kepala OPD/Badan (Eselon II)
        if ($kepalaSkpd === 'Y' || $kepalaSkpd === 'y') {
            return 'B'; // Tingkat B - Untuk Pejabat Eselon II
        }

        // Eselon IIb (check before general II)
        if (stripos($eselon, 'IIB') !== false || $eselon === 'II.B') {
            return 'C'; // Tingkat C - Untuk Pejabat Eselon IIb
        }

        // Eselon II (general)
        if (stripos($eselon, 'II') !== false) {
            return 'B'; // Tingkat B - Untuk Pejabat Eselon II
        }

        // Eselon III
        if (stripos($eselon, 'III') !== false) {
            return 'D'; // Tingkat D - Untuk Pejabat Eselon III
        }

        // Eselon IV atau Golongan IV
        if (stripos($eselon, 'IV') !== false || stripos($golongan, 'IV') !== false) {
            return 'E'; // Tingkat E - Untuk Pejabat Eselon IV atau Golongan IV
        }

        // Golongan III atau II
        if (stripos($golongan, 'III') !== false || stripos($golongan, 'II') !== false) {
            return 'F'; // Tingkat F - Untuk Pejabat Golongan III dan II
        }

        // Golongan I
        if (stripos($golongan, 'I') !== false) {
            return 'G'; // Tingkat G - Untuk Pejabat Golongan I
        }

        // Default ke tingkat F jika tidak terdeteksi
        return 'F';
    }

    /**
     * Get the tingkat biaya label for the current SPD
     */
    public function getTingkatBiayaLabelAttribute(): string
    {
        if (!$this->tingkat_biaya) {
            return '-';
        }

        foreach (self::TINGKAT_OPTIONS as $option) {
            if ($option['value'] === $this->tingkat_biaya) {
                return $option['label'];
            }
        }

        return $this->tingkat_biaya;
    }

    protected $searchable = [
        'nomor_spd',
        'suratTugasPegawai.nama_lengkap',
        'suratTugasPegawai.nip',
    ];

    protected $appends = ['tingkat_biaya_label'];

    public function suratTugas(): BelongsTo
    {
        return $this->belongsTo(SuratTugas::class);
    }

    public function suratTugasPegawai(): BelongsTo
    {
        return $this->belongsTo(SuratTugasPegawai::class);
    }

    public function laporanPerjalananDinas(): HasOne
    {
        return $this->hasOne(LaporanPerjalananDinas::class, 'spd_id');
    }

    public function pengikut(): HasMany
    {
        return $this->hasMany(SpdPengikut::class, 'spd_id');
    }
}
