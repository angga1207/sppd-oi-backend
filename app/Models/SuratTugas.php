<?php

namespace App\Models;

use App\Traits\HasNanoId;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SuratTugas extends Model
{
    use SoftDeletes, Searchable, HasNanoId;

    protected $table = 'surat_tugas';

    protected $fillable = [
        'nomor_surat',
        'klasifikasi_id',
        'kategori_id',
        'pemberi_perintah_nama',
        'pemberi_perintah_nip',
        'pemberi_perintah_jabatan',
        'pemberi_perintah_instance_id',
        'pemberi_perintah_pangkat',
        'pemberi_perintah_golongan',
        'dasar',
        'untuk',
        'has_spd',
        'penandatangan_nama',
        'penandatangan_nip',
        'penandatangan_jabatan',
        'penandatangan_instance_id',
        'ppk_nama',
        'ppk_nip',
        'ppk_jabatan',
        'ppk_pangkat',
        'ppk_golongan',
        'ppk_instance_id',
        'instance_id',
        'jenis_perjalanan',
        'tujuan_provinsi_id',
        'tujuan_provinsi_nama',
        'tujuan_kabupaten_id',
        'tujuan_kabupaten_nama',
        'tujuan_kecamatan_id',
        'tujuan_kecamatan_nama',
        'lokasi_tujuan',
        'tanggal_berangkat',
        'lama_perjalanan',
        'tanggal_kembali',
        'tempat_dikeluarkan',
        'tanggal_dikeluarkan',
        'alat_angkut',
        'biaya',
        'sub_kegiatan_kode',
        'sub_kegiatan_nama',
        'kode_rekening',
        'uraian_rekening',
        'keterangan',
        'status',
        'file_surat_tugas',
        'file_surat_tugas_signed',
        'signed_at',
        'created_by',
    ];

    protected $searchable = [
        'nomor_surat',
        'penandatangan_nama',
        'penandatangan_nip',
        'tujuan_provinsi_nama',
        'tujuan_kabupaten_nama',
        'tujuan_kecamatan_nama',
        'lokasi_tujuan',
    ];

    protected function casts(): array
    {
        return [
            'has_spd' => 'boolean',
            'tanggal_berangkat' => 'date',
            'tanggal_kembali' => 'date',
            'tanggal_dikeluarkan' => 'date',
            'signed_at' => 'datetime',
            'biaya' => 'decimal:2',
        ];
    }

    // Relationships
    public function klasifikasi(): BelongsTo
    {
        return $this->belongsTo(KlasifikasiNomorSurat::class, 'klasifikasi_id');
    }

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(KategoriSurat::class, 'kategori_id');
    }

    public function penandatanganInstance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'penandatangan_instance_id');
    }

    public function pemberiPerintahInstance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'pemberi_perintah_instance_id');
    }

    public function ppkInstance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'ppk_instance_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Override serialization: rename createdBy relation to created_by_user
     * to avoid conflict with the created_by integer field
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        // If the createdBy relation was loaded, move it to created_by_user
        if (array_key_exists('created_by', $array) && is_array($array['created_by'])) {
            $array['created_by_user'] = $array['created_by'];
            $array['created_by'] = $this->getAttributeValue('created_by');
        }

        return $array;
    }

    public function pegawai(): HasMany
    {
        return $this->hasMany(SuratTugasPegawai::class);
    }

    public function suratPerjalananDinas(): HasMany
    {
        return $this->hasMany(SuratPerjalananDinas::class);
    }

    public function logSurat(): HasMany
    {
        return $this->hasMany(LogSurat::class, 'surat_tugas_id');
    }

    // Helper: can edit only if draft
    public function canEdit(): bool
    {
        return $this->status === 'draft';
    }

    // Helper: can send for signature only if draft
    public function canSend(): bool
    {
        return $this->status === 'draft';
    }

}
